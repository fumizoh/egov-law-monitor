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

        $ai_summary_limit = is_user_logged_in()
            ? egov_law_monitor_get_ai_summary_limit( get_current_user_id() )
            : 0;

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
                    'usageUrl' => rest_url(
                        'egov-law-monitor/v1/ai-summary-usage'
                    ),
                    'restNonce' => wp_create_nonce( 'wp_rest' ),
                    'isLoggedIn' => is_user_logged_in(),
                    'remaining' => max( 0, $ai_summary_limit - $ai_usage_count ),
                    'limit' => $ai_summary_limit,
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

            button.addEventListener('click', async () => {

                if (!settings.url) {
                    window.alert(
                        'AI要約ページが設定されていません。'
                    );
                    return;
                }

                if (!settings.usageUrl) {
                    window.alert(
                        'AI要約の利用状況を取得できません。'
                    );
                    return;
                }

                let usage;

                try {
                    const response =
                        await fetch(
                            settings.usageUrl,
                            {
                                method: 'GET',
                                credentials: 'same-origin',
                                headers: {
                                    'X-WP-Nonce':
                                        settings.restNonce
                                }
                            }
                        );

                    if (!response.ok) {
                        throw new Error(
                            'AI要約の利用状況を取得できませんでした。'
                        );
                    }

                    usage = await response.json();

                } catch (error) {
                    window.alert(
                        'AI要約の利用状況を取得できませんでした。\n' +
                        'ページを再読み込みしてから、もう一度お試しください。'
                    );
                    return;
                }

                const remaining =
                    Number(usage.remaining || 0);

                const amendmentName =
                    button.dataset.amendmentName ||
                    'この改正';

                const message =
                    remaining > 0
                        ? 'この改正をAIで要約します。\n\n' +
                          '今月のAI要約の残り：' +
                          remaining +
                          '回\n\n' +
                          amendmentName
                        : '今月のAI要約利用回数を使い切っています。\n\n' +
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

/*
 * AI要約の最新利用状況を取得するREST API。
 *
 * 法令ページを開いた後に別タブ等でAI要約を利用した場合でも、
 * 「AIで要約」クリック時点の最新残り回数を確認できるようにする。
 */
add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'egov-law-monitor/v1',
            '/ai-summary-usage',
            array(
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
                'callback' => function () {

                    $user_id =
                        get_current_user_id();

                    $limit =
                        egov_law_monitor_get_ai_summary_limit(
                            $user_id
                        );

                    $usage_count =
                        egov_law_monitor_get_ai_summary_usage_count(
                            $user_id
                        );

                    return rest_ensure_response(
                        array(
                            'remaining' => max(
                                0,
                                $limit - $usage_count
                            ),
                            'limit' => $limit,
                            'usageCount' => $usage_count,
                        )
                    );
                },
            )
        );
    }
);

require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-users.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-menu.php';


register_activation_hook(
    __FILE__,
    function () {
        egov_law_monitor_create_tables();
        egov_law_monitor_setup_api_user();
    }
);