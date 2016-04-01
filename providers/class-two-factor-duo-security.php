<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class for creating a Time-based One-time Password provider.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Totp
 */
class Two_Factor_Duo_Security extends Two_Factor_Provider {

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	const SECRET_META_KEY = '_two_factor_due_security_key';

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	const NOTICES_META_KEY = '_two_factor_due_security_notices';

	/**
	 * The timeout for duo to expire a request.
	 *
	 * @var int
	 */
	const DUO_EXPIRE = 300;

	/**
	 * The timeout for the app to expire requests.
	 *
	 * @var int
	 */
	const APP_EXPIRE = 3600;

	/**
	 * The integration key length.
	 *
	 * @var int
	 */
	const IKEY_LEN = 20;

	/**
	 * The secret key length.
	 *
	 * @var int
	 */
	const SKEY_LEN = 40;

	/**
	 * The API hostname length.
	 *
	 * @var string
	 */
	const AKEY_LEN = 40;

	/**
	 * The endpoint for the duo security api.
	 *
	 * @var string
	 */
	const PING_ENDPOINT = '/auth/v2/ping';

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
	private $auth_token = 'duo_auth_cookie';

	/**
	 * The user meta token key.
	 *
	 * @var string
	 */
    private $auth_key = 'duo_secure_auth_cookie';
    
	/**
	 * Class constructor. Sets up hooks, etc.
	 */
	protected function __construct() {
		$this->priority = 30;

		add_action( 'admin_enqueue_scripts',       					array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', 								array( $this, 'admin_notices' ) );
		add_action( 'personal_options_update',              		array( $this, 'user_options_update' ) );
		add_action( 'edit_user_profile_update',             		array( $this, 'user_options_update' ) );
		add_action( 'two-factor-user-options-' . 		__CLASS__, 	array( $this, 'print_user_options' ) );
		add_action( 'two-factor-user-option-details-' . __CLASS__, 	array( $this, 'print_user_option_details' ) );

		/*-------------XML-RPC Features-----------------*/
	    if($this->duo_get_option('duo_xmlrpc', 'off') == 'off') {
	        add_filter( 'xmlrpc_enabled', array( $this, '__return_false' ) );
	    }

	    add_action('init', 				array( $this, 'duo_verify_auth' ), 	10);
	    add_action('clear_auth_cookie', array( $this, 'duo_unset_cookie' ), 10);
	    add_filter('authenticate', 		array( $this, 'duo_authenticate_user' ), 10, 3);

	    // Custom fields in network settings
	    add_action('wpmu_options', 			array( $this, 'duo_mu_options' ) );
	    add_action('update_wpmu_options', 	array( $this, 'duo_update_mu_options' ) );
	    add_action('admin_init', 			array( $this, 'duo_admin_init' ) );

		return parent::__construct();
	}

	/**
	 * Ensures only one instance of this class exists in memory at any one time.
	 */
	public static function get_instance() {
		static $instance;
		if ( ! isset( $instance ) ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 0.2-dev
	 *
	 * @param string $hook Current page.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'user-edit.php', 'profile.php' ) ) ) {
			return;
		}

		wp_enqueue_script( 'duo-security', plugins_url( 'js/duo-security.js', __FILE__ ), array( 'jquery' ), null, true );
	}

	/**
	 * Returns the name of the provider.
	 * 
	 * @since 0.2-dev
	 */
	public function get_label() {
		return _x( 'Duo Security', 'Provider Label' );
	}

	/**
	 * Returns a short description about the authentication method.
	 *
	 * @since 0.2-dev
	 */
	public function get_description() {
		return _x( 'Use Duo Security to receive push notifications to your phone.', 'Two-Factor Authentication Method Description' );
	}

	public function is_enabled() {
		return true;
	}

	protected function get_android_link() {
		return esc_url_raw( 'https://play.google.com/store/apps/details?id=com.duosecurity.duomobile' );
	}

	protected function get_ios_link() {
		return esc_url_raw( 'https://geo.itunes.apple.com/us/app/duo-mobile/id422663827?mt=8' );
	}

	/**
	 * Display TOTP options on the user settings page.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user The current user being edited.
	 */
	public function print_user_options( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		require_once( TWO_FACTOR_DIR . 'providers/class-mobile-detect.php' );
		$detect = new Mobile_Detect();

		// build a customized message using this info
		$message = '';
		if( $detect->isiOS() ) {
			$message = __( '<a target="_blank" href="' . $this->get_ios_link() . '">Download App</a>' );
		} elseif ( $detect->isAndroidOS() ) {
			$message = __( '<a target="_blank" href="' . $this->get_android_link() . '">Download App</a>' );
		} else {
			$message = sprintf( 'Download at the <a target="_blank" href="%1$s">App Store</a> or on <a target="_blank" href="%2$s">Google Play&trade;</a>.', $this->get_ios_link(), $this->get_android_link() );
		}

		_e( sprintf( '<div class="%1$s"><p>%2$s</p></div>', 'two-factor-details', $message ) );
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

		$site_name = get_bloginfo( 'name', 'display' );
		$totp_acct = esc_html__( $site_name . ':' . $user->user_login );

		$message = sprintf( __( '%s' ), $this->get_label() ) . ' is ';

		if ( empty( $key ) ) {
			$message .= 'disabled';
		} else {
			$message .= 'enabled';
		}

		$message = $totp_acct;
		// _e( sprintf( '<div class="%1$s"><p><strong>Account Tag:</strong> %2$s</p></div>', 'two-factor-details', $message ) );
	}

	/**
	 * Save the options.
	 * 
	 * @since 0.2-dev
	 *
	 * @param int $user_id The user ID whose options are being updated.
	 */
	public function user_options_update( $user_id ) {
		if ( isset( $_POST['_nonce_user_two_factor_duo_options'] ) ) {
			check_admin_referer( 'user_two_factor_duo_options', '_nonce_user_two_factor_duo_options' );

			$current_key = get_user_meta( $user_id, self::SECRET_META_KEY, true );
			// If the key hasn't changed or is invalid, do nothing.
			if ( ! isset( $_POST['two-factor-duo-security-key'] ) || $current_key === $_POST['two-factor-duo-security-key'] || ! preg_match( '/^[' . self::$_base_32_chars . ']+$/', $_POST['two-factor-duo-security-key'] ) ) {
				return false;
			}

			$notices = array();

			if ( ! empty( $_POST['two-factor-duo-security-authcode'] ) ) {
				if ( $this->is_valid_authcode( $_POST['two-factor-duo-security-key'], $_POST['two-factor-duo-security-authcode'] ) ) {
					if ( ! update_user_meta( $user_id, self::SECRET_META_KEY, $_POST['two-factor-duo-security-key'] ) ) {
						$notices['error'][] = __( '2-Step, unable to save Verification Code. Please re-scan the QR code or enter the code provided by your application.' );
					}
				} else {
					$notices['error'][] = __( '2-Step, Verification Codes not activated. The authentication code you entered is invalid. Please re-scan the QR code or enter the code provided by your application.' );
				}
			}

			if ( ! empty( $notices ) ) {
				update_user_meta( $user_id, self::NOTICES_META_KEY, $notices );
			}
		}
	}

	/**
	 * Display any available admin notices.
	 */
	public function admin_notices() {
		$notices = get_user_meta( get_current_user_id(), self::NOTICES_META_KEY, true );

		if ( ! empty( $notices ) ) {
			delete_user_meta( get_current_user_id(), self::NOTICES_META_KEY );
			foreach ( $notices as $class => $messages ) {
				foreach ( $messages as $msg ) {
					_e( sprintf( '<div class="%1$s"><p>%2$s</p></div>', esc_html__( 'notice notice-error is-dismissible' ), esc_html__( $msg ) ) );
				}
			}
		}
	}

	/**
	 * Validates authentication.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @return bool Whether the user gave a valid code
	 */
	public function validate_authentication( $user ) {
		$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );

		return $this->is_valid_authcode( $key, $_REQUEST['authcode'] );
	}

	// /**
	//  * Pack stuff
	//  *
	//  * @param string $value The value to be packed.
	//  *
	//  * @return string Binary packed string.
	//  */
	// public static function pack64( $value ) {
	// 	// 64bit mode (PHP_INT_SIZE == 8).
	// 	if ( PHP_INT_SIZE >= 8 ) {
	// 		// If we're on PHP 5.6.3+ we can use the new 64bit pack functionality.
	// 		if ( version_compare( PHP_VERSION, '5.6.3', '>=' ) && PHP_INT_SIZE >= 8 ) {
	// 			return pack( 'J', $value );
	// 		}
	// 		$highmap = 0xffffffff << 32;
	// 		$higher  = ( $value & $highmap ) >> 32;
	// 	} else {
	// 		/*
	// 		 * 32bit PHP can't shift 32 bits like that, so we have to assume 0 for the higher
	// 		 * and not pack anything beyond it's limits.
	// 		 */
	// 		$higher = 0;
	// 	}

	// 	$lowmap  = 0xffffffff;
	// 	$lower   = $value & $lowmap;

	// 	return pack( 'NN', $higher, $lower );
	// }

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// Only available if the secret key has been saved for the user.
		$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );

		return ! empty( $key );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		require_once( ABSPATH . '/wp-admin/includes/template.php' );
		?>
		<p><?php esc_html_e( 'Waiting for notification response.' ); ?></p>
		<?php
	}

	public function duo_get_option( $key, $default='' ) {
        if ( is_multisite() ) {
            return get_site_option($key, $default);
        }
        return get_option($key, $default);
    }

	public function duo_mu_options() {
		?>
        <h3>Duo Security</h3>
        <table class="form-table">
            <?php duo_settings_text();?></td></tr>
            <tr><th>Integration key</th><td><?php duo_settings_ikey();?></td></tr>
            <tr><th>Secret key</th><td><?php duo_settings_skey();?></td></tr>
            <tr><th>API hostname</th><td><?php duo_settings_host();?></td></tr>
            <tr><th>Roles</th><td><?php duo_settings_roles();?></td></tr>
            <tr><th>Disable XML-RPC</th><td><?php duo_settings_xmlrpc();?></td></tr>
        </table>
		<?php
    }

    public function duo_update_mu_options() {
        if(isset($_POST['duo_ikey'])) {
            $ikey = $_POST['duo_ikey'];
            $result = update_site_option('duo_ikey', $ikey);
        }

        if(isset($_POST['duo_skey'])) {
            $skey = $_POST['duo_skey'];
            $result = update_site_option('duo_skey', $skey);
        }

        if(isset($_POST['duo_host'])) {
            $host = $_POST['duo_host'];
            $result = update_site_option('duo_host', $host);
        }

        if(isset($_POST['duo_roles'])) {
            $roles = $_POST['duo_roles'];
            $result = update_site_option('duo_roles', $roles);
        }

        if(isset($_POST['duo_xmlrpc'])) {
            $xmlrpc = $_POST['duo_xmlrpc'];
            $result = update_site_option('duo_xmlrpc', $xmlrpc);
        }
        else {
            $result = update_site_option('duo_xmlrpc', 'on');
        }
    }

    // public function duo_add_link($links, $file) {
    //     static $this_plugin;
    //     if (!$this_plugin) $this_plugin = plugin_basename(__FILE__);

    //     if ($file == $this_plugin) {
    //         $settings_link = '<a href="options-general.php?page=duo_wordpress">'.__("Settings", "duo_wordpress").'</a>';
    //         array_unshift($links, $settings_link);
    //     }
    //     return $links;
    // }

    public function duo_get_user_agent() {
        global $wp_version;
        $duo_wordpress_version = '2.5.1';

        return $_SERVER['SERVER_SOFTWARE'] . " WordPress/$wp_version duo_wordpress/$duo_wordpress_version";
    }

    /*
     * Get Duo's system time.
     * If that fails then use server system time
     */
    public function duo_get_time() {
        $time = NULL;

        if ( !is_ssl() ) {
            //fall back to local time
            error_log('SSL is disabled. Can\'t fetch Duo server time.');
        }
        else {
            $duo_host = duo_get_option('duo_host');
            $headers = duo_sign_ping($duo_host);

            $duo_url = 'https://' . $duo_host . self::PING_ENDPOINT;
            $cert_file = TWO_FACTOR_DIR . 'certificates/duo_security_certs.pem';

            if( ini_get('allow_url_fopen') ) {
                $time =  duo_get_time_fopen($duo_url, $cert_file, $headers);
            } 
            else if(in_array('curl', get_loaded_extensions())){
                $time = duo_get_time_curl($duo_url, $cert_file, $headers);
            }
            else{
                $time = duo_get_time_WP_HTTP($duo_url, $headers);
            }
        }

        //if all fails, use local time
        return ($time != NULL ? $time : time());;
    }

    public function duo_get_time_fopen($duo_url, $cert_file, $headers) {
        $settings = array(
                        'http'=>array(
                            'method' => 'GET',
                            'header' => $headers,
                            'user_agent'=> duo_get_user_agent(),
                        ),
                        'ssl'=>array(
                            'allow_self_signed'=>false,
                            'verify_peer'=>true,
                            'cafile'=>$cert_file,
                        )
        );

        if ( defined('WP_PROXY_HOST') && defined('WP_PROXY_PORT')) {
            $settings['http']['proxy'] = 'tcp://' . WP_PROXY_HOST . ':' . WP_PROXY_PORT;
        }

        $context = stream_context_create($settings);
        $response = json_decode(file_get_contents($duo_url, false, $context), true);

        if (!$response){
            return NULL;
        }
        return (int)$response['response']['time'];
    }

    public function duo_get_time_curl($duo_url, $cert_file, $headers) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $duo_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, $cert_file);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_USERAGENT, duo_get_user_agent());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ( defined('WP_PROXY_HOST') && defined('WP_PROXY_PORT')) {
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $ch, CURLOPT_PROXY, WP_PROXY_HOST );
            curl_setopt( $ch, CURLOPT_PROXYPORT, WP_PROXY_PORT );
        }

        $response =json_decode(curl_exec($ch), true);
        curl_close ($ch);

        if (!$response){
            return NULL;
        }
        return (int)$response['response']['time'];
    }

    // Uses Wordpress HTTP. We can't specify our SSL cert here.
    // Servers with out of date root certs may fail.
    public function duo_get_time_WP_HTTP($duo_url, $headers) {
        if( !class_exists('WP_Http') ){
            require_once( ABSPATH . WPINC . '/class-http.php' );
        }

        $args = array(
            'method'      =>    'GET',
            'blocking'    =>    true,
            'sslverify'   =>    true,
            'user-agent'  =>    duo_get_user_agent(),
            'headers'     =>    $headers,
        );

        $response = wp_remote_get($duo_url, $args);

        if(is_wp_error($response)){
            $error_message = $response->get_error_message();
            error_log("Could not fetch Duo server time: $error_message");

            return NULL;
        }
        else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return (int)$body['response']['time'];
        }
    }

    public function parameterize($key, $value) {
        return sprintf('%s="%s"', $key, $value);
    }

    public function duo_sign_request($user, $redirect) {
        $ikey = duo_get_option('duo_ikey');
        $skey = duo_get_option('duo_skey');
        $host = duo_get_option('duo_host');
        $akey = duo_get_akey();

        $username = $user->user_login;
        $duo_time = duo_get_time();

        $request_sig = Duo::signRequest($ikey, $skey, $akey, $username, $duo_time);

        $post_action = esc_url(site_url('wp-login.php', 'login_post'));
        $iframe_attributes = array(
            'id' => 'duo_iframe',
            'data-host' => $host,
            'data-sig-request' => $request_sig,
            'data-post-action' => $post_action,
            'frameborder' => '0',
        );
        $iframe_attributes = array_map(
            "parameterize",
            array_keys($iframe_attributes),
            array_values($iframe_attributes)
        );
        $iframe_attributes = implode(" ", $iframe_attributes);

		?>
	    <html>
	        <head>
	            <meta http-equiv="X-UA-Compatible" content="IE=edge">
	            <meta name="viewport" content="width=device-width, initial-scale=1">
	            <?php
	                global $wp_version;
	                if(version_compare($wp_version, "3.3", "<=")){
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/login.css') . '" />';
	                }
	                else if(version_compare($wp_version, "3.7", "<=")){
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/wp-admin.css') . '" />';
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/colors-fresh.css') . '" />';
	                }
	                else if(version_compare($wp_version, "3.8", "<=")){
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/wp-admin.css') . '" />';
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/colors.css') . '" />';
	                }
	                else {
	                    echo '<link rel="stylesheet" type="text/css" href="' . admin_url('css/login.min.css') . '" />';
	                }

	            ?>

	            <style>
	                body {
	                    background: #f1f1f1;
	                }
	                .centerHeader {
	                    width: 100%;
	                    padding-top: 8%;
	                }
	                #WPLogo {
	                    width: 100%;
	                }
	                .iframe_div {
	                    width: 90%;
	                    max-width: 620px;
	                    margin: 0 auto;
	                }
	                #duo_iframe {
	                    height: 500px;
	                    width: 100%;
	                    min-width: 304px;
	                    max-width: 620px;
	                }
	                div {
	                    background: transparent;
	                }
	            </style>
	        </head>

	        <body class="login" >
	            <script src="<?php echo plugins_url('duo_web/Duo-Web-v2.min.js?v=2', __FILE__); ?>"></script>

	            <h1 class="centerHeader">
	                <a href="http://wordpress.org/" id="WPLogo" title="Powered by WordPress"><?php echo get_bloginfo('name'); ?></a>
	            </h1>
	            <div class="iframe_div">
	                <iframe <?php echo $iframe_attributes ?>></iframe>
	            </div>
	            <form method="POST" style="display:none;" id="duo_form">
	                <?php if (isset($_POST['rememberme'])) { ?>
	                <input type="hidden" name="rememberme" value="<?php echo esc_attr($_POST['rememberme'])?>"/>
	                <?php
	                }
	                if (isset($_REQUEST['interim-login'])){
	                    echo '<input type="hidden" name="interim-login" value="1"/>';
	                }
	                else {
	                    echo '<input type="hidden" name="redirect_to" value="' . esc_attr($redirect) . '"/>';
	                }
	                ?>
	            </form>
	        </body>
	    </html>
		<?php
    }

    public function duo_get_roles(){
        global $wp_roles;
        // $wp_roles may not be initially set if wordpress < 3.3
        $wp_roles = isset($wp_roles) ? $wp_roles : new WP_Roles();
        return $wp_roles;
    }

    public function duo_auth_enabled(){
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) { 
            return false; //allows the XML-RPC protocol for remote publishing
        }

        if (duo_get_option('duo_ikey', '') == '' || duo_get_option('duo_skey', '') == '' ||
            duo_get_option('duo_host', '') == '') {
            return false;
        }
        return true;
    }

    public function duo_role_require_mfa($user){
        $wp_roles = duo_get_roles();
        $all_roles = array();
        foreach ($wp_roles->get_names() as $k=>$r) {
            $all_roles[$k] = $r;
        }

        $duo_roles = duo_get_option('duo_roles', $all_roles); 

        /*
         * WordPress < 3.3 does not include the roles by default
         * Create a User object to get roles info
         * Don't use get_user_by()
         */
        if (!isset($user->roles)){
            $user = new WP_User(0, $user->user_login);
        }

        /*
         * Mainly a workaround for multisite login:
         * if a user logs in to a site different from the one 
         * they are a member of, login will work however
         * it appears as if the user has no roles during authentication
         * "fail closed" in this case and require duo auth
         */
        if(empty($user->roles)) {
            return true;
        }

        foreach ($user->roles as $role) {
            if (array_key_exists($role, $duo_roles)) {
                return true;
            }
        }
        return false;
    }

    public function duo_start_second_factor($user, $redirect_to=NULL){
        if ( !$redirect_to ) {
            // Some custom themes do not provide the redirect_to value
            // Admin page is a good default
            $redirect_to = isset( $_POST['redirect_to'] ) ? $_POST['redirect_to'] : admin_url();
        }

        wp_logout();
        duo_sign_request($user, $redirect_to);
        exit();
    }
    
    public function duo_authenticate_user($user="", $username="", $password="") {
        // play nicely with other plugins if they have higher priority than us
        if ( is_a( $user, 'WP_User') ) {
            return $user;
        }

        if ( !duo_auth_enabled() ){
            return;
        }

        if ( isset( $_POST['sig_response'] ) ) {
            // secondary auth
            remove_action('authenticate', 'wp_authenticate_username_password', 20);
            $akey = duo_get_akey();

            $duo_time = duo_get_time();
            $username = Duo::verifyResponse(duo_get_option('duo_ikey'),
                                            duo_get_option('duo_skey'),
                                            $akey,
                                            $_POST['sig_response'],
                                            $duo_time);
            if ($username) {
                // Don't use get_user_by(). It doesn't return a WP_User object if wordpress version < 3.3
                $user = new WP_User(0, $username);
                duo_set_cookie($user);

                return $user;
            } else {
                return new WP_Error('Duo authentication_failed', __('<strong>Error</strong>: Failed or expired two factor authentication.'));
            }
        }

        if (strlen($username) > 0) {
            // primary auth
            // Don't use get_user_by(). It doesn't return a WP_User object if wordpress version < 3.3
            $user = new WP_User(0, $username);
            if (!$user) {
                error_log("Failed to retrieve WP user $username");
                return;
            }

            if(!duo_role_require_mfa($user)){
                return;
            }

            remove_action('authenticate', 'wp_authenticate_username_password', 20);
            $user = wp_authenticate_username_password(NULL, $username, $password);

            if (!is_a($user, 'WP_User')) {
                // on error, return said error (and skip the remaining plugin chain)
                return $user;
            } else {
                duo_start_second_factor($user);
            }
        }
    }

    public function duo_settings_page() {
		?>
	    <div class="wrap">
	        <h2>Duo Two-Factor Authentication</h2>
	        <?php if(is_multisite()) { ?>
	            <form action="ms-options.php" method="post">
	        <?php } else { ?>
	            <form action="options.php" method="post"> 
	        <?php } ?>
	            <?php settings_fields('duo_settings'); ?>
	            <?php do_settings_sections('duo_settings'); ?> 
	            <p class="submit">
	                <input name="Submit" type="submit" class="button primary-button" value="<?php esc_attr_e('Save Changes'); ?>" />
	            </p>
	        </form>
	    </div>
		<?php
    }

    public function duo_settings_ikey() {
        $ikey = esc_attr(duo_get_option('duo_ikey'));
        echo "<input id='duo_ikey' name='duo_ikey' size='40' type='text' value='$ikey' />";
    }

    public function duo_settings_skey() {
        $skey = esc_attr(duo_get_option('duo_skey'));
        echo "<input id='duo_skey' name='duo_skey' size='40' type='password' value='$skey' autocomplete='off' />";
    }

    public function duo_settings_host() {
        $host = esc_attr(duo_get_option('duo_host'));
        echo "<input id='duo_host' name='duo_host' size='40' type='text' value='$host' />";
    }

    public function duo_settings_roles() {
        $wp_roles = duo_get_roles();
        $roles = $wp_roles->get_names();
        $newroles = array();
        foreach($roles as $key=>$role) {
            $newroles[before_last_bar($key)] = before_last_bar($role);
        }

        $selected = duo_get_option('duo_roles', $newroles);

        foreach ($wp_roles->get_names() as $key=>$role) {
            //create checkbox for each role
			?>
            <input id="duo_roles" name='duo_roles[<?php echo $key; ?>]' type='checkbox' value='<?php echo $role; ?>'  <?php if(in_array($role, $selected)) echo 'checked'; ?> /> <?php echo $role; ?> <br />
			<?php
        }
    }

    public function duo_roles_validate($options) {
        //return empty array
        if (!is_array($options) || empty($options) || (false === $options)) {
            return array();
        }

        $wp_roles = duo_get_roles();
        $valid_roles = $wp_roles->get_names();

        //otherwise validate each role and then return the array
        foreach ($options as $opt) {
            if (!in_array($opt, $valid_roles)) {
                unset($options[$opt]);
            }
        }
        return $options;
    }

    public function duo_settings_text() {
        echo "<p>See the <a target='_blank' href='https://www.duosecurity.com/docs/wordpress'>Duo for WordPress guide</a> to enable Duo two-factor authentication for your WordPress logins.</p>";
        echo '<p>You can retrieve your integration key, secret key, and API hostname by logging in to the Duo administrative interface.</p>';
        echo '<p>Note: After enabling the plugin, you will be immediately prompted for second factor authentication.</p>';
    }

    public function duo_ikey_validate($ikey) {
        if (strlen($ikey) != 20) {
            add_settings_error('duo_ikey', '', 'Integration key is not valid');
            return "";
        } else {
            return $ikey;
        }
    }
    
    public function duo_skey_validate($skey){
        if (strlen($skey) != 40) {
            add_settings_error('duo_skey', '', 'Secret key is not valid');
            return "";
        } else {
            return $skey;
        }
    }

    public function duo_settings_xmlrpc() {
        $val = '';
        if(duo_get_option('duo_xmlrpc', 'off') == 'off') {
            $val = "checked";
        }
        echo "<input id='duo_xmlrpc' name='duo_xmlrpc' type='checkbox' value='off' $val /> Yes<br />";
        echo "Using XML-RPC bypasses two-factor authentication and makes your website less secure. We recommend only using the WordPress web interface for managing your WordPress website.";
    }

    public function duo_xmlrpc_validate($option) {
        if($option == 'off') {
            return $option;
        }
        return 'on';
    }


    public function duo_add_site_option($option, $value = '') {
        // Add multisite option only if it doesn't exist already
        // With Wordpress versions < 3.3, calling add_site_option will override old values
        if (duo_get_option($option) === FALSE){
            add_site_option($option, $value);
        }
    }

    public function duo_admin_init() {
        if (is_multisite()) {
            $wp_roles = duo_get_roles();
            $roles = $wp_roles->get_names();
            $allroles = array();
            foreach($roles as $key=>$role) {
                $allroles[before_last_bar($key)] = before_last_bar($role);
            }
            
            duo_add_site_option('duo_ikey', '');
            duo_add_site_option('duo_skey', '');
            duo_add_site_option('duo_host', '');
            duo_add_site_option('duo_roles', $allroles);
            duo_add_site_option('duo_xmlrpc', 'off');
        }
        else {
            add_settings_section('duo_settings', 'Main Settings', 'duo_settings_text', 'duo_settings');
            add_settings_field('duo_ikey', 'Integration key', 'duo_settings_ikey', 'duo_settings', 'duo_settings');
            add_settings_field('duo_skey', 'Secret key', 'duo_settings_skey', 'duo_settings', 'duo_settings');
            add_settings_field('duo_host', 'API hostname', 'duo_settings_host', 'duo_settings', 'duo_settings');
            add_settings_field('duo_roles', 'Enable for roles:', 'duo_settings_roles', 'duo_settings', 'duo_settings');
            add_settings_field('duo_xmlrpc', 'Disable XML-RPC (recommended)', 'duo_settings_xmlrpc', 'duo_settings', 'duo_settings');
            register_setting('duo_settings', 'duo_ikey', 'duo_ikey_validate');
            register_setting('duo_settings', 'duo_skey', 'duo_skey_validate');
            register_setting('duo_settings', 'duo_host');
            register_setting('duo_settings', 'duo_roles', 'duo_roles_validate');
            register_setting('duo_settings', 'duo_xmlrpc', 'duo_xmlrpc_validate');
        }
    }

    public function duo_set_cookie( $user ) {
        $ikey_b64 = base64_encode(duo_get_option('duo_ikey'));
        $username_b64 = base64_encode($user->user_login);
        $expire = strtotime('+48 hours');

        //Create http cookie
        $val = base64_encode(sprintf("%s|%s|%s|%s", $auth_token, $username_b64, $ikey_b64, $expire)); 
        $sig = duo_hash_hmac($val);
        $cookie = sprintf("%s|%s", $val, $sig);

        setcookie($auth_token, $cookie, 0, COOKIEPATH, COOKIE_DOMAIN, false, true);

        if (COOKIEPATH != SITECOOKIEPATH){
            setcookie($auth_token, $cookie, 0, SITECOOKIEPATH, COOKIE_DOMAIN, false, true);
        }

        if (is_ssl()){
            //Create https cookie
            $sec_val = base64_encode(sprintf("%s|%s|%s|%s", $auth_key, $username_b64, $ikey_b64, $expire)); 
            $sec_sig = duo_hash_hmac($sec_val);
            $sec_cookie = sprintf("%s|%s", $sec_val, $sec_sig);
            setcookie($auth_key, $sec_cookie, 0, COOKIEPATH, COOKIE_DOMAIN, true, true);

            if (COOKIEPATH != SITECOOKIEPATH){
                setcookie($auth_key, $sec_cookie, 0, SITECOOKIEPATH, COOKIE_DOMAIN, true, true);
            }
        }
    }

    public function duo_unset_cookie() {
        setcookie($auth_token, '', strtotime('-1 day'), COOKIEPATH, COOKIE_DOMAIN);
        setcookie($auth_token, '', strtotime('-1 day'), SITECOOKIEPATH, COOKIE_DOMAIN);
        setcookie($auth_key, '', strtotime('-1 day'), COOKIEPATH, COOKIE_DOMAIN);
        setcookie($auth_key, '', strtotime('-1 day'), SITECOOKIEPATH, COOKIE_DOMAIN);
    }

    public function duo_verify_sig($cookie, $u_sig){
        $sig = duo_hash_hmac($cookie);
        if (duo_hash_hmac($sig) === duo_hash_hmac($u_sig)) {
            return true;
        }
        return false;
    }

	public function duo_verify_cookie($user){
	    /*
	        Return true if Duo cookie is valid, false otherwise
	        If using SSL, or secure cookie is set, only accept secure cookie
	    */
        if (is_ssl() || isset($_COOKIE[$auth_key])){
            $duo_auth_cookie_name = $auth_key;
        }
        else {
            $duo_auth_cookie_name = $auth_token;
        }

        if(!isset($_COOKIE[$duo_auth_cookie_name])){
            error_log("Duo cookie with name: $duo_auth_cookie_name not found. Start two factor authentication. SSL: " . is_ssl());
            return false;
        }

        $cookie_list = explode('|', $_COOKIE[$duo_auth_cookie_name]);
        if (count($cookie_list) !== 2){
            error_log('Invalid Duo cookie');
            return false;
        }
        list($u_cookie_b64, $u_sig) = $cookie_list;
        if (!duo_verify_sig($u_cookie_b64, $u_sig)){
            error_log('Duo cookie signature mismatch');
            return false;
        }

        $cookie_content = explode('|', base64_decode($u_cookie_b64));
        if (count($cookie_content) !== 4){
            error_log('Invalid field count in Duo cookie');
            return false;
        }
        list($cookie_name, $cookie_username_b64, $cookie_ikey_b64, $expire) = $cookie_content;
        // Check cookie values
        if ($cookie_name !== $duo_auth_cookie_name ||
            base64_decode($cookie_username_b64) !== $user->user_login ||
            base64_decode($cookie_ikey_b64) !== duo_get_option('duo_ikey')){
            error_log('Invalid Duo cookie content');
            return false;
        }

        $expire = intval($expire);
        if ($expire < strtotime('now')){
            error_log('Duo cookie expired');
            return false;
        }
        return true;
    }

    public function duo_get_uri(){
        // Workaround for IIS which may not set REQUEST_URI, or QUERY parameters
        if (!isset($_SERVER['REQUEST_URI']) ||
            (!empty($_SERVER['QUERY_STRING']) && !strpos($_SERVER['REQUEST_URI'], '?', 0))) {
            $current_uri = substr($_SERVER['PHP_SELF'],1);
            if (isset($_SERVER['QUERY_STRING']) AND $_SERVER['QUERY_STRING'] != '') {
                $current_uri .= '?'.$_SERVER['QUERY_STRING'];
            }
            return $current_uri;
        }
        else {
            return $_SERVER['REQUEST_URI'];
        }
    }

    public function duo_verify_auth(){
	    /*
	        Verify the user is authenticated with Duo. Start 2FA otherwise
	    */
        if (! duo_auth_enabled()){
            if (is_multisite()) {
                $site_info = get_current_site();
            }
            else {
            }
            return;
        }

        if(is_user_logged_in()){
            $user = wp_get_current_user();
            if (duo_role_require_mfa($user) and !duo_verify_cookie($user)){
                duo_start_second_factor($user, duo_get_uri());
            }
        }
    }

    public function duo_get_akey(){
        // Get an application specific secret key.
        // If wp_salt() is not long enough, append a random secret to it
        $akey = duo_get_option('duo_akey', '');
        $akey .= wp_salt();
        if (strlen($akey) < 40) {
            $akey = wp_generate_password(40, true, true);
            update_site_option('duo_akey', $akey);
            $akey .= wp_salt();
        }
        return $akey;
    }

    public function duo_hash_hmac($data){
        return hash_hmac('sha1', $data, duo_get_akey());
    }

    public function duo_sign_ping($host, $date=NULL) {
        if (! $date) {
            $date = date('r');
        }
        $canon = array($date, 'GET', $host, self::PING_ENDPOINT, '');
        $canon = implode("\n", $canon);
        $sig = hash_hmac('sha1', $canon, duo_get_option('duo_skey'));
        return array(
                'Authorization: Basic ' . base64_encode(duo_get_option('duo_ikey') . ':' . $sig),
                'Date: ' . $date,
                'Host: ' . $host,
        );
    }

	private static function sign_vals($key, $vals, $prefix, $expire, $time=NULL) { 
		$exp = ($time ? $time : time()) + $expire;
		$val = $vals . '|' . $exp;
		$b64 = base64_encode($val);
		$cookie = $prefix . '|' . $b64;

		$sig = hash_hmac("sha1", $cookie, $key);
		return $cookie . '|' . $sig;
	}

	private static function parse_vals($key, $val, $prefix, $ikey, $time=NULL) {
		$ts = ($time ? $time : time());

		$parts = explode('|', $val);
		if (count($parts) !== 3) {
			return null;
		}

		list($u_prefix, $u_b64, $u_sig) = $parts;
		$sig = hash_hmac("sha1", $u_prefix . '|' . $u_b64, $key);

		if (hash_hmac("sha1", $sig, $key) !== hash_hmac("sha1", $u_sig, $key)) {
			return null;
		}

		if ($u_prefix !== $prefix) {
			return null;
		}

		$cookie_parts = explode('|', base64_decode($u_b64));

		if (count($cookie_parts) !== 3) {
			return null;
		}

		list($user, $u_ikey, $exp) = $cookie_parts;

		if ($u_ikey !== $ikey) {
			return null;
		}
		if ($ts >= intval($exp)) {
			return null;
		}

		return $user;
	}

	public static function signRequest($ikey, $skey, $akey, $username, $time=NULL) {
		if ( ( !isset($username) || strlen($username) === 0 ) || ( strpos($username, '|') !== FALSE ) ) {
			return 'The username passed to sign_request() is invalid.';
		}
		if (!isset($ikey) || strlen($ikey) !== self::IKEY_LEN) {
			return 'The Duo integration key passed to sign_request() is invalid.';
		}
		if (!isset($skey) || strlen($skey) !== self::SKEY_LEN) {
			return 'The Duo secret key passed to sign_request() is invalid.';
		}
		if (!isset($akey) || strlen($akey) < self::AKEY_LEN) {
			return 'The application secret key passed to sign_request() must be at least ' . self::AKEY_LEN . ' characters.';
		}

		$vals = $username . '|' . $ikey;
		$duo_sig = self::sign_vals($skey, $vals, 'TX', self::DUO_EXPIRE, $time);
		$app_sig = self::sign_vals($akey, $vals, 'APP', self::APP_EXPIRE, $time);

		return $duo_sig . ':' . $app_sig;
	}

	public static function verifyResponse($ikey, $skey, $akey, $sig_response, $time=NULL) {
		list($auth_sig, $app_sig) = explode(':', $sig_response);

		$auth_user = self::parse_vals($skey, $auth_sig, 'AUTH', $ikey, $time);
		$app_user = self::parse_vals($akey, $app_sig, 'APP', $ikey, $time);

		if ($auth_user !== $app_user) {
			return null;
		}
		return $auth_user;
	}
}
