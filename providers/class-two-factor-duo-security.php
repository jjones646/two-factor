<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

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

	const DEFAULT_KEY_BIT_SIZE = 160;
	const DEFAULT_CRYPTO = 'sha1';
	const DEFAULT_DIGIT_COUNT = 6;
	const DEFAULT_TIME_STEP_SEC = 30;
	const DEFAULT_TIME_STEP_ALLOWANCE = 4;
	private static $_base_32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

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

		wp_enqueue_script( 'totp-options', plugins_url( 'js/totp-options.js', __FILE__ ), array( 'jquery' ), null, true );
	}

	/**
	 * Returns the name of the provider.
	 * 
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Authenticator App', 'Provider Label' );
	}

	/**
	 * Returns a short description about the authentication method.
	 *
	 * @since 0.2-dev
	 */
	public function get_description() {
		return _x( 'Use an Authentication App on your phone that will generate time-synchronized codes for your account.', 'Two-Factor Authentication Method Description' );
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

		wp_nonce_field( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options', false );
		$key = get_user_meta( $user->ID, self::SECRET_META_KEY, true );

		if ( empty( $key ) ) {
			$key = $this->generate_key();
			$site_name = get_bloginfo( 'name', 'display' );
			?>
			<p><button type="button" class="button button-secondary two-factor-totp two-factor-register"><?php esc_html_e( 'Setup App' ); ?></button></p>
			<div id="two-factor-totp-options" class="two-factor-register two-factor-totp hide-if-js">
				<img src="<?php echo esc_url( $this->get_google_qr_code( $site_name . ':' . $user->user_login, $key, $site_name ) ); ?>" id="two-factor-totp-qrcode" />
				<p><strong><?php echo esc_html( $key ); ?></strong></p>
				<p><?php esc_html_e( 'Scan the QR code or use the provided key. Optionally, you can give an authentication code from your app.' ); ?></p>
				<p>
					<label for="two-factor-totp-authcode"><?php esc_html_e( 'Authentication Code:' ); ?></label>
					<input type="hidden" name="two-factor-totp-key" value="<?php echo esc_attr( $key ) ?>" />
					<input type="tel" name="two-factor-totp-authcode" id="two-factor-totp-authcode" class="input" value="" size="20" pattern="[0-9]*" />
				</p>
			</div>
			<?php
		} else {
			?>
			<p><button type="button" class="button button-secondary two-factor-totp two-factor-unregister"><?php esc_html_e( 'Delete App Key' ); ?></button></p>
			<?php
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

		$site_name = get_bloginfo( 'name', 'display' );
		$totp_acct = esc_html__( $site_name . ':' . $user->user_login );

		$message = sprintf( __( '%s' ), $this->get_label() ) . ' is ';

		if ( empty( $key ) ) {
			$message .= 'disabled';
		} else {
			$message .= 'enabled';
		}

		$message = $totp_acct;
		_e( sprintf( '<div class="%1$s"><p><strong>Account Tag:</strong> %2$s</p></div>', 'two-factor-details', $message ) );
	}

	/**
	 * Save the options specified in `::print_user_options()`
	 * 
	 * @since 0.1-dev
	 *
	 * @param int $user_id The user ID whose options are being updated.
	 */
	public function user_options_update( $user_id ) {
		if ( isset( $_POST['_nonce_user_two_factor_totp_options'] ) ) {
			check_admin_referer( 'user_two_factor_totp_options', '_nonce_user_two_factor_totp_options' );

			$current_key = get_user_meta( $user_id, self::SECRET_META_KEY, true );
			// If the key hasn't changed or is invalid, do nothing.
			if ( ! isset( $_POST['two-factor-totp-key'] ) || $current_key === $_POST['two-factor-totp-key'] || ! preg_match( '/^[' . self::$_base_32_chars . ']+$/', $_POST['two-factor-totp-key'] ) ) {
				return false;
			}

			$notices = array();

			if ( ! empty( $_POST['two-factor-totp-authcode'] ) ) {
				if ( $this->is_valid_authcode( $_POST['two-factor-totp-key'], $_POST['two-factor-totp-authcode'] ) ) {
					if ( ! update_user_meta( $user_id, self::SECRET_META_KEY, $_POST['two-factor-totp-key'] ) ) {
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

	/**
	 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift
	 *
	 * @param string $key      The share secret key to use.
	 * @param string $authcode The code to test.
	 *
	 * @return bool Whether the code is valid within the time frame
	 */
	public static function is_valid_authcode( $key, $authcode ) {
		/**
		 * Filter the maximum ticks to allow when checking valid codes.
		 *
		 * Ticks are the allowed offset from the correct time in 30 second increments,
		 * so the default of 4 allows codes that are two minutes to either side of server time
		 *
		 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
		 */
		$max_ticks = apply_filters( 'two-factor-totp-time-step-allowance', self::DEFAULT_TIME_STEP_ALLOWANCE );

		// Array of all ticks to allow, sorted using absolute value to test closest match first.
		$ticks = range( - $max_ticks, $max_ticks );
		usort( $ticks, array( __CLASS__, 'abssort' ) );

		$time = time() / self::DEFAULT_TIME_STEP_SEC;

		foreach ( $ticks as $offset ) {
			$log_time = $time + $offset;
			if ( self::calc_totp( $key, $log_time ) === $authcode ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates key
	 *
	 * @param int $bitsize Nume of bits to use for key.
	 *
	 * @return string $bitsize long string composed of available base32 chars.
	 */
	public static function generate_key( $bitsize = self::DEFAULT_KEY_BIT_SIZE ) {
		$bytes = ceil( $bitsize / 8 );
		$secret = wp_generate_password( $bytes, true, true );

		return self::base32_encode( $secret );
	}

	/**
	 * Pack stuff
	 *
	 * @param string $value The value to be packed.
	 *
	 * @return string Binary packed string.
	 */
	public static function pack64( $value ) {
		// 64bit mode (PHP_INT_SIZE == 8).
		if ( PHP_INT_SIZE >= 8 ) {
			// If we're on PHP 5.6.3+ we can use the new 64bit pack functionality.
			if ( version_compare( PHP_VERSION, '5.6.3', '>=' ) && PHP_INT_SIZE >= 8 ) {
				return pack( 'J', $value );
			}
			$highmap = 0xffffffff << 32;
			$higher  = ( $value & $highmap ) >> 32;
		} else {
			/*
			 * 32bit PHP can't shift 32 bits like that, so we have to assume 0 for the higher
			 * and not pack anything beyond it's limits.
			 */
			$higher = 0;
		}

		$lowmap  = 0xffffffff;
		$lower   = $value & $lowmap;

		return pack( 'NN', $higher, $lower );
	}

	/**
	 * Calculate a valid code given the shared secret key
	 *
	 * @param string $key        The shared secret key to use for calculating code.
	 * @param mixed  $step_count The time step used to calculate the code, which is the floor of time() divided by step size.
	 * @param int    $digits     The number of digits in the returned code.
	 * @param string $hash       The hash used to calculate the code.
	 * @param int    $time_step  The size of the time step.
	 *
	 * @return string The totp code
	 */
	public static function calc_totp( $key, $step_count = false, $digits = self::DEFAULT_DIGIT_COUNT, $hash = self::DEFAULT_CRYPTO, $time_step = self::DEFAULT_TIME_STEP_SEC ) {
		$secret = self::base32_decode( $key );

		if ( false === $step_count ) {
			$step_count = floor( time() / $time_step );
		}

		$timestamp = self::pack64( $step_count );

		$hash = hash_hmac( $hash, $timestamp, $secret, true );

		$offset = ord( $hash[19] ) & 0xf;

		$code = (
				( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
				( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
				( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
				( ord( $hash[ $offset + 3 ] ) & 0xff )
			) % pow( 10, $digits );

		return str_pad( $code, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Uses the Google Charts API to build a QR Code for use with an otpauth url
	 *
	 * @param string $name  The name to display in the Authentication app.
	 * @param string $key   The secret key to share with the Authentication app.
	 * @param string $title The title to display in the Authentication app.
	 *
	 * @return string A URL to use as an img src to display the QR code
	 */
	public static function get_google_qr_code( $name, $key, $title = null ) {
		// Pre-encode spaces because iOS chokes on them.
		$name = str_replace( ' ', '%20', $name );
		$google_url = urlencode( 'otpauth://totp/' . $name . '?secret=' . $key );
		if ( isset( $title ) ) {
			$google_url .= urlencode( '&issuer=' . rawurlencode( $title ) );
		}
		return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . $google_url;
	}

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
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );
		?>
		<p>
			<label for="authcode"><?php esc_html_e( 'Authentication Code:' ); ?></label>
			<input type="tel" name="authcode" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
		</p>
		<script type="text/javascript">
			setTimeout( function(){
				var d;
				try{
					d = document.getElementById('authcode');
					d.value = '';
					d.focus();
				} catch(e){}
			}, 200);
		</script>
		<?php
		submit_button( __( 'Authenticate' ) );
	}

	/**
	 * Returns a base32 encoded string.
	 *
	 * @param string $string String to be encoded using base32.
	 *
	 * @return string base32 encoded string without padding.
	 */
	public static function base32_encode( $string ) {
		if ( empty( $string ) ) {
			return '';
		}

		$binary_string = '';

		foreach ( str_split( $string ) as $character ) {
			$binary_string .= str_pad( base_convert( ord( $character ), 10, 2 ), 8, '0', STR_PAD_LEFT );
		}

		$five_bit_sections = str_split( $binary_string, 5 );
		$base32_string = '';

		foreach ( $five_bit_sections as $five_bit_section ) {
			$base32_string .= self::$_base_32_chars[ base_convert( str_pad( $five_bit_section, 5, '0' ), 2, 10 ) ];
		}

		return $base32_string;
	}

	/**
	 * Decode a base32 string and return a binary representation
	 *
	 * @param string $base32_string The base 32 string to decode.
	 *
	 * @throws Exception If string contains non-base32 characters.
	 *
	 * @return string Binary representation of decoded string
	 */
	public static function base32_decode( $base32_string ) {

		$base32_string 	= strtoupper( $base32_string );

		if ( ! preg_match( '/^[' . self::$_base_32_chars . ']+$/', $base32_string, $match ) ) {
			throw new Exception( 'Invalid characters in the base32 string.' );
		}

		$l 	= strlen( $base32_string );
		$n	= 0;
		$j	= 0;
		$binary = '';

		for ( $i = 0; $i < $l; $i++ ) {

			$n = $n << 5; // Move buffer left by 5 to make room.
			$n = $n + strpos( self::$_base_32_chars, $base32_string[ $i ] ); 	// Add value into buffer.
			$j += 5; // Keep track of number of bits in buffer.

			if ( $j >= 8 ) {
				$j -= 8;
				$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
			}
		}

		return $binary;
	}

	/**
	 * Used with usort to sort an array by distance from 0
	 *
	 * @param int $a First array element.
	 * @param int $b Second array element.
	 *
	 * @return int -1, 0, or 1 as needed by usort
	 */
	private static function abssort( $a, $b ) {
		$a = abs( $a );
		$b = abs( $b );
		if ( $a === $b ) {
			return 0;
		}
		return ($a < $b) ? -1 : 1;
	}
}
