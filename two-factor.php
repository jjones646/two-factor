<?php
/**
 * Plugin Name: Two-Factor
 * Plugin URI: http://github.com/jjones646/two-factor
 * Description: Two-Factor Authentication all in one place.
 * Author: Jonathan Jones
 * Version: 0.2-dev
 * Author URI: https://github.com/jjones646
 * Network: True
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shortcut constant to the path of this file.
 */
define( 'TWO_FACTOR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Include the base class here, so that other plugins can also extend it.
 */
require_once( TWO_FACTOR_DIR . 'providers/class-two-factor-provider.php' );

/**
 * Include the core that handles the common bits.
 */
require_once( TWO_FACTOR_DIR . 'class-two-factor.php' );
Two_Factor::add_hooks();


