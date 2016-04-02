<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract class for creating two factor authentication providers.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
abstract class Two_Factor_Provider {

	/**
	 * The priority any derived method. Derived classes must set this in their constructor.
	 *
	 * @type int
	 */
	protected $priority = 100;

	/**
	 * Class constructor.
	 *
	 * @since 0.1-dev
	 */
	protected function __construct() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets_base' ) );

		return $this;
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 0.2-dev
	 *
	 * @param string $hook Current page.
	 */
	public static function enqueue_assets_base( $hook ) {
		if ( ! in_array( $hook, array( 'user-edit.php', 'profile.php' ) ) ) {
			return;
		}

		wp_enqueue_script( 'two-factor-js', plugins_url( 'js/two-factor-provider.js', __FILE__ ), array( 'jquery' ), null, true );
	}

	/**
	 * Returns the priority of the provider type.
	 *
	 * @since 0.2-dev
	 */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Returns the name of the provider.
	 *
	 * @since 0.1-dev
	 *
	 * @return string
	 */
	abstract function get_label();

	/**
	 * Prints the name of the provider.
	 *
	 * @since 0.1-dev
	 */
	public function print_label() {
		echo esc_html( $this->get_label() );
	}

	/**
	 * Returns a short description about the authentication method.
	 *
	 * @since 0.2-dev
	 */
	abstract function get_description();

	/**
	 * Prints the description message for the authentication type.
	 *
	 * @since 0.2-dev
	 */
	public function print_description() {
		echo esc_html( $this->get_description() );
	}

	/**
	 * Prints the form that prompts the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	abstract function authentication_page( $user );

	/**
	 * Validates the users input token.
	 *
	 * @since 0.1-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	abstract function validate_authentication( $user );

	/**
	 * Whether this Two Factor provider is configured and available for the user specified.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @return boolean
	 */
	abstract function is_available_for_user( $user );

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	abstract function print_user_options( $user );

	/**
	 * Inserts markup at the end of the user profile field for this provider.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	abstract function print_user_option_details( $user );

	/**
	 * Generate a random eight-digit string to send out as an auth code.
	 *
	 * @since 0.1-dev
	 *
	 * @param int          $length The code length.
	 * @param string|array $chars Valid auth code characters.
	 * @return string
	 */
	public function get_code( $length = 8, $chars = '1234567890' ) {
		$code = '';
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $chars );
		}
		for ( $i = 0; $i < $length; $i++ ) {
			$key = array_rand( $chars );
			$code .= $chars[ $key ];
		}
		return $code;
	}
}
