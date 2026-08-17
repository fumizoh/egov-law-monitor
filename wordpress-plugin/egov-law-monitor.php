<?php
/**
 * Plugin Name: eGov Law Monitor
 * Description: eGov Law MonitorのWordPress連携機能。
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'wp_enqueue_scripts',
    function () {
        $css_path = plugin_dir_path( __FILE__ )
            . 'assets/css/egov-law-post.css';

        $css_url = plugin_dir_url( __FILE__ )
            . 'assets/css/egov-law-post.css';

        wp_enqueue_style(
            'egov-law-post',
            $css_url,
            array(),
            filemtime( $css_path )
        );
    }
);