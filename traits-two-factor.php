<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// add_action( 'all', create_function( '', 'var_dump( current_filter() );' ) );

/**
 * Traits that define generic helper functions for two-factor things.
 *
 * @since 0.2-dev
 *
 * @package Two_Factor
 */
trait Two_Factor_Trails {

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

    /**
     * Standardize provider names to a uniform format.
     *
     * @since 0.2-dev
     */
    public static function make_provider_key( $name ) {
        $key = sanitize_key( $name );

        $pos = strpos( $key, 'two_factor_' );
        if ( $pos === 0 ) {
            $key = substr_replace( $key, '', $pos, strlen( 'two_factor_' ) );
        }

        return $key;
    }

    /**
     * Construct a link for the user profile table.
     *
     * @since 0.2-dev
     */
    public static function make_option_link( $title, $label, $action, $url = '#', $prefix_divider = true ) {
        $action_key = str_replace( '-', '_', $action );
        $action_key = self::make_provider_key( $action_key );
        $label_key = self::make_provider_key( $label );
        $link = '';

        if ( $prefix_divider ) {
            $link .= ' | ';
        }
        $link .= '<span class="two-factor-option ' . esc_attr( $action_key ) . '">';
        $link .= '<a href="' . esc_url( $url ) . '" id="two_factor_option-' . esc_attr( $label_key ) . '-' . esc_attr( $action_key ) . '" ';
        $link .= 'aria-label="' . esc_attr( $title ) . '">' . esc_html( $title ) . '</a>';
        $link .= '</span>';

        return $link;
    }


    public static function get_var_dump_recursive( $var ) {
        $ret = '';
        foreach( $var as $key => $val ) {
            $ret .= '<tr>';
            $ret .= '<td><strong>';
            $ret .= $key;
            $ret .= '</strong></td>';
            $ret .= '<td>';
            if ( is_array( $val ) ) {
             $ret .= self::get_var_dump( $val );
            } else {
             $ret .= $val;   
            }
            $ret .= '</td>';
            $ret .= '</tr>';
        }
        return $ret;
    }


    public static function get_var_dump( $val ) {
        $ret = '';
        $ret .= '<table border="1" width="100%" style="border:1px solid black; border-collapse: collapse;">';
        $ret .= self::get_var_dump_recursive( $val );
        $ret .= '</table>';
        return $ret;
    }


    public static function dump_request() {
        wp_die( self::get_var_dump( $_REQUEST ) );
    }
}
