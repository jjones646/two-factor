<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class for creating an email provider.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_Email extends Two_Factor_Provider {

	/**
	 * The user meta token key.
	 *
	 * @type string
	 */
	const TOKEN_META_KEY = 'two_factor-email_token';

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		$this->priority = 60;

		add_action( 'two_factor_user_option-' . 		__CLASS__, 	array( $this, 'print_user_options' ) );
		add_action( 'two_factor_user_option_details-' .	__CLASS__, 	array( $this, 'print_user_option_details' ) );

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

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function get_label() {
		return _x( 'Email', 'Provider Label', 'two-factor' );
	}

	/**
	 * Returns a short description about the authentication method.
	 *
	 * @since 0.2-dev
	 */
	public function get_description() {
		return _x( 'Receive single-use codes at your account\'s email address.', 'Two-Factor Authentication Method Description', 'two-factor' );
	}

	/**
	 * Delete all active tokens for a user.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function delete_token( $user_id ) {
		return delete_user_meta( $user_id, self::TOKEN_META_KEY );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function authentication_page( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		$this->generate_and_email_token( $user );
		require_once( ABSPATH .  '/wp-admin/includes/template.php' );

		?>
		<p><?php _e( 'A verification code has been sent to <strong>' . sanitize_email($user->user_email) . '</strong>.' ); ?></p>
		<p>
			<label for="authcode"><?php esc_html_e( 'Verification Code:' ); ?></label>
			<input type="tel" name="two_factor-email_code" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
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

		submit_button( __( 'Log In' ) );
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
		if ( ! isset( $user->ID ) || ! isset( $_REQUEST['two_factor-email_code'] ) ) {
			return false;
		}

		return $this->validate_token( $user->ID, $_REQUEST['two_factor-email_code'] );
	}

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	public function is_available_for_user( $user ) {
		// does this user have a valid email address field?
		if ( is_email( $user->user_email ) ) {
			return false;
		}
		return false;
	}

	/**
	 * Inserts any options that extend the method's setup/configurations with user input.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public function print_user_options( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		// Empty on purpose
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

		$message = sprintf( __( 'Single-use codes will be sent to <strong>%1$s</strong>.' ), $user->user_email );

		_e( sprintf( '<p>%1$s</p>', $message ) );
	}

	/**
	 * Displays an admin notice when email is disabled and no other two-factor methods are available.
	 *
	 * @since 0.2-dev
	 */
	public function admin_notices() {
		$user = wp_get_current_user();
		
		// Return if the provider is not enabled.
		if ( ! in_array( __CLASS__, Two_Factor::get_enabled_providers_for_user( $user->ID ) ) ) {
			return;
		}

		$message = sprintf( __( 'Two-factor: You are out of backup codes and need to <a href="%s">generate more.</a>' ), esc_url( get_edit_user_link( $user->ID ) . '#two_factor-backup_codes' ) );

		esc_html_e( sprintf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error is-dismissible', $message ) );
	}

	/**
	 * Generate the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function generate_token( $user_id ) {
		$token = $this->get_code();
		update_user_meta( $user_id, self::TOKEN_META_KEY, wp_hash( $token ) );
		return $token;
	}

	/**
	 * Validate the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param int    $user_id User ID.
	 * @param string $token User token.
	 * @return boolean
	 */
	private function validate_token( $user_id, $token ) {
		$hashed_token = get_user_meta( $user_id, self::TOKEN_META_KEY, true );
		if ( wp_hash( $token ) !== $hashed_token ) {
			$this->delete_token( $user_id );
			return false;
		}
		return true;
	}

	/**
	 * Generate and email the user token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	private function generate_and_email_token( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		$token = $this->generate_token( $user->ID );

		$subject = wp_strip_all_tags( sprintf( __( 'Your login confirmation code for %s' ), get_bloginfo( 'name' ) ) );
		$message = wp_strip_all_tags( sprintf( __( 'Enter %s to log in.' ), $token ) );
		wp_mail( $user->user_email, $subject, $message );
	}
}
