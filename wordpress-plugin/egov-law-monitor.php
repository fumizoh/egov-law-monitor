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
                ),
            )
        );

        wp_add_inline_script(
            'egov-law-watch',
            <<<'JS'
(function () {

    function showAiSummaryModal(options) {
        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        const {
            lawName,
            amendmentName,
            isNewLaw,
            effectivePlan,
            remaining,
            limit,
            onConfirm
        } = options;

        const existing = document.getElementById('egov-ai-summary-modal');
        if (existing) {
            existing.remove();
        }

        const overlay = document.createElement('div');
        overlay.id = 'egov-ai-summary-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'AI要約の確認');

        overlay.innerHTML = `
            <div class="egov-ai-summary-modal-overlay">
                <div class="egov-ai-summary-modal" role="document">
                    <div class="egov-ai-summary-modal-title">AI要約</div>
                    <div class="egov-ai-summary-modal-body">
                        ${
                            remaining > 0
                                ? '<p>この改正をAIで要約します。</p>' +
                                  '<p>' +
                                  (effectivePlan === 'free'
                                      ? 'お試し利用の残り：'
                                      : '今月のAI要約の残り：') +
                                  '<strong>' + remaining + ' / ' + limit + '回</strong></p>' +

                                    '<div class="egov-ai-summary-modal-amendment">' +
                                    '<p><strong>法令名</strong><br>' +
                                    escapeHtml(lawName) +
                                    '</p>' +
                                    (
                                        isNewLaw
                                            ? '<p>新規制定</p>'
                                            : '<p><strong>改正法令</strong><br>' +
                                                escapeHtml(amendmentName) +
                                                '</p>'
                                    ) +
                                    '</div>' +

                                    '<div class="egov-ai-summary-modal-notice">' +
                                    '<p><strong>要約対象について</strong></p>' +
                                    '<p>AI要約の対象は、条・項・号の本文です。別表・帳票・図は要約の対象外です。</p>' +
                                    '</div>'

                                : '<p>' +
                                (effectivePlan === 'free'
                                    ? 'AI要約のお試し利用回数（' + limit + '回）を使い切っています。'
                                    : '今月のAI要約利用回数（' + limit + '回）を使い切っています。') +
                                '</p>' +
                                '<p>この改正のAI要約を利用済みの場合は、<br>' +
                                'AI要約ページから再度表示できます。</p>'
                        }
                    </div>
                    <div class="egov-ai-summary-modal-actions">
                        <button type="button" class="egov-ai-summary-modal-cancel">
                            閉じる
                        </button>
                        ${
                            remaining > 0
                                ? '<button type="button" class="egov-ai-summary-modal-confirm">' +
                                  'AI要約する' +
                                  '</button>'
                                : ''
                        }
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const close = () => {
            overlay.remove();
        };

        overlay.querySelector('.egov-ai-summary-modal-overlay')
            .addEventListener('click', (event) => {
                if (event.target === event.currentTarget) {
                    close();
                }
            });

        overlay.querySelector('.egov-ai-summary-modal-cancel')
            .addEventListener('click', close);

        const confirmButton =
            overlay.querySelector('.egov-ai-summary-modal-confirm');

        if (confirmButton) {
            confirmButton.addEventListener('click', () => {
                close();
                onConfirm();
            });

            confirmButton.focus();
        } else {
            overlay.querySelector('.egov-ai-summary-modal-cancel').focus();
        }
    }

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

        buttons.forEach((button) => {

            button.addEventListener('click', async () => {

                if (!settings.isLoggedIn) {
                    window.alert(
                        'AI要約を利用するには、会員登録・ログインが必要です。'
                    );
                    return;
                }

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
                    const usageUrl =
                        new URL(
                            settings.usageUrl,
                            window.location.origin
                        );

                    usageUrl.searchParams.set(
                        'law_id',
                        button.dataset.lawId || ''
                    );

                    usageUrl.searchParams.set(
                        'effective_date',
                        button.dataset.effectiveDate || ''
                    );

                    usageUrl.searchParams.set(
                        'law_data_id',
                        button.dataset.lawDataId || ''
                    );

                    usageUrl.searchParams.set(
                        'sub_revision',
                        button.dataset.subRevision || ''
                    );

                    const response =
                        await fetch(
                            usageUrl.toString(),
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

                const effectivePlan = usage && usage.effectivePlan;
                const remaining = usage && usage.remaining;
                const limit = usage && usage.limit;

                if (
                    (effectivePlan !== 'free' && effectivePlan !== 'standard') ||
                    !Number.isInteger(remaining) ||
                    !Number.isInteger(limit) ||
                    remaining < 0 ||
                    limit < 1 ||
                    remaining > limit
                ) {
                    window.alert(
                        'AI要約の利用状況を取得できませんでした。\n' +
                        'ページを再読み込みしてから、もう一度お試しください。'
                    );
                    return;
                }

                const amendmentName =
                    button.dataset.amendmentName ||
                    'この改正';

                /*
                * If the user has already used an AI summary
                * for this exact revision, show the existing
                * history directly without displaying the modal.
                */
                if (
                    usage.alreadyUsed &&
                    Number(usage.historyId || 0) > 0
                ) {
                    const url =
                        new URL(
                            settings.url,
                            window.location.origin
                        );

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

                    url.searchParams.set(
                        'history_id',
                        String( usage.historyId )
                    );

                    window.location.href = url.toString();
                    return;
                }

                showAiSummaryModal({
                    lawName: button.dataset.lawName || '',
                    amendmentName: amendmentName,
                    isNewLaw: button.dataset.isNewLaw === 'true',
                    effectivePlan: effectivePlan,
                    remaining: remaining,
                    limit: limit,
                    onConfirm: () => {

                        const url =
                            new URL(
                                settings.url,
                                window.location.origin
                            );

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

                        // ポップアップを使わず、同じタブでAI要約ページへ移動する。
                        window.location.href = url.toString();
                    }
                });
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
require_once plugin_dir_path( __FILE__ ) . 'includes/square-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-usage.php';
add_action( 'plugins_loaded', 'egov_law_monitor_maybe_upgrade_subscription_schema' );

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
require_once plugin_dir_path( __FILE__ ) . 'includes/entitlement-policy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/entitlement-service.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/ai-summary-usage-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-checkout.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-billing-period-candidate.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-admin-review.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-subscription-sync.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-recurring-payment-read-service.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-initial-payment-read-service.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/square-webhook.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/subscription-plan-page.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-users.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-menu.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-ai-summary-cache.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/admin-square-payments.php';


register_activation_hook(
    __FILE__,
    function () {
        egov_law_monitor_create_tables();
        egov_law_monitor_maybe_upgrade_subscription_schema();
        egov_law_monitor_setup_api_user();
    }
);
