<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class for creating a FIDO Universal 2nd Factor provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_U2F extends Two_Factor_Provider {

	// use the generic helper traits
	use Two_Factor_Trails;

	/**
	 * The U2F library interface object.
	 *
	 * @var u2flib_server\U2F
	 */
	public static $u2f;

	/**
	 * The user meta registered key.
	 *
	 * @type string
	 */
	const REGISTERED_KEY_USER_META_KEY = 'two_factor-u2f_key';

	/**
	 * The user meta authenticate data.
	 *
	 * @type string
	 */
	const AUTH_DATA_USER_META_KEY = 'two_factor-u2f_request';

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		$this->priority = 20;

		if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
			throw new Exception('PHP has to be at least version 5.3.0, this is ' . PHP_VERSION );
		}

		$app_url_parts = parse_url( home_url() );
		$app_url = sprintf( '%s://%s', $app_url_parts['scheme'], $app_url_parts['host'] );

		require_once( TWO_FACTOR_DIR . 'vendor/autoload.php' );
		self::$u2f = new u2flib_server\U2F( $app_url );

		require_once( TWO_FACTOR_DIR . 'providers/class-two-factor-u2f-admin.php' );
		Two_Factor_U2F_Admin::add_hooks( __CLASS__ );

		add_action( 'admin_notices', 								array( $this, 'admin_notices' ) );
		add_action( 'login_enqueue_scripts',                		array( $this, 'login_enqueue_assets' ) );
		add_action( 'two_factor_user_option-' . 		__CLASS__, 	array( $this, 'print_user_options' ) );
		add_action( 'two_factor_user_option_details-' .	__CLASS__, 	array( $this, 'print_user_option_details' ) );

		add_filter( 'two_factor_fields-' . __CLASS__, array( $this, 'set_provider_info' ), 10, 2 );

		return parent::__construct();
	}

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 *
	 * @since 0.1-dev
	 */
	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	public function set_provider_info( $fields ) {
		$fields[ 'description' ] = _x( 'Use a hardware device compatible with the U2F protocol for 2-Step authentication during sign-in.', 'two-factor authentication hardware device', 'two-factor' );

		$user = wp_get_current_user();
		if ( self::is_available_for_user( $user ) ) {
			$fields[ 'manage' ] = self::make_option_link( 'Manage Keys', __CLASS__, 'manage' );
		} else {
			$fields[ 'manage' ] = self::make_option_link( 'Add Key', __CLASS__, 'setup' );
		}

		return $fields;
	}

	/**
	 * Displays an admin notice when disabled and no other two-factor methods are available.
	 *
	 * @since 0.2-dev
	 */
	public function admin_notices() {
		$user = wp_get_current_user();
		
		// Return if the provider is not enabled.
		if ( ! in_array( __CLASS__, Two_Factor::get_enabled_providers_for_user( $user->ID ) ) ) {
			return;
		}

		// Return if not available for user.
		if ( $this->is_available_for_user( $user ) ) {
			return;
		}

		$message = __( 'Testing from admin_notices, fido-u2f' );

		esc_html_e( sprintf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', $message ) );
	}

	/**
	 * Enqueue assets for login form.
	 *
	 * @since 0.1-dev
	 */
	public function login_enqueue_assets() {
		wp_enqueue_script( 'u2f-api', plugins_url( 'includes/u2f/u2f-api.js', __FILE__ ), null, null, true );

		// register the script
		wp_register_script( 'two_factor-u2f_login-js', plugins_url( 'js/u2f-login.js', __FILE__ ), array( 'jquery', 'u2f-api' ), null, true );
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Security Keys', 'abstract noun', 'two-factor' );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH . '/wp-admin/includes/template.php' );

		try {
			$keys = self::get_security_keys( $user->ID );
			$data = self::$u2f->getAuthenticateData( $keys );
			update_user_meta( $user->ID, self::AUTH_DATA_USER_META_KEY, $data );

			// add the localized data for the js
			$js_data = array(
				'request' => wp_json_encode( $data ),
				'text' => array(
					'insert' => esc_html__( 'Insert and/or tap your Security Key.', 'two_factor' ),
					'error' => esc_html__( 'Sign-in failure.', 'two-factor' ),
				),
			);
			wp_localize_script( 'two_factor-u2f_login-js', 'u2fL10n', $js_data );
			// enqueued script with localized data
			wp_enqueue_script( 'two_factor-u2f_login-js' );

		} catch ( Exception $e ) {
			?>
			<p><?php esc_html_e( 'An error occurred while creating authentication data.' ); ?></p>
			<?php
			return null;
		}
		?>
		<p><?php esc_html_e( 'Insert and/or tap your Security Key.' ); ?></p>
		<input type="hidden" name="u2f_response" id="u2f_response">
		<?php
	}

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function validate_authentication( $user ) {
		$requests = get_user_meta( $user->ID, self::AUTH_DATA_USER_META_KEY, true );

		$response = json_decode( stripslashes( $_REQUEST['u2f_response'] ) );

		$keys = self::get_security_keys( $user->ID );

		try {
			$reg = self::$u2f->doAuthenticate( $requests, $keys, $response );

			$reg->last_used = current_time( 'timestamp' );

			self::update_security_key( $user->ID, $reg );

			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Whether this two-factor provider is configured and available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		return self::is_browser_support() && (bool) self::get_security_keys( $user->ID ) && is_ssl();
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function print_user_options( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function print_user_option_details( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		$num_keys = count( self::get_security_keys( $user->ID ) );
		$message = '';

		if ( $num_keys ) {
			$message = esc_html__( 'You currently have' ) . __( sprintf( __( ' <strong>%u</strong> ' ), $num_keys ) ) . esc_html__( 'Security ' . _n( 'Key', 'Keys', $num_keys ) . ' registered.' );
		} else {
			$message = esc_html__( 'You have not registered any Security Keys.' );
		}

		if ( ! is_ssl() ) {
			$message .= __( ' <strong>' . esc_html_e( 'Using Security Keys requires an https connection.' ) . '</strong>' );
		}
		
		_e( sprintf( '<p>%1$s</p>', $message ) );
	}

	/**
	 * Check if a given variable is a security key
	 *
	 * @since 0.2-dev
	 *
	 * @param object $key The data of registered security key.
	 * @return bool True if valid security key object, false otherwise.
	 */
	public static function is_security_key( $key ) {
		return ! ( ! is_object( $key )
			|| ! property_exists( $key, 'keyHandle' ) || empty( $key->keyHandle )
			|| ! property_exists( $key, 'publicKey' ) || empty( $key->publicKey )
			|| ! property_exists( $key, 'certificate' ) || empty( $key->certificate )
			|| ! property_exists( $key, 'counter' ) || ( -1 > $key->counter ) );
	}

	/**
	 * Add registered security key to a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id  User ID.
	 * @param object $register The data of registered security key.
	 * @return int|bool Meta ID on success, false on failure.
	 */
	public static function add_security_key( $user_id, $register ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		if ( ! self::is_security_key( $register ) ) {
			return false;
		}

		$num_keys = count( self::get_security_keys( $user->ID ) );

		$register = array(
			'keyHandle'   => $register->keyHandle,
			'publicKey'   => $register->publicKey,
			'certificate' => $register->certificate,
			'counter'     => $register->counter,
		);

		$register['name']      = __( sprintf( 'Security Key %u', $num_keys + 1) );
		$register['added']     = current_time( 'timestamp' );
		$register['last_used'] = $register['added'];

		return add_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY, $register );
	}

	/**
	 * Retrieve registered security keys for a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return array|bool Array of keys on success, false on failure.
	 */
	public static function get_security_keys( $user_id ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		$keys = get_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY );
		if ( $keys ) {
			foreach ( $keys as &$key ) {
				$key = (object) $key;
			}
			unset( $key );
		}

		return $keys;
	}

	/**
	 * Update registered security key.
	 *
	 * Use the $prev_value parameter to differentiate between meta fields with the
	 * same key and user ID.
	 *
	 * If the meta field for the user does not exist, it will be added.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id  User ID.
	 * @param object $data The data of registered security key.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public static function update_security_key( $user_id, $data ) {
		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		if ( ! self::is_security_key( $data ) ) {
			return false;
		}

		$keys = self::get_security_keys( $user_id );
		if ( $keys ) {
			foreach ( $keys as $key ) {
				if ( $key->keyHandle === $data->keyHandle ) {
					return update_user_meta( $user_id, self::REGISTERED_KEY_USER_META_KEY, (array) $data, (array) $key );
				}
			}
		}

		return self::add_security_key( $user_id, $data );
	}

	/**
	 * Remove registered security key matching criteria from a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id   User ID.
	 * @param string $keyHandle Optional. Key handle.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_security_key( $user_id, $keyHandle = null ) {
		global $wpdb;

		if ( ! is_numeric( $user_id ) ) {
			return false;
		}

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$table = $wpdb->usermeta;

		$keyHandle = wp_unslash( $keyHandle );
		$keyHandle = maybe_serialize( $keyHandle );

		$query = $wpdb->prepare( "SELECT umeta_id FROM $table WHERE meta_key = '%s' AND user_id = %d", self::REGISTERED_KEY_USER_META_KEY, $user_id );

		if ( $keyHandle ) {
			$query .= $wpdb->prepare( ' AND meta_value LIKE %s', '%:"' . $keyHandle . '";s:%' );
		}

		$meta_ids = $wpdb->get_col( $query );
		if ( ! count( $meta_ids ) ) {
			return false;
		}

		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'user', $meta_id );
		}

		return true;
	}

	/**
	 * Detect browser support for FIDO U2F.
	 *
	 * @since 0.1-dev
	 */
	public static function is_browser_support() {
		global $is_chrome;

		require_once( ABSPATH . '/wp-admin/includes/dashboard.php' );
		$response = wp_check_browser_version();

		return $is_chrome && version_compare( $response['version'], '41' ) >= 0 && ! wp_is_mobile();
	}
}
