<?php

/**
 * AI Summary page shortcode.
 */

function egov_law_monitor_ai_summary_shortcode() {

    if ( ! is_user_logged_in() ) {
        return '<p>AI要約を利用するにはログインしてください。</p>';
    }

    $user_id = get_current_user_id();

    $law_id = isset( $_GET['law_id'] )
        ? sanitize_text_field(
            wp_unslash( $_GET['law_id'] )
        )
        : '';

    $effective_date = isset( $_GET['effective_date'] )
        ? sanitize_text_field(
            wp_unslash( $_GET['effective_date'] )
        )
        : '';

    $law_data_id = isset( $_GET['law_data_id'] )
        ? sanitize_text_field(
            wp_unslash( $_GET['law_data_id'] )
        )
        : '';

    $sub_revision = isset( $_GET['sub_revision'] )
        ? sanitize_text_field(
            wp_unslash( $_GET['sub_revision'] )
        )
        : '';

    $free_limit = 5;

    /*
     * AI Summary request.
     */
    $summary_result = null;
    $summary_error  = null;

    if (
        $law_id &&
        $effective_date &&
        $law_data_id &&
        $sub_revision
    ) {

        $usage_count =
            egov_law_monitor_get_ai_summary_usage_count(
                $user_id
            );

        /*
         * If the user has already used this exact revision,
         * it can still be displayed after the free limit.
         */
        $cache =
            egov_law_monitor_get_ai_summary_cache(
                $law_id,
                $effective_date,
                $law_data_id,
                $sub_revision
            );

        $already_used =
            egov_law_monitor_has_ai_summary_history(
                $user_id,
                $law_id,
                $effective_date,
                $law_data_id,
                $sub_revision
            );

        /*
         * Do not call Cloud Run when the user has reached
         * the free limit and this exact revision has not been used.
         */
        if (
            $usage_count >= $free_limit &&
            ! $already_used
        ) {

            $summary_error =
                new WP_Error(
                    'egov_law_monitor_ai_limit_reached',
                    'AI要約の無料利用回数の上限に達しています。'
                );

        } else {

            $summary_result =
                egov_law_monitor_generate_ai_summary(
                    $law_id,
                    $effective_date,
                    $law_data_id,
                    $sub_revision
                );

            if ( is_wp_error( $summary_result ) ) {

                $summary_error =
                    $summary_result;

                $summary_result = null;

            } else {

                $revision_hash =
                    $summary_result['revision_hash'] ?? '';

                /*
                 * A successful summary must have a revision hash.
                 */
                if ( $revision_hash === '' ) {

                    $summary_error =
                        new WP_Error(
                            'egov_law_monitor_ai_invalid_response',
                            'AI要約のバージョン情報を取得できませんでした。'
                        );

                    $summary_result = null;

                } else {

                    /*
                     * Same revision:
                     * do not consume another free use.
                     */
                    $already_used =
                        egov_law_monitor_has_ai_summary_history(
                            $user_id,
                            $law_id,
                            $effective_date,
                            $law_data_id,
                            $sub_revision
                        );

                    /*
                     * New revision:
                     * record the usage only after the summary
                     * has been successfully obtained.
                     */
                    if ( ! $already_used ) {

                        $saved =
                            egov_law_monitor_save_ai_summary_history(
                                $user_id,
                                $law_id,
                                $summary_result['law_name'] ?? '',
                                $effective_date,
                                $law_data_id,
                                $sub_revision,
                                $revision_hash,
                                $summary_result['summary']['title'] ?? '',
                                $summary_result['summary']['body'] ?? ''
                            );

                        if ( $saved === false ) {

                            $summary_error =
                                new WP_Error(
                                    'egov_law_monitor_ai_history_error',
                                    'AI要約の利用履歴を保存できませんでした。'
                                );

                            $summary_result = null;
                        }
                    }
                }
            }
        }

    } elseif ( $law_id || $effective_date || $law_data_id || $sub_revision ) {

        $summary_error =
            new WP_Error(
                'egov_law_monitor_ai_invalid_request',
                'AI要約の対象となる改正情報が不足しています。'
            );
    }

    /*
     * Get the latest usage count after processing.
     */
    $usage_count =
        egov_law_monitor_get_ai_summary_usage_count(
            $user_id
        );

    $remaining = max(
        0,
        $free_limit - $usage_count
    );

    /*
     * Get history after processing.
     */
    $history =
        egov_law_monitor_get_ai_summary_history(
            $user_id
        );

    ob_start();
    ?>

    <div class="egov-ai-summary-page">

        <h2 class="egov-ai-summary-heading"><i class="las la-robot" aria-hidden="true"></i> AI要約</h2>

        <div class="egov-ai-summary-usage">

            <p>
                AI要約の無料利用：
                <strong>
                    残り <?php echo esc_html( $remaining ); ?>
                    / <?php echo esc_html( $free_limit ); ?> 回
                </strong>
            </p>

        </div>

        <div class="egov-ai-summary-current">

            <?php if ( $summary_error ) : ?>

                <p>
                    <?php
                    echo esc_html(
                        $summary_error->get_error_message()
                    );
                    ?>
                </p>

            <?php elseif ( $summary_result ) : ?>

                <?php
                $summary =
                    $summary_result['summary'] ?? array();

                $summary_title =
                    $summary['title'] ?? '';

                $summary_body =
                    $summary['body'] ?? '';

                $law_name =
                    $summary_result['law_name'] ?? '';

                $amendment_name =
                    $summary_result['amendment_name'] ?? '';

                $comparison_effective_date =
                    $summary_result['comparison_effective_date'] ?? '';
                ?>

                <?php if ( $law_name !== '' ) : ?>

                    <h2 class="egov-ai-summary-law-name">
                        <?php echo esc_html( $law_name ); ?>
                    </h2>

                <?php endif; ?>

                <?php if ( $amendment_name !== '' ) : ?>

                    <p>
                        改正法令：
                        <?php echo esc_html( $amendment_name ); ?>
                    </p>

                <?php endif; ?>

                <?php if ( $comparison_effective_date !== '' ) : ?>

                    <p>
                        比較対象の施行日：
                        <?php echo esc_html( $comparison_effective_date ); ?>
                    </p>

                <?php endif; ?>

                <h3 class="egov-ai-summary-title">
                    <?php echo esc_html( $summary_title ); ?>
                </h3>

                <p>
                    施行日：
                    <?php
                    echo esc_html( $effective_date );
                    ?>
                </p>

                <div class="egov-ai-summary-body">

                    <?php
                    echo wpautop(
                        esc_html( $summary_body )
                    );
                    ?>

                </div>

            <?php elseif ( $law_id && $effective_date ) : ?>

                <p>
                    AI要約を表示できませんでした。
                </p>

            <?php else : ?>

                <p>表示する法令を選択してください。</p>

            <?php endif; ?>

        </div>

        <div class="egov-ai-summary-history">

            <h3>AI要約の履歴</h3>

            <?php if ( empty( $history ) ) : ?>

                <p>AI要約の利用履歴はありません。</p>

            <?php else : ?>

                <ul>

                    <?php foreach ( $history as $item ) : ?>

                        <?php
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
                            get_permalink()
                        );
                        ?>

                        <?php
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
                            <a
                                class="egov-ai-summary-history-law"
                                href="<?php echo esc_url( $history_url ); ?>"
                            >
                                <?php echo esc_html( $item['law_name'] ); ?>
                            </a>

                            <div class="egov-ai-summary-history-meta">
                                <span>
                                    施行日：<?php echo esc_html( $item['effective_date'] ); ?>
                                </span>
                                <span>
                                    利用日：<?php echo esc_html( $used_at_display ); ?>
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
    'egov_ai_summary',
    'egov_law_monitor_ai_summary_shortcode'
);