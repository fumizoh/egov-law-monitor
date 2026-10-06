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

define(
    'EGOV_AI_SUMMARY_FREE_LIMIT',
    5
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

        // Law post JS
        $post_js_path = plugin_dir_path( __FILE__ )
            . 'assets/js/law-post.js';

        $post_js_url = plugin_dir_url( __FILE__ )
            . 'assets/js/law-post.js';

        wp_enqueue_script(
            'egov-law-post',
            $post_js_url,
            array(),
            filemtime( $post_js_path ),
            true
        );

        $ai_summary_url = '';

        $pages = get_pages(
            array(
                'post_status' => 'publish',
            )
        );

        foreach ( $pages as $page ) {
            if ( has_shortcode( $page->post_content, 'egov_ai_summary' ) ) {
                $ai_summary_url = get_permalink( $page );
                break;
            }
        }

        $ai_free_limit = EGOV_AI_SUMMARY_FREE_LIMIT;
        $ai_usage_count = is_user_logged_in()
            ? egov_law_monitor_get_ai_summary_usage_count( get_current_user_id() )
            : 0;

        wp_localize_script(
            'egov-law-watch',
            'egovLawMonitor',
            array(
                'dataUrl'   => EGOV_LAW_MONITOR_DATA_URL,
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'restUrl'   => rest_url( 'egov-law-monitor/v1/watches' ),
                'lawSearchUrl' => rest_url( 'egov-law-monitor/v1/law-search' ),
                'aiSummary' => array(
                    'url' => $ai_summary_url,
                    'isLoggedIn' => is_user_logged_in(),
                    'remaining' => max( 0, $ai_free_limit - $ai_usage_count ),
                    'freeLimit' => $ai_free_limit,
                ),
            )
        );

        wp_add_inline_script(
            'egov-law-watch',
            <<<'JS'
(function () {

    function initAiSummaryActions() {

        const settings =
            window.egovLawMonitor &&
            window.egovLawMonitor.aiSummary;

        const buttons =
            document.querySelectorAll(
                '.egov-ai-summary-action'
            );

        if (!settings || !buttons.length) {
            return;
        }

        if (!settings.isLoggedIn) {
            buttons.forEach((button) => {
                button.hidden = true;
            });
            return;
        }

        buttons.forEach((button) => {

            button.addEventListener('click', () => {

                if (!settings.url) {
                    window.alert(
                        'AI要約ページが設定されていません。'
                    );
                    return;
                }

                const remaining =
                    Number(settings.remaining || 0);

                const amendmentName =
                    button.dataset.amendmentName ||
                    'この改正';

                const message =
                    remaining > 0
                        ? 'この改正をAIで要約します。\n\n' +
                          '無料利用の残り：' +
                          remaining +
                          '回\n\n' +
                          amendmentName
                        : '無料利用回数を使い切っています。\n\n' +
                          'この改正のAI要約を利用済みの場合は、\n' +
                          'AI要約ページから再度表示できます。';

                if (!window.confirm(message)) {
                    return;
                }

                const url =
                    new URL(settings.url, window.location.origin);

                url.searchParams.set(
                    'law_id',
                    button.dataset.lawId || ''
                );
                url.searchParams.set(
                    'effective_date',
                    button.dataset.effectiveDate || ''
                );
                url.searchParams.set(
                    'law_data_id',
                    button.dataset.lawDataId || ''
                );
                url.searchParams.set(
                    'sub_revision',
                    button.dataset.subRevision || ''
                );

                window.open(
                    url.toString(),
                    '_blank',
                    'noopener,noreferrer'
                );
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initAiSummaryActions
        );
    } else {
        initAiSummaryActions();
    }

})();
JS
        );
    }
);

require_once plugin_dir_path( __FILE__ ) . 'includes/database.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/permissions.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/law-search.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/law-search-page.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/watch-api.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/internal-api.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-auth.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-page.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-management.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/user-settings.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-users.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-menu.php';


register_activation_hook(
    __FILE__,
    function () {
        egov_law_monitor_create_tables();
        egov_law_monitor_setup_api_user();
    }
);