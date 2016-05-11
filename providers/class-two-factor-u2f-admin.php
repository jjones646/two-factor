<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class for registering & modifying FIDO U2F security keys.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Two_Factor_U2F_Admin {

	/**
	 * The user meta register data.
	 *
	 * @type string
	 */
	const REGISTER_DATA_USER_META_KEY = 'two_factor-u2f_register_request';

	/**
	 * Add various hooks.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function add_hooks( $provider = null ) {
		add_action( 'admin_enqueue_scripts',       array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'personal_options_update',     array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'edit_user_profile_update',    array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'load-profile.php',            array( __CLASS__, 'catch_delete_security_key' ) );
		add_action( 'load-user-edit.php',          array( __CLASS__, 'catch_delete_security_key' ) );
		add_action( 'wp_ajax_inline-save-key',     array( __CLASS__, 'wp_ajax_inline_save' ) );

		if ( ! empty( $provider ) ) {
			add_action( 'two_factor_user_option-' . $provider,		array( __CLASS__, 'print_user_options' ) );
			// add_action( 'two_factor_user_option_details-' . $provider, 	array( __CLASS__, 'print_user_option_details' ) );
		}
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param string $hook Current page.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'user-edit.php', 'profile.php' ) ) ) {
			return;
		}

		wp_enqueue_script( 'u2f-api', plugins_url( 'includes/u2f/u2f-api.js',  __FILE__ ), null, null, true );

		$user_id = get_current_user_id();
		$security_keys = Two_Factor_U2F::get_security_keys( $user_id );

		try {
			list( $req, $sigs ) = Two_Factor_U2F::$u2f->getRegisterData( $security_keys );

			update_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY, $req );

		} catch ( Exception $e ) {
			return false;
		}

		// register the script
		wp_register_script( 'two_factor-u2f_dev_mgmt-js', plugins_url( 'js/u2f-dev-mgmt.js', __FILE__ ), array( 'jquery', 'u2f-api' ), null, true );
		// localize the script with our data
		$translation_array = array(
			'register' => array(
				'request' => $req,
				'sigs' => $sigs,
			),
			'text' => array(
				'insert' => esc_html__( __( 'Insert and/or tap your Security Key.', 'two_factor' ) ),
				'error' => esc_html__( 'Registration failure.', 'two-factor' ),
			),
		);
		wp_localize_script( 'two_factor-u2f_dev_mgmt-js', 'u2fL10n', $translation_array );
		// enqueued script with localized data
		wp_enqueue_script( 'two_factor-u2f_dev_mgmt-js' );

		// localize the next script
		wp_localize_script( 'two_factor-u2f_row_mgmt', 'u2fRowMgmt', array(
			'error' => esc_html__( 'Error while saving the changes.' ),
		) );
	}

	/**
	 * Display the security key section in a users profile.
	 *
	 * @since 0.2-dev
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function print_user_options( $user ) {
		if ( ! isset( $user->ID ) ) {
			return false;
		}

		wp_nonce_field( "user_security_keys-{$user->ID}", '_nonce_user_security_keys' );
		$new_key = false;

		$security_keys = Two_Factor_U2F::get_security_keys( $user->ID );
		$num_keys = count( $security_keys );

		if ( $security_keys ) {
			foreach ( $security_keys as &$security_key ) {
				if ( property_exists( $security_key, 'new' ) ) {
					$new_key = true;
					unset( $security_key->new );

					// If we've got a new one, update the db record to not save it there any longer.
					Two_Factor_U2F::update_security_key( $user->ID, $security_key );
				}
			}
			unset( $security_key );
		}

		if ( ! empty( $security_keys ) ) {
			require( TWO_FACTOR_DIR . 'providers/class-two-factor-u2f-user-list-table.php' );
			$u2f_list_table = new Two_Factor_U2F_User_List_Table();
			$u2f_list_table->items = $security_keys;
			$u2f_list_table->prepare_items();
			$u2f_list_table->display();
			$u2f_list_table->inline_edit();
		}

		?>
		<div class="register-security-key">
			<?php if ( ! is_ssl() ) : ?>
				<?php if ( ! empty( $security_keys ) ) : ?>
					<button type="button" class="button button-secondary two-factor-u2f two-factor-toggle"><?php esc_html_e( 'View Keys' ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( Two_Factor_U2F::is_browser_support() ) : ?>
					<input type="hidden" name="do_new_security_key" id="do_new_security_key">
					<input type="hidden" name="u2f_response" id="u2f_response">
					
						<button type="button" class="button button-secondary two-factor-u2f two-factor-register"><?php esc_html_e( 'Add Key' ); ?></button>
					
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'You are using an unsupported browser. Security Keys are only supported in Chrome 41+. ' ); ?><a href="https://support.google.com/accounts/answer/6103523"><?php esc_html_e( 'More Information' ); ?></a></p>
					<p><button type="button" class="button button-secondary two-factor-u2f two-factor-toggle"><?php esc_html_e( 'View Keys' ); ?></button></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Catch the non-ajax submission from the new form.
	 *
	 * This executes during the `personal_options_update` & `edit_user_profile_update` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int $user_id User ID.
	 */
	public static function catch_submission( $user_id ) {
		if ( ! empty( $_REQUEST['do_new_security_key'] ) ) {
			check_admin_referer( "user_security_keys-{$user_id}", '_nonce_user_security_keys' );

			try {
				$response = json_decode( stripslashes( $_POST['u2f_response'] ) );
				$reg = Two_Factor_U2F::$u2f->doRegister( get_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY, true ), $response );
				$reg->new = true;

				Two_Factor_U2F::add_security_key( $user_id, $reg );
			} catch ( Exception $e ) {
				return false;
			}

			delete_user_meta( $user_id, self::REGISTER_DATA_USER_META_KEY );

			wp_safe_redirect( add_query_arg( array(
				'new_app_pass' => 1,
			), wp_get_referer() ) . '#security-keys-section' );
			exit;
		}
	}

	/**
	 * Catch the delete security key request.
	 *
	 * This executes during the `load-profile.php` & `load-user-edit.php` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function catch_delete_security_key() {
		$user_id = get_current_user_id();
		if ( ! empty( $_REQUEST['delete_security_key'] ) ) {
			$slug = $_REQUEST['delete_security_key'];
			check_admin_referer( "delete_security_key-{$slug}", '_nonce_delete_security_key' );

			Two_Factor_U2F::delete_security_key( $user_id, $slug );

			wp_safe_redirect( remove_query_arg( 'new_app_pass', wp_get_referer() ) . '#security-keys-section' );
		}
	}

	/**
	 * Generate a link to rename a specified security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function rename_link( $item ) {
		return sprintf( '<a href="#" class="two-factor-edit-inline">%s</a>', esc_html__( 'Rename' ) );
	}

	/**
	 * Generate a link to delete a specified security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function delete_link( $item ) {
		$delete_link = add_query_arg( 'delete_security_key', $item->keyHandle );
		$delete_link = wp_nonce_url( $delete_link, "delete_security_key-{$item->keyHandle}", '_nonce_delete_security_key' );
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $delete_link ), esc_html__( 'Delete' ) );
	}

	/**
	 * Ajax handler for quick edit saving for a security key.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function wp_ajax_inline_save() {
		check_ajax_referer( 'keyinlineeditnonce', '_inline_edit' );

		require( TWO_FACTOR_DIR . 'providers/class-two-factor-fido-u2f-user-list-table.php' );
		$wp_list_table = new Two_Factor_U2F_User_List_Table();

		if ( ! isset( $_POST['keyHandle'] ) ) {
			wp_die();
		}

		$user_id = get_current_user_id();

		$security_keys = Two_Factor_U2F::get_security_keys( $user_id );
		if ( ! $security_keys ) {
			wp_die();
		}

		foreach ( $security_keys as &$key ) {
			if ( $key->keyHandle === $_POST['keyHandle'] ) {
				break;
			}
		}

		$key->name = $_POST['name'];

		$updated = Two_Factor_U2F::update_security_key( $user_id, $key );
		if ( ! $updated ) {
			wp_die( esc_html__( 'Item not updated.' ) );
		}
		$wp_list_table->single_row( $key );
		wp_die();
	}
}
