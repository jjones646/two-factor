<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class for creating a backup codes provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Backup_Codes extends Two_Factor_Provider {

	// use the generic helper traits
	use Two_Factor_Trails;

	/**
	 * The user meta backup codes key.
	 *
	 * @type string
	 */
	const BACKUP_CODES_META_KEY = 'two_factor-backup_codes';

	/**
	 * The number backup codes.
	 *
	 * @type int
	 */
	const NUMBER_OF_CODES = 10;

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		$this->priority = 80;

		add_action( 'admin_enqueue_scripts',       					array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_two_factor_backup_codes_generate', 	array( $this, 'ajax_generate_json' ) );

		add_action( 'admin_notices', 								array( $this, 'admin_notices' ) );
		add_action( 'two_factor_user_option-' . 		__CLASS__, 	array( $this, 'print_user_options' ) );
		add_action( 'two_factor_user_option_details-' .	__CLASS__, 	array( $this, 'print_user_option_details' ) );

		add_filter( 'two_factor_fields-' . __CLASS__, 				array( $this, 'set_provider_info' ), 10, 2 );

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
		$fields[ 'description' ] = _x( 'Receive single-use codes at your account\'s email address.', 'two-factor authentication method', 'two-factor' );

		$user = wp_get_current_user();
		if ( self::is_available_for_user( $user ) ) {
			$fields[ 'manage' ] = self::make_option_link( 'Delete Codes', __CLASS__, 'delete' );
		} else {
			$fields[ 'manage' ] = self::make_option_link( 'Generate Codes', __CLASS__, 'two-factor-generate' );
		}

		return $fields;
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 0.2-dev
	 *
	 * @param string $hook Current page.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php' ) ) ) {
			return;
		}
		// register the script
		wp_register_script( 'two_factor-backup_codes-js', plugins_url( 'js/backup-codes.js', __FILE__ ), array( 'jquery' ), null, true );

		// localize the script with our data
		$js_data = array(
			'action'  		=> 'two_factor_backup_codes_generate',
			'ajaxUrl' 		=> admin_url( 'admin-ajax.php' ),
			'nonce'   		=> wp_create_nonce( 'two_factor_codes_generate' )
		);
		wp_localize_script( 'two_factor-backup_codes-js', 'bckCodesData', $js_data );

		// enqueued script with localized data
		wp_enqueue_script( 'two_factor-backup_codes-js' );
	}

	/**
	 * Displays an admin notice when backup codes have run out.
	 *
	 * @since 0.1-dev
	 */
	public function admin_notices() {
		$user = wp_get_current_user();
		
		// Return if the provider is not enabled.
		if ( ! in_array( __CLASS__, Two_Factor::get_enabled_providers_for_user( $user->ID ) ) ) {
			return;
		}

		// Return if we are not out of codes.
		if ( $this->is_available_for_user( $user ) ) {
			return;
		}

		$message = sprintf( __( 'Two-factor: You are out of backup codes and need to <a href="%s">generate more.</a>' ), esc_url( get_edit_user_link( $user->ID ) . '#two_factor-backup_codes' ) );

		esc_html_e( sprintf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', $message ) );
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Backup Codes', 'provider label', 'two-factor' );
	}

	/**
	 * Returns a short description about the authentication method.
	 *
	 * @since 0.2-dev
	 */
	public function get_description() {
		return _x( 'Generate ' . self::NUMBER_OF_CODES . ' single-use codes that can be used in emergency situations when all other methods are unavailable.', 'Two-Factor Authentication Method Description', 'two-factor' );
	}

	/**
	 * Whether this Two Factor provider is configured and codes are available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Does this user have available codes?
		if ( self::codes_remaining_for_user( $user ) ) {
			return true;
		}
		return false;
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

		?>
		<p class="description"><?php _e( 'Store these codes in a secure location. You will <strong>not</strong> be able to view these codes again.' ); ?></p>
		<textarea rows="15" disabled></textarea>
		<p><a href="#" id="two_factor-backup_codes-download_link" class="hide-if-no-js" download="two-factor-backup-codes.txt"><?php esc_html_e( 'Download' ); ?></a><p>
		<?php
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function get_user_option_details( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		$count = self::codes_remaining_for_user( $user );
		if ( $count ) {
			self::delete_all_codes( $user );
			$message = sprintf( __( 'You have <strong>%u</strong> unused %s remaining.' ), $count, _n( 'code', 'codes', $count ) );
		} else {
			$message = sprintf( __( 'You have not generated any backup codes.' ) );
		}

		return sprintf( '<p>%1$s</p>', $message );
	}

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function print_user_option_details( $user ) {
		_e( self::get_user_option_details( $user ) );
	}

	/**
	 * Generates backup codes & updates the user meta.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param array   $args Optional arguments for assinging new codes.
	 */
	public function generate_codes( $user, $args = '' ) {
		$codes = array();
		$codes_hashed = array();

		// Check for arguments
		if ( isset( $args['number'] ) ) {
			$num_codes = (int) $args['number'];
		} else {
			$num_codes = self::NUMBER_OF_CODES;
		}

		// Append or replace (default)
		if ( isset( $args['method'] ) && 'append' === $args['method'] ) {
			$codes_hashed = (array) get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );
		}

		for ( $i = 0; $i < $num_codes; $i++ ) {
			$code = $this->get_code(20);
			$codes_hashed[] = wp_hash_password( $code );
			$codes[] = $code;
			unset( $code );
		}

		update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $codes_hashed );

		// Unhashed
		return $codes;
	}

	public function get_comment_block( $user ) {
		$header =  __( '# These are single-use codes for ' . get_home_url() . ".\r\n" );
		$header .= __( '#   Date: ' . current_time( 'mysql', true ) . "\r\n" );
		$header .= __( '#   User: ' . $user->user_login . "\r\n" );
		return $header;
	}

	/**
	 * Generates a JSON object of backup codes.
	 *
	 * @since 0.1-dev
	 */
	public function ajax_generate_json() {
		check_ajax_referer( 'two_factor_codes_generate', '_ajax_nonce' );
		$user = wp_get_current_user();

		// Setup the return data.
		$codes = $this->generate_codes( $user );
		$i18n = array(
			'title' => self::get_user_option_details( $user ),
			'header' => self::get_comment_block( $user )
		);

		// Send the response.
		wp_send_json_success( array( 'codes' => $codes, 'i18n' => $i18n ) );
		// don't continue now that we're done
		wp_die();
	}

	/**
	 * Returns the number of unused codes for the specified user
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return int $int  The number of unused codes remaining
	 */
	public static function codes_remaining_for_user( $user ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );
		if ( is_array( $backup_codes ) && ! empty( $backup_codes ) ) {
			return count( $backup_codes );
		}
		return 0;
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Enter a single-use backup code.' ); ?></p>
		<p>
		<label for="authcode"><?php esc_html_e( 'Backup Code:' ); ?></label>
		<input type="tel" name="two_factor-backup_code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<?php
		submit_button( __( 'Submit' ) );
	}

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return bool   True on success, false on failure.
	 */
	public function validate_authentication( $user ) {
		return $this->validate_code( $user, $_POST['two_factor-backup_code'] );
	}

	/**
	 * Validates a backup code.
	 *
	 * Backup Codes are single-use and are deleted upon a successful validation.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param int     $code The backup code.
	 * @return bool   True on success, false on failure.
	 */
	public function validate_code( $user, $code ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );

		if ( is_array( $backup_codes ) && ! empty( $backup_codes ) ) {
			foreach ( $backup_codes as $code_index => $code_hashed ) {
				if ( wp_check_password( $code, $code_hashed, $user->ID ) ) {
					return $this->delete_code( $user, $code_hashed );
				}
			}
		}
		return false;
	}

	/**
	 * Deletes a backup code.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string  $code_hashed The hashed the backup code.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function delete_code( $user, $code_hashed ) {
		$backup_codes = get_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, true );

		// Delete the current code from the list since it's been used.
		$backup_codes = array_flip( $backup_codes );
		unset( $backup_codes[ $code_hashed ] );
		$backup_codes = array_values( array_flip( $backup_codes ) );

		// Update the backup code master list.
		return update_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $backup_codes );
		// return delete_user_meta( $user->ID, self::BACKUP_CODES_META_KEY, $backup_codes[ $code_hashed ] );
	}

	/**
	 * Delete all backup codes for a user.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return bool True on success, false on failure.
	 */
	public function delete_all_codes( $user ) {
		return delete_user_meta( $user->ID, self::BACKUP_CODES_META_KEY );
	}
}
