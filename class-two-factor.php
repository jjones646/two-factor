<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// load the trait methods so we can use them in the class
require_once( TWO_FACTOR_DIR . 'traits-two-factor.php' );

/**
 * Class for creating two factor authorization.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor {

	// use the generic helper traits
	use Two_Factor_Trails;

	/**
	 * The global key holding all available providers.
	 *
	 * @type string
	 */
	const ENABLED_PROVIDERS_KEY = 'two_factor-providers';

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

		add_action( 'edit_user_profile',          array( __CLASS__, 'user_two_factor_options' ), 20 );
		add_action( 'show_user_profile',          array( __CLASS__, 'user_two_factor_options' ), 20 );
		
		add_action( 'init',           			  array( __CLASS__, 'user_two_factor_options_update' ), 30 );
		add_action( 'personal_options_update',    array( __CLASS__, 'user_two_factor_options_update' ) );
		add_action( 'edit_user_profile_update',   array( __CLASS__, 'user_two_factor_options_update' ) );

		add_filter( 'manage_users_columns',       array( __CLASS__, 'filter_manage_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'manage_users_custom_column' ), 10, 3 );
		add_filter( 'removable_query_args', 	  array( __CLASS__, 'add_filterable_params' ) );
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
			'Two_Factor_U2F'     	  => TWO_FACTOR_DIR . 'providers/class-two-factor-u2f.php',
			'Two_Factor_Totp'         => TWO_FACTOR_DIR . 'providers/class-two-factor-totp.php',
			'Two_Factor_Email'        => TWO_FACTOR_DIR . 'providers/class-two-factor-email.php',
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
					
					$data_obj[ 'key' ] = self::make_provider_key( $class );
					$data_obj[ 'name' ] = $class;
					$data_obj[ 'obj' ] = $inst;

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

		$providers_can_use = array_filter( self::get_providers(), function ( $p ) use ( $providers ) {
			return in_array( $p['key'], $providers );
		} );

		if ( empty( $providers_can_use ) ) {
			return array();
		}
		return $providers_can_use;
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
		} else {
			$provider = $providers[0];
		}

		/**
		 * Filter the two-factor authentication provider used for this user.
		 *
		 * @param string $provider The provider currently being used.
		 * @param int    $user_id  The user ID.
		 */
		$provider = apply_filters( 'two_factor_primary_provider_for_user', $provider, $user_id );

		if ( isset( $provider ) ) {
			return $provider;
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

	// /**
	//  * Add short description. @todo
	//  *
	//  * @since 0.1-dev
	//  */
	// public static function backup_2fa() {
	// 	if ( ! isset( $_GET['wp-auth-id'], $_GET['wp-auth-nonce'], $_GET['provider'] ) ) {
	// 		return;
	// 	}

	// 	$user = get_userdata( $_GET['wp-auth-id'] );
	// 	if ( ! $user ) {
	// 		return;
	// 	}

	// 	$nonce = $_GET['wp-auth-nonce'];
	// 	if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
	// 		wp_safe_redirect( get_bloginfo( 'url' ) );
	// 		exit;
	// 	}

	// 	$providers = self::get_available_providers_for_user( $user );
	// 	if ( isset( $providers[ $_GET['provider'] ] ) ) {
	// 		$provider = $providers[ $_GET['provider'] ];
	// 	} else {
	// 		wp_die( esc_html__( 'Cheatin&#8217; uh?' ), 403 );
	// 	}

	// 	wp_enqueue_style( 'two-factor-login', plugins_url( 'providers/css/two-factor-login.css', __FILE__ ) );

	// 	self::login_html( $user, $_GET['wp-auth-nonce'], $_GET['redirect_to'], '', $provider );

	// 	exit;
	// }

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
		}
		$provider_key = $provider['key'];
		// wp_die( var_dump( $ppk ) );

		$available_providers = self::get_available_providers_for_user( $user );
		if ( 1 < count( $available_providers ) ) {
			$backup_provider = $available_providers[1];	
		}

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
				<input type="hidden" name="provider"      id="provider"      value="<?php echo esc_attr( $provider_key ); ?>" />
				<input type="hidden" name="wp-auth-id"    id="wp-auth-id"    value="<?php echo esc_attr( $user->ID ); ?>" />
				<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />
				<?php if ( $interim_login ) : ?>
					<input type="hidden" name="interim-login" value="1" />
				<?php else : ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="rememberme"    id="rememberme"    value="<?php echo esc_attr( $rememberme ); ?>" />
				<?php $provider['obj']->authentication_page( $user ); ?>
		</form>

		<?php if ( isset( $backup_provider ) ) : ?>
			<div class="backup-methods-wrap">
				<p class="backup-methods"><a href="<?php echo esc_url( add_query_arg( urlencode_deep( array(
					'action'        => 'backup_2fa',
					'provider'      => $backup_classname,
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => $login_nonce,
					'redirect_to'   => $redirect_to,
					'rememberme'    => $rememberme,
				) ), $wp_login_url ) ); ?>"><?php esc_html_e( sprintf( __( 'Or, use your backup method: %1$s &rarr;', 'two-factor' ), $backup_provider['obj']->get_label() ) ); ?></a></p>
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
		$login_nonce['expiration'] = time() + MINUTE_IN_SECONDS;

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

		$ret = true;
		if ( $nonce !== $login_nonce['key'] || time() > $login_nonce['expiration'] ) {
			$ret = false;
		}

		self::delete_login_nonce( $user_id );
		return $ret;
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
			$provider_key = $_POST[ 'provider' ];
			$provider = array_filter( self::get_available_providers_for_user( $user ), function( $p ) use ( $provider_key ) {
							return ! strcasecmp( $p['key'], $provider_key );
						} );

			if ( isset( $provider ) ) {
				$provider = $provider[0];
			} else {
				wp_die( esc_html__( 'Invalid two-factor provider.' ), 403 );
			}

		} else {
			$provider = self::get_primary_provider_for_user( $user->ID );
			wp_die( var_dump( $provider ) );
		}

		if ( true !== $provider['obj']->validate_authentication( $user ) ) {
			do_action( 'wp_login_failed', $user->user_login );

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				return;
			}

			self::login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html__( 'ERROR: Invalid verification code.' ), $provider );
			exit;
		}

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
		$columns['two_factor'] = __( 'Two-factor' );
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
		if ( 'two_factor' !== $column_name ) {
			return $output;
		}

		if ( ! self::is_user_using_two_factor( $user_id ) ) {
			return sprintf( '<span class="dashicons-before dashicons-no-alt">%s</span>', esc_html__( 'Disabled' ) );
		} else {
			$provider = self::get_primary_provider_for_user( $user_id );
			return esc_html( $provider['obj']->get_label() );
		}
	}

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

		$user_providers = array_map( function($p) { return $p['key']; }, self::get_enabled_providers_for_user() );
		$two_factor_disabled = empty( $user_providers );

		$configured_providers = self::get_available_providers_for_user();

		?>
		<h2><?php _e( 'Sign-in Methods' ); ?></h2>
		<table class="form-table">
		<tr id="two_factor-profile_option" class="two-factor two-factor-wrap">
			<th scope="row"><?php _e( '2-Step Verification' ); ?></th>
			<td>
		<?php

		if ( $two_factor_disabled ) {
			// Because get_user_meta() has no way of providing a def
			$configured_providers = array();

			?>
			<input class="hidden" value=" "><!-- #24364 workaround -->
			<button type="button" class="button button-secondary two-factor-enable two-factor-toggle hide-if-no-js"><?php _e( 'Enable 2-Step Verification' ); ?></button>
			<p class="description two-factor two-factor-toggle"><?php _e('Add a second layer of protection with 2-Step Verification, which requires a single-use code when you sign in.'); ?></p>
			<div class="two-factor two-factor-toggle hide-if-js">
			<?php
		} else {
			?><div class="two-factor two-factor-toggle"><?php
		} 

		$primary_provider = self::get_primary_provider_for_user( $user->ID );
		wp_nonce_field( 'two_factor_options_update', 'two_factor_nonce' );

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
			<?php foreach ( self::get_providers() as $provider ) :
				$fields = array(
					'description' => '',
		            'manage' => self::make_option_link( 'Manage', $provider[ 'key' ], 'two-factor-manage' )
	        	);
				$is_active = in_array( $provider['key'], $user_providers );
				$provider_fields = apply_filters( 'two_factor_fields-' . $provider['name'], $fields );
				?>
				<tr class="<?php _e( $is_active ? 'active' : 'inactive' ); ?>">
					<th scope="row" class="check-column">
						<label class="screen-reader-text">Select <?php $provider['obj']->print_label(); ?></label>
						<input type="hidden" name="checked[]" value="<?php $provider['obj']->is_available_for_user( $user ); ?>">
					</th>
					<td class="plugin-title column-primary"><strong><?php $provider['obj']->print_label(); ?></strong>
						<div class="row-actions visible">
						<?php

						$action_name = esc_html( $is_active ? 'Deactivate' : 'Activate' );
						$action_name_strict = self::make_provider_key( $action_name );

						$update_link = wp_nonce_url( admin_url('profile.php'), 'two_factor_options_update_' . $action_name_strict, 'two_factor_nonce' );
						$update_link = add_query_arg( 'action', $action_name_strict, $update_link );
						$update_link = add_query_arg( 'provider', $provider[ 'key' ], $update_link );

						_e( self::make_option_link( $action_name, $provider[ 'key' ], 'two-factor-update', $update_link, false ) );

						if ( ! empty( $provider_fields[ 'manage' ] ) && $is_active ) {
							_e( $provider_fields[ 'manage' ] );
						}

						?>
						</div>
					</td>
					<td class="column-details desc">
						<div class="plugin-description two-factor-column-details">
							<?php _e( $provider_fields[ 'description' ] ); ?>
						</div>
						<div class="two-factor-options">
							<div class="two-factor-option-details">
								<?php do_action( 'two_factor_user_option_details-' . $provider['name'], $user ); ?>
							</div>
							<div class="two-factor-options two-factor-toggle hide-if-js">
								<?php do_action( 'two_factor_user_option-' . $provider['name'], $user ); ?>
							</div>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		</td>
		</tr>
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
	public static function user_two_factor_options_update( $user ) {
		if ( empty( $user ) ) {
			$user = wp_get_current_user();
		}

		if ( is_numeric( $user ) ) {
			$user = get_userdata( $user );
		}
		// make sure all parameters were give, otherwise skip any updating
		foreach( self::add_filterable_params() as $param ) {
			if ( ! isset( $_REQUEST[ $param ] ) ) {
				return;
			}
		}

		if ( check_admin_referer( 'two_factor_options_update_' . $_REQUEST[ 'action' ], 'two_factor_nonce' ) ) {
			$available_providers = array_map( function( $p ) { return $p['key']; }, self::get_providers() );
			if ( empty( $available_providers ) ) {
				return;
			}

			$provider = $_REQUEST[ 'provider' ];
			if ( in_array( $provider, $available_providers ) ) {
				// at this point, we know that the given provider's key is a valid one and
				// the user is allowed to use it
				$current_providers = get_user_meta( $user->ID, self::ENABLED_PROVIDERS_USER_META_KEY, true );

				if ( empty( $current_providers ) ) {
					$current_providers = array();
					// make sure the key exists for the user - this won't overwrite anything
					// if it already exists
					add_user_meta( $user->ID, self::ENABLED_PROVIDERS_USER_META_KEY, $current_providers, true );
				}

				if ( in_array( $provider, $current_providers ) ) {
					if ( $_REQUEST[ 'action' ] == 'deactivate' ) {
						// disable the provider for the user to use
						$current_providers = array_filter( $current_providers, function( $p ) use ( $provider ) {
							return strcasecmp( $p, $provider );
						} );
					}
				} else {
					if ( $_REQUEST[ 'action' ] == 'activate' ) {
						// enable the provider for the user to use
						array_push( $current_providers, $provider );
					}
				}

				// write the updated list of providers for the user to the database
				update_user_meta( $user->ID, self::ENABLED_PROVIDERS_USER_META_KEY, $current_providers );
			}

			if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
				$return_url = remove_query_arg( wp_removable_query_args() );
		    	$return_url = wp_validate_redirect( $return_url );

		    	wp_safe_redirect( esc_url( $return_url ) );
	    	}
		}
	}

	public static function add_filterable_params( array $vars = null ) {
		if ( empty( $vars ) ) {
			$vars = array();
		}
		array_push( $vars, 'two_factor_nonce', 'action', 'provider' );
		return $vars;
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
