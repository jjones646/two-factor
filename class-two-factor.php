<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// add_action( 'all', create_function( '', 'var_dump( current_filter() );' ) );

/**
 * Class for creating two factor authorization.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor {

	/**
	 * The global key holding all available providers.
	 *
	 * @type string
	 */
	const ENABLED_PROVIDERS_KEY = 'two_factor-providers';

	/**
	 * The user meta provider key.
	 *
	 * @type string
	 */
	const PROVIDER_USER_META_KEY = 'two_factor-provider';

	/**
	 * The user meta enabled providers key.
	 *
	 * @type string
	 */
	const ENABLED_PROVIDERS_USER_META_KEY = 'two_factor-enabled_providers';

	/**
	 * The user meta nonce key.
	 *
	 * @type string
	 */
	const USER_META_NONCE_KEY    = 'two_factor-nonce';

	/**
	 * Set up filters and actions.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks() {
		register_activation_hook( __FILE__, 	  array( __CLASS__, 'activation_hook' ) );
		register_deactivation_hook( __FILE__, 	  array( __CLASS__, 'deactivate_hook' ) );

		add_action( 'init',                       array( __CLASS__, 'get_providers' ) );

		add_action( 'wp_login',                   array( __CLASS__, 'wp_login' ), 10, 2 );
		add_action( 'login_form_validate_2fa',    array( __CLASS__, 'login_form_validate_2fa' ) );
		add_action( 'login_form_backup_2fa',      array( __CLASS__, 'backup_2fa' ) );

		add_action( 'admin_init', 				  array( __CLASS__, 'add_settings_general' ) );

		add_action( 'show_user_profile',          array( __CLASS__, 'user_two_factor_options' ) );
		add_action( 'edit_user_profile',          array( __CLASS__, 'user_two_factor_options' ) );

		add_action( 'personal_options_update',    array( __CLASS__, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update',   array( __CLASS__, 'user_two_factor_options_update' ) );

		add_filter( 'manage_users_columns',       array( __CLASS__, 'filter_manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'manage_users_custom_column' ), 10, 3 );
	}

	/**
	 * For each provider, include it and then instantiate it.
	 *
	 * @since 0.1-dev
	 *
	 * @param bool $nofilter When set, the method will not filter out any providers.
	 * @return array of data structures for each available provider
	 */
	public static function get_providers( $nofilter = false ) {
		/**
		 * To add new two-factor providers, use the 'two_factor_providers' filter instead of
		 * adding it here directly.
		 */
		$providers = array(
			'Two_Factor_Email'        => TWO_FACTOR_DIR . 'providers/class-two-factor-email.php',
			'Two_Factor_Totp'         => TWO_FACTOR_DIR . 'providers/class-two-factor-totp.php',
			'Two_Factor_FIDO_U2F'     => TWO_FACTOR_DIR . 'providers/class-two-factor-fido-u2f.php',
			'Two_Factor_Backup_Codes' => TWO_FACTOR_DIR . 'providers/class-two-factor-backup-codes.php'
		);

		/**
		 * Filter the supplied providers.
		 *
		 * This lets third-parties either remove providers (such as Email), or
		 * add their own providers (such as text message or Clef).
		 *
		 * @param array $providers A key-value array where the key is the class name, and
		 *                         the value is the path to the file containing the class.
		 */
		$providers = apply_filters( 'two_factor_providers', $providers );

		return self::build_providers( $providers, $nofilter );
	}

	/**
	 * Sets up and builds a data structure for each provider class.
	 *
	 * @since 0.2-dev
	 * 
	 * @param array $providers A key-value array where the key is the class name, and
	 *                         the value is the path to the file containing the class.
	 * @param bool $nofilter When set, the method will not filter out any providers.
	 * @return array of data structures for each available provider
	 */
	private static function &build_providers( &$providers, $nofilter = false ) {
		if ( ! is_array( $providers ) ) {
			return;
		}
		$providers_array = array();

		/**
		 * For each given provider,
		 */
		foreach ( $providers as $class => $path ) {
			// include the provider's class file
			include_once( $path );

			/**
			 * Confirm that it's been successfully included before instantiating.
			 */
			if ( class_exists( $class ) ) {
				try {
					// we store the object instance in a small data structure
					$data_obj = array();

					// get an instance of the provider class
					$inst = call_user_func( array( $class, 'get_instance' ) );
					$key = sanitize_key( $class );

					$data_obj[ 'name' ] = $class;
					$data_obj[ 'obj' ] = $inst;

					$pos = strpos($key, 'two_factor_');
					if ( $pos === 0 ) {
					    $key = substr_replace( $key, '', $pos, strlen( 'two_factor_' ) );
					}
					$data_obj[ 'key' ] = $key;
					
					// now add the new data structure to our array
					array_push( $providers_array, $data_obj );
				} catch ( Exception $e ) {}
			}
		}

		// Sort providers by their priorities, descending
		uasort( $providers_array, function($a, $b) { return ( $a['obj']->get_priority() > $b['obj']->get_priority() ); } );

		if ( $nofilter ) {
			// don't exclude any providers from the list that gets set and returned
			$providers = $providers_array;
		} else {
			// get a list of global providers, if it's not in this array, it should never show for a non-super-admin.
			$enabled_providers = self::get_enabled_providers();
			if ( ! $enabled_providers ) {
				self::add_option_key( $providers_array );
				$enabled_providers = self::get_enabled_providers();
			}
			
			// Reset the key value array we were given and return it's reference
			$providers = array_filter( $providers_array, function ( $p ) use ( $enabled_providers ) {
				return in_array( $p[ 'key' ], $enabled_providers );
			} );
		}
		return $providers;
	}

	/**
	 * Get all Two-Factor Auth providers that are enabled for the specified|current user.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return array
	 */
	public static function get_enabled_providers_for_user( $user = null ) {
		if ( empty( $user ) || ! is_a( $user, 'WP_User' ) ) {
			$user = wp_get_current_user();
		}

		// get a list of the user's enabled providers, the intersection of this and the above are the
		// enabled providers for the user
		$providers = get_user_meta( $user->ID, self::ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( empty( $providers ) ) {
			$providers = array();
		}

		return array_filter( self::get_providers(), function ( $p ) use ( $providers ) {
			return in_array( $p['name'], $providers );
		} );
	}

	/**
	 * Get all Two-Factor Auth providers that are both enabled and configured for the specified|current user.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return array
	 */
	public static function get_available_providers_for_user( $user = null ) {
		if ( empty( $user ) || ! is_a( $user, 'WP_User' ) ) {
			$user = wp_get_current_user();
		}

		$configured_providers = array();
		$providers = self::get_enabled_providers_for_user( $user );

		foreach ( $providers as $provider ) {
			if ( in_array( $provider, self::get_providers() ) && $provider['obj']->is_available_for_user( $user ) ) {
				array_push( $configured_providers, $provider );
			}
		}

		return $configured_providers;
	}

	/**
	 * Gets the Two-Factor Auth provider for the specified|current user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id Optional. User ID. Default is 'null'.
	 * @return object|null
	 */
	public static function get_primary_provider_for_user( $user_id = null ) {
		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$providers = self::get_available_providers_for_user( get_userdata( $user_id ) );

		// If there's only one available provider, force that to be the primary.
		if ( empty( $providers ) ) {
			return null;
		} elseif ( 1 === count( $providers ) ) {
			$provider = $providers[0];
		} else {
			$provider_name = get_user_meta( $user_id, self::PROVIDER_USER_META_KEY, true );

			$provider = array_filter( $providers, function($p) { return strcmp( $p['name'], $provider_name ); } );

			if ( empty( $provider ) ) {
				$provider = $providers[0];
			} else {
				$provider = $provider[0];
			}
		}

		/**
		 * Filter the two-factor authentication provider used for this user.
		 *
		 * @param string $provider The provider currently being used.
		 * @param int    $user_id  The user ID.
		 */
		$provider = apply_filters( 'two_factor_primary_provider_for_user', $provider, $user_id );

		if ( isset( $provider['obj'] ) ) {
			return $provider['obj'];
		}
		return null;
	}

	/**
	 * Quick boolean check for whether a given user is using two-step.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id Optional. User ID. Default is 'null'.
	 */
	public static function is_user_using_two_factor( $user_id = null ) {
		return ! empty( self::get_primary_provider_for_user( $user_id ) );
	}

	/**
	 * Handle the browser-based login.
	 *
	 * @since 0.1-dev
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function wp_login( $user_login, $user ) {
		if ( ! self::is_user_using_two_factor( $user->ID ) ) {
			return;
		}

		wp_clear_auth_cookie();

		self::show_two_factor_login( $user );
		exit;
	}

	/**
	 * Display the login form.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function show_two_factor_login( $user ) {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		$login_nonce = self::create_login_nonce( $user->ID );
		if ( ! $login_nonce ) {
			wp_die( esc_html__( 'Could not save login nonce.' ) );
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : $_SERVER['REQUEST_URI'];

		self::login_html( $user, $login_nonce['key'], $redirect_to );
	}

	/**
	 * Add short description. @todo
	 *
	 * @since 0.1-dev
	 */
	public static function backup_2fa() {
		if ( ! isset( $_GET['wp-auth-id'], $_GET['wp-auth-nonce'], $_GET['provider'] ) ) {
			return;
		}

		$user = get_userdata( $_GET['wp-auth-id'] );
		if ( ! $user ) {
			return;
		}

		$nonce = $_GET['wp-auth-nonce'];
		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
			exit;
		}

		$providers = self::get_available_providers_for_user( $user );
		if ( isset( $providers[ $_GET['provider'] ] ) ) {
			$provider = $providers[ $_GET['provider'] ];
		} else {
			wp_die( esc_html__( 'Cheatin&#8217; uh?' ), 403 );
		}

		wp_enqueue_style( 'two-factor-login', plugins_url( 'providers/css/two-factor-login.css', __FILE__ ) );

		self::login_html( $user, $_GET['wp-auth-nonce'], $_GET['redirect_to'], '', $provider );

		exit;
	}

	/**
	 * Generates the html form for the second step of the authentication process.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User       $user WP_User object of the logged-in user.
	 * @param string        $login_nonce A string nonce stored in usermeta.
	 * @param string        $redirect_to The URL to which the user would like to be redirected.
	 * @param string        $error_msg Optional. Login error message.
	 * @param string|object $provider An override to the provider.
	 */
	public static function login_html( $user, $login_nonce, $redirect_to, $error_msg = '', $provider = null ) {
		if ( empty( $provider ) ) {
			$provider = self::get_primary_provider_for_user( $user->ID );
		} elseif ( is_string( $provider ) && method_exists( $provider, 'get_instance' ) ) {
			$provider = call_user_func( array( $provider, 'get_instance' ) );
		}

		$provider_class = get_class( $provider );
		$available_providers = self::get_available_providers_for_user( $user );
		$backup_providers = array_diff_key( $available_providers, array( $provider_class => null ) );

		$interim_login = isset( $_REQUEST['interim-login'] ); // WPCS: override ok.

		$wp_login_url = wp_login_url();

		$rememberme = 0;
		if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
			$rememberme = 1;
		}

		if ( ! function_exists( 'login_header' ) ) {
			// We really should migrate login_header() out of `wp-login.php` so it can be called from an includes file.
			include_once( TWO_FACTOR_DIR . 'providers/includes/function-login-header.php' );
		}

		login_header();

		if ( ! empty( $error_msg ) ) {
			echo '<div id="login_error"><strong>' . esc_html( $error_msg ) . '</strong><br /></div>';
		}
		?>

		<form name="validate_2fa_form" id="loginform" action="<?php echo esc_url( set_url_scheme( add_query_arg( 'action', 'validate_2fa', $wp_login_url ), 'login_post' ) ); ?>" method="post" autocomplete="off">
				<input type="hidden" name="provider"      id="provider"      value="<?php echo esc_attr( $provider_class ); ?>" />
				<input type="hidden" name="wp-auth-id"    id="wp-auth-id"    value="<?php echo esc_attr( $user->ID ); ?>" />
				<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />
				<?php   if ( $interim_login ) { ?>
					<input type="hidden" name="interim-login" value="1" />
				<?php   } else { ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
				<?php   } ?>
				<input type="hidden" name="rememberme"    id="rememberme"    value="<?php echo esc_attr( $rememberme ); ?>" />

				<?php $provider->authentication_page( $user ); ?>
		</form>

		<?php if ( 1 === count( $backup_providers ) ) :
			$backup_classname = key( $backup_providers );
			$backup_provider  = $backup_providers[ $backup_classname ];
			?>
			<div class="backup-methods-wrap">
				<p class="backup-methods"><a href="<?php echo esc_url( add_query_arg( urlencode_deep( array(
					'action'        => 'backup_2fa',
					'provider'      => $backup_classname,
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => $login_nonce,
					'redirect_to'   => $redirect_to,
					'rememberme'    => $rememberme,
				) ), $wp_login_url ) ); ?>"><?php echo esc_html( sprintf( __( 'Or, use your backup method: %s &rarr;', 'two-factor' ), $backup_provider->get_label() ) ); ?></a></p>
			</div>
		<?php elseif ( 1 < count( $backup_providers ) ) : ?>
			<div class="backup-methods-wrap">
				<p class="backup-methods"><a href="javascript:;" onclick="document.querySelector('ul.backup-methods').style.display = 'block';"><?php esc_html_e( 'Or, use a backup method.', 'two-factor' ); ?></a></p>
				<ul class="backup-methods">
					<?php foreach ( $backup_providers as $backup_classname => $backup_provider ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( urlencode_deep( array(
							'action'        => 'backup_2fa',
							'provider'      => $backup_classname,
							'wp-auth-id'    => $user->ID,
							'wp-auth-nonce' => $login_nonce,
							'redirect_to'   => $redirect_to,
							'rememberme'    => $rememberme,
						) ), $wp_login_url ) ); ?>"><?php $backup_provider->print_label(); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<p id="backtoblog">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php esc_attr_e( 'Are you lost?' ); ?>"><?php echo esc_html( sprintf( __( '&larr; Back to %s' ), get_bloginfo( 'title', 'display' ) ) ); ?></a>
		</p>
		<?php
		/** This action is documented in wp-login.php */
		do_action( 'login_footer' ); ?>
		<div class="clear"></div>
		</body>
		</html>
		<?php
	}

	/**
	 * Create the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public static function create_login_nonce( $user_id ) {
		$login_nonce               = array();
		$login_nonce['key']        = wp_hash( $user_id . mt_rand() . microtime(), 'nonce' );
		$login_nonce['expiration'] = time() + HOUR_IN_SECONDS;

		if ( ! update_user_meta( $user_id, self::USER_META_NONCE_KEY, $login_nonce ) ) {
			return false;
		}
		return $login_nonce;
	}

	/**
	 * Delete the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public static function delete_login_nonce( $user_id ) {
		return delete_user_meta( $user_id, self::USER_META_NONCE_KEY );
	}

	/**
	 * Verify the login nonce.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce Login nonce.
	 */
	public static function verify_login_nonce( $user_id, $nonce ) {
		$login_nonce = get_user_meta( $user_id, self::USER_META_NONCE_KEY, true );
		if ( ! $login_nonce ) {
			return false;
		}

		if ( $nonce !== $login_nonce['key'] || time() > $login_nonce['expiration'] ) {
			self::delete_login_nonce( $user_id );
			return false;
		}
		return true;
	}

	/**
	 * Login form validation.
	 *
	 * @since 0.1-dev
	 */
	public static function login_form_validate_2fa() {
		if ( ! isset( $_POST['wp-auth-id'], $_POST['wp-auth-nonce'] ) ) {
			return;
		}

		$user = get_userdata( $_POST['wp-auth-id'] );
		if ( ! $user ) {
			return;
		}

		$nonce = $_POST['wp-auth-nonce'];
		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( get_bloginfo( 'url' ) );
			exit;
		}

		if ( isset( $_POST['provider'] ) ) {
			$providers = self::get_available_providers_for_user( $user );
			if ( isset( $providers[ $_POST['provider'] ] ) ) {
				$provider = $providers[ $_POST['provider'] ];
			} else {
				wp_die( esc_html__( 'Cheatin&#8217; uh?' ), 403 );
			}
		} else {
			$provider = self::get_primary_provider_for_user( $user->ID );
		}

		if ( true !== $provider->validate_authentication( $user ) ) {
			do_action( 'wp_login_failed', $user->user_login );

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				return;
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html__( 'ERROR: Invalid verification code.' ), $provider );
			exit;
		}

		self::delete_login_nonce( $user->ID );

		$rememberme = isset( $_REQUEST['rememberme'] ) && (bool) $_REQUEST['rememberme'];
		wp_set_auth_cookie( $user->ID, $rememberme );

		// Must be global because that's how login_header() uses it.
		global $interim_login;
		$interim_login = isset( $_REQUEST['interim-login'] ); // WPCS: override ok.

		if ( $interim_login ) {
			$customize_login = isset( $_REQUEST['customize-login'] );
			if ( $customize_login ) {
				wp_enqueue_script( 'customize-base' );
			}
			$message = '<p class="message">' . __( 'You have successfully logged in.' ) . '</p>';
			$interim_login = 'success'; // WPCS: override ok.
			login_header( '', $message ); ?>
			</div>
			<?php
			/** This action is documented in wp-login.php */
			do_action( 'login_footer' ); ?>
			<?php if ( $customize_login ) : ?>
				<script type="text/javascript">setTimeout( function(){ new wp.customize.Messenger({ url: '<?php echo wp_customize_url(); /* WPCS: XSS OK. */ ?>', channel: 'login' }).send('login') }, 1000 );</script>
			<?php endif; ?>
			</body></html>
			<?php
			exit;
		}
		$redirect_to = apply_filters( 'login_redirect', $_REQUEST['redirect_to'], $_REQUEST['redirect_to'], $user );
		wp_safe_redirect( $redirect_to );

		exit;
	}

	/**
	 * Filter the columns on the Users admin screen.
	 *
	 * @param  array $columns Available columns.
	 * @return array          Updated array of columns.
	 */
	public static function filter_manage_users_columns( array $columns ) {
		$columns['two-factor'] = __( 'Two-Factor' );
		return $columns;
	}

	/**
	 * Output the 2FA column data on the Users screen.
	 *
	 * @param  string $output      The column output.
	 * @param  string $column_name The column ID.
	 * @param  int    $user_id     The user ID.
	 * @return string              The column output.
	 */
	public static function manage_users_custom_column( $output, $column_name, $user_id ) {
		if ( 'two-factor' !== $column_name ) {
			return $output;
		}

		if ( ! self::is_user_using_two_factor( $user_id ) ) {
			return sprintf( '<span class="dashicons-before dashicons-no-alt">%s</span>', esc_html__( 'Disabled' ) );
		} else {
			$provider = self::get_primary_provider_for_user( $user_id );
			return esc_html( $provider->get_label() );
		}
	}

	// update_usermeta( absint( $user_id ), 'twitter', wp_kses_post( $_POST['twitter'] ) );

	/**
	 * Adds settings to 'WP-Admin -> Settings -> General' page
	 *
	 * @since 0.2-dev
	 */
	public static function add_settings_general() {
		if ( ! ( is_super_admin() && is_main_site() ) ) {
			return;
		}

		add_settings_section(
			self::ENABLED_PROVIDERS_KEY,
			__( 'Two-factor Methods', 'two_factor' ),
			array( __CLASS__, 'settings_section_generate' ),
			'general'
		);

		register_setting( 'general', self::ENABLED_PROVIDERS_KEY );
	}

	/**
	 * Add user profile fields.
	 *
	 * This executes during the `show_user_profile` & `edit_user_profile` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function user_two_factor_options( $user ) {
		if ( empty( self::get_providers() ) ) {
			return;
		}
		wp_enqueue_style( 'two-factor', plugins_url( 'providers/css/two-factor.css', __FILE__ ) );

		$enabled_providers = self::get_available_providers_for_user();
		$two_factor_disabled = empty( $enabled_providers );

		?>
		<h2><?php _e( 'Sign-in Methods' ); ?></h2>
		<table class="form-table">
		<tr id="two_factor-profile_option" class="two-factor two-factor-wrap">
			<th scope="row"><?php _e( '2-Step Verification' ); ?></th>
			<td>
		<?php

		if ( $two_factor_disabled ) {
			// Because get_user_meta() has no way of providing a def
			$enabled_providers = array();

			?>
			<input class="hidden" value=" "><!-- #24364 workaround -->
			<button type="button" class="button button-secondary two-factor two-factor-toggle hide-if-no-js"><?php _e( 'Enable 2-Step Verification' ); ?></button>
			<p class="description two-factor-toggle"><?php _e('Add a second layer of protection with 2-Step Verification, which requires a single-use code when you sign in.'); ?></p>
			<div class="two-factor two-factor-toggle hide-if-js">
			<?php
		} else {
			?><div class="two-factor two-factor-toggle"><?php
		} 

		$primary_provider = self::get_primary_provider_for_user( $user->ID );
		wp_nonce_field( 'user_two_factor_options', '_nonce_user_two_factor_options', false );

		if ( $two_factor_disabled ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( self::ENABLED_PROVIDERS_USER_META_KEY ); ?>[]" value="<?php /* Dummy input so $_POST value is passed when no providers are enabled. */ ?>"/>
			<?php
		}

		?>
		<table class="wp-list-table widefat two-factor-table plugins">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="cb-select-all-1">Select All</label>
					</td>
					<th scope="col" class="manage-column column-method column-primary"><?php _e( 'Method' ); ?></th>
					<th scope="col" class="manage-column column-details"><?php _e( 'Details' ); ?></th>
				</tr>
			</thead>
			<tbody id="the-list">
			<?php foreach ( self::get_providers() as $provider ) : ?>
				<?php if ( $provider['obj']->is_available_for_user( $user ) ) : ?>
				<tr class="active">
				<?php else : ?>
				<tr class="inactive">
				<?php endif; ?>
					<th scope="row" class="check-column">
						<label class="screen-reader-text">Select <?php $provider['obj']->print_label(); ?></label>
						<input type="hidden" name="checked[]" value="<?php $provider['obj']->is_available_for_user( $user ); ?>">
					</th>
					<td class="plugin-title column-primary"><strong><?php $provider['obj']->print_label(); ?></strong>
						<div class="row-actions visible">
							<?php if ( $provider['obj']->is_available_for_user( $user ) ) : ?>
							<span class="two-factor-option <?php esc_html_e( 'deactivate' ); ?>">
							<a href="#" id="two_factor-deactivate-<?php _e( esc_attr( $provider['key'] ) ); ?>" aria-label="Deactivate <?php $provider['obj']->print_label(); ?>">Deactivate</a>
							</span>
							<?php else : ?>
							<span class="two-factor-option <?php esc_html_e( 'activate' ); ?>">
							<a href="#" id="two_factor-activate-<?php _e( esc_attr( $provider['key'] ) ); ?>" aria-label="Activate <?php $provider['obj']->print_label(); ?>">Activate</a>
							</span>
							<?php endif; ?>
							<?php _e( ' | ' ); ?>
							<?php if ( $provider['obj']->is_available_for_user( $user ) ) : ?>
							<span class="two-factor-option <?php esc_html_e( 'delete' ); ?>">
							<a href="#" id="two_factor-delete-<?php _e( esc_attr( $provider['key'] ) ); ?>" aria-label="Remove Data <?php $provider['obj']->print_label(); ?>">Remove Data</a>
							</span>
							<?php else : ?>
							<span class="two-factor-option <?php esc_html_e( 'setup' ); ?>">
							<a href="#" id="two_factor-setup-<?php _e( esc_attr( $provider['key'] ) ); ?>" aria-label="Setup <?php $provider['obj']->print_label(); ?>">Setup</a>
							</span>
							<?php endif; ?>
						</div>
					</td>
					<td class="column-details desc">
						<div class="plugin-description two-factor-column-details">
						<?php $provider['obj']->print_description(); ?>
						</div>
						<div class="two-factor-options">
							<div class="two-factor-option-details">
							<?php do_action( 'two_factor_user_option_details-' . $provider['name'], $user ); ?>
							</div>
							<div class="two-factor-options two-factor-toggle hide-if-js">
							<?php do_action( 'two_factor_user_options-' . $provider['name'], $user ); ?>
							</div>
						</div>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		</td></tr>
		</table>
		<?php

		/**
		 * Fires after the Two Factor methods table.
		 *
		 * To be used by Two Factor methods to add settings UI.
		 *
		 * @since 0.1-dev
		 */
		do_action( 'show_user_security_settings', $user );
	}

	/**
	 * Update the user meta value.
	 *
	 * This executes during the `personal_options_update` & `edit_user_profile_update` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_two_factor_options_update( $user_id ) {
		if ( isset( $_POST['_nonce_user_two_factor_options'] ) ) {
			check_admin_referer( 'user_two_factor_options', '_nonce_user_two_factor_options' );
			$providers         = self::get_providers();

			if ( ! isset( $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ] ) ||
					! is_array( $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ] ) ) {
				return;
			}

			$enabled_providers = $_POST[ self::ENABLED_PROVIDERS_USER_META_KEY ];
			$enabled_providers = array_intersect( $enabled_providers, array_keys( $providers ) );
			update_user_meta( $user_id, self::ENABLED_PROVIDERS_USER_META_KEY, $enabled_providers );

			// Whitelist the new values to only the available classes and empty.
			$new_provider = isset( $_POST[ self::PROVIDER_USER_META_KEY ] ) ? $_POST[ self::PROVIDER_USER_META_KEY ] : '';
			if ( empty( $new_provider ) || array_key_exists( $new_provider, $providers ) ) {
				update_user_meta( $user_id, self::PROVIDER_USER_META_KEY, $new_provider );
			}
		}
	}

	public static function settings_section_generate( $argv ) {
		if ( ! ( is_super_admin() && is_main_site() ) ) {
			return;
		}

		$section_name = $argv[ 'id' ];
		$provider_keys = array_map( function($p) { return $p['key']; }, self::get_providers() );
		$enabled_providers = self::get_enabled_providers( $provider_keys );

		foreach ( self::get_providers( true ) as $provider ) {
			$id = __( $section_name . '[' . $provider['key'] . ']' );
			$is_en = in_array( $provider[ 'key' ], $enabled_providers );

			add_settings_field(
				$id,
				$provider['obj']->get_label(),
				array( __CLASS__, 'show_checkbox' ),
				'general',
				$section_name,
				array( 'label_for' => $id , 'id' => $id, 'name' => $id, 'is_enabled' => $is_en )
			);

			// register the field to the section
			register_setting( $section_name, $id );
		}
	}

	/**
	 * Display option for provider enable/disable checkbox.
	 *
	 * @since 0.2-dev
	 */
	public static function show_checkbox( $argv ) {
		$elem = sprintf( '<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s >',
			esc_attr( $argv[ 'id' ] ),
			esc_attr( $argv[ 'name' ] ),
			checked( $argv[ 'is_enabled' ], true, false )
		);

		_e( $elem );
	}

	public static function get_enabled_providers( $default_val = null ) {
		$providers = get_option( self::ENABLED_PROVIDERS_KEY, $default_val );

		if ( ! $providers ) {
			return array();
		}
		return array_keys( $providers );
	}

	/**
	 * Add the given provider keys as a site wide option if it doesn't
	 * already exist in the database. This will do nothing if the key
	 * already exists.
	 *
	 * @since 0.2-dev
	 */
	public static function add_option_key( array $providers ) {
		// get the strict name versions for each provider class name
		$provider_keys = array_values( array_map( function( $p ) { return $p[ 'key' ]; }, $providers ) );
		if ( empty( $provider_keys ) ) {
			return;
		}

		// make sure we have unique provider keys in the database
		add_option( self::ENABLED_PROVIDERS_KEY, $provider_keys );
	}

	/**
	 * Display option for provider enable/disable checkbox.
	 *
	 * @since 0.2-dev
	 */
	public static function deactivate_hook() {
		unregister_setting( 'general', self::ENABLED_PROVIDERS_KEY );
	}
}