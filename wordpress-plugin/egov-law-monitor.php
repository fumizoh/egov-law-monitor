<?php
/**
 * Plugin Name: e-Gov Law Monitor
 * Description: e-Gov Law MonitorのWordPress連携機能。
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define(
    'EGOV_LAW_MONITOR_DATA_URL',
    'https://fumizoh.github.io/egov-law-monitor/'
);

add_action(
    'wp_enqueue_scripts',
    function () {

        // CSS
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

        // JS
        $js_path = plugin_dir_path( __FILE__ )
            . 'assets/js/law-watch.js';

        $js_url = plugin_dir_url( __FILE__ )
            . 'assets/js/law-watch.js';

        wp_enqueue_script(
            'egov-law-watch',
            $js_url,
            array(),
            filemtime( $js_path ),
            true
        );

        wp_localize_script(
            'egov-law-watch',
            'egovLawMonitor',
            array(
                'dataUrl' => EGOV_LAW_MONITOR_DATA_URL,
            )
        );
    }
);

require_once plugin_dir_path( __FILE__ ) . 'includes/database.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/law-search.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/law-search-page.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/watch-api.php';


register_activation_hook(
    __FILE__,
    'egov_law_monitor_create_tables'
);