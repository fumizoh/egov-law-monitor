<?php

/**
 * AI要約 管理・履歴ページ用ショートコード
 *
 * Shortcode:
 * [egov_ai_summary_management]
 *
 * AI要約の生成は行わず、残り利用回数と利用履歴のみを表示する。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function egov_law_monitor_ai_summary_management_shortcode() {

    if ( ! is_user_logged_in() ) {
        return '<p>AI要約を利用するにはログインしてください。</p>';
    }

    $user_id    = get_current_user_id();
    $free_limit = EGOV_AI_SUMMARY_FREE_LIMIT;

    $usage_count =
        egov_law_monitor_get_ai_summary_usage_count(
            $user_id
        );

    $remaining = max(
        0,
        $free_limit - $usage_count
    );

    $history =
        egov_law_monitor_get_ai_summary_history(
            $user_id
        );

    /*
     * 履歴の改正法令名・比較対象の施行日は、
     * WordPressのAI要約キャッシュから補完する。
     * Cloud Runは呼び出さない。
     */
    foreach ( $history as &$history_item ) {

        $history_cache =
            egov_law_monitor_get_ai_summary_cache(
                $history_item['law_id'] ?? '',
                $history_item['effective_date'] ?? '',
                $history_item['law_data_id'] ?? '',
                $history_item['sub_revision'] ?? ''
            );

        if ( is_array( $history_cache ) ) {
            $history_item['amendment_name'] =
                $history_cache['amendment_name'] ?? '';

            $history_item['comparison_effective_date'] =
                $history_cache['comparison_effective_date'] ?? '';
        }
    }

    unset( $history_item );

    /*
     * AI要約結果ページのURL。
     *
     * [egov_ai_summary] を含む公開固定ページを自動的に探す。
     * メインプラグイン側のAI要約ボタンと同じ仕組みにする。
     */
    $result_page_url = '';

    $pages = get_pages(
        array(
            'post_status' => 'publish',
        )
    );

    foreach ( $pages as $page ) {
        if ( has_shortcode( $page->post_content, 'egov_ai_summary' ) ) {
            $result_page_url = get_permalink( $page );
            break;
        }
    }

    ob_start();
    ?>

    <div class="egov-ai-summary-management">

        <h2 class="egov-ai-summary-heading">
            <i class="las la-robot" aria-hidden="true"></i>
            AI要約
        </h2>

        <div class="egov-ai-summary-usage">

            <p>
                AI要約の無料利用：
                <strong>
                    残り <?php echo esc_html( $remaining ); ?>
                    / <?php echo esc_html( $free_limit ); ?> 回
                </strong>
            </p>

        </div>

        <div class="egov-ai-summary-history">

            <h3>AI要約の履歴</h3>

            <?php if ( empty( $history ) ) : ?>

                <p>AI要約の利用履歴はありません。</p>

            <?php else : ?>

                <ul>

                    <?php foreach ( $history as $item ) : ?>

                        <?php
                        $history_url = '';

                        if ( $result_page_url !== '' ) {
                            $history_url = add_query_arg(
                                array(
                                    'law_id' =>
                                        $item['law_id'],
                                    'effective_date' =>
                                        $item['effective_date'],
                                    'law_data_id' =>
                                        $item['law_data_id'] ?? '',
                                    'sub_revision' =>
                                        $item['sub_revision'] ?? '',
                                    'history_id' =>
                                        $item['id'],
                                ),
                                $result_page_url
                            );
                        }

                        $used_at = $item['used_at'] ?? '';

                        if (
                            $used_at === ''
                            || $used_at === '0000-00-00 00:00:00'
                        ) {
                            $used_at_display = '未記録';
                        } else {
                            $used_at_display = $used_at;
                        }
                        ?>

                        <li class="egov-ai-summary-history-item">

                            <?php if ( $history_url !== '' ) : ?>

                                <a
                                    class="egov-ai-summary-history-law"
                                    href="<?php echo esc_url( $history_url ); ?>"
                                >
                                    <?php echo esc_html( $item['law_name'] ); ?>
                                </a>

                            <?php else : ?>

                                <span class="egov-ai-summary-history-law">
                                    <?php echo esc_html( $item['law_name'] ); ?>
                                </span>

                            <?php endif; ?>

                            <?php if ( ! empty( $item['amendment_name'] ) ) : ?>
                                <div class="egov-ai-summary-history-amendment">
                                    改正法令：
                                    <?php echo esc_html( $item['amendment_name'] ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $item['summary_title'] ) ) : ?>
                                <div class="egov-ai-summary-history-title">
                                    <?php echo esc_html( $item['summary_title'] ); ?>
                                </div>
                            <?php endif; ?>

                            <div class="egov-ai-summary-history-meta">

                                <span>
                                    施行日：
                                    <?php echo esc_html( $item['effective_date'] ); ?>
                                </span>

                                <?php if ( ! empty( $item['comparison_effective_date'] ) ) : ?>
                                    <span>
                                        比較対象：
                                        <?php echo esc_html( $item['comparison_effective_date'] ); ?>
                                    </span>
                                <?php endif; ?>

                                <span>
                                    利用日：
                                    <?php echo esc_html( $used_at_display ); ?>
                                </span>

                            </div>

                        </li>

                    <?php endforeach; ?>

                </ul>

            <?php endif; ?>

        </div>

    </div>

    <?php

    return ob_get_clean();
}

add_shortcode(
    'egov_ai_summary_management',
    'egov_law_monitor_ai_summary_management_shortcode'
);
