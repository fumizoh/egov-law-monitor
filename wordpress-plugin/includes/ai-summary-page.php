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

    $history_id = isset( $_GET['history_id'] )
        ? absint( $_GET['history_id'] )
        : 0;

    $ai_summary_limit = egov_law_monitor_get_ai_summary_limit( $user_id );

    /*
     * AI Summary request.
     */
    $summary_result = null;
    $summary_error  = null;

    /*
     * History view.
     *
     * A history link must be served entirely from WordPress data.
     * Do not call Cloud Run when displaying a previously generated
     * summary.
     */
    if ( $history_id > 0 ) {

        $history_item =
            egov_law_monitor_get_ai_summary_history_by_id(
                $user_id,
                $history_id
            );

        if ( ! is_array( $history_item ) ) {

            $summary_error =
                new WP_Error(
                    'egov_law_monitor_ai_history_not_found',
                    '指定されたAI要約の履歴が見つかりません。'
                );

        } else {

            $summary_result = array(
                'law_id' => $history_item['law_id'],
                'law_name' => $history_item['law_name'],
                'effective_date' => $history_item['effective_date'],
                'law_data_id' => $history_item['law_data_id'] ?? '',
                'sub_revision' => $history_item['sub_revision'] ?? '',
                'revision_hash' => $history_item['revision_hash'],
                'amendment_name' => '',
                'comparison_effective_date' => '',
                'summary' => array(
                    'title' => $history_item['summary_title'],
                    'body' => $history_item['summary_body'],
                ),
                'cached' => true,
            );

            /*
             * The cache is also local WordPress data. Use it only to
             * restore the amendment metadata shown on the summary page.
             */
            $history_cache =
                egov_law_monitor_get_ai_summary_cache(
                    $history_item['law_id'],
                    $history_item['effective_date'],
                    $history_item['law_data_id'] ?? '',
                    $history_item['sub_revision'] ?? ''
                );

            if ( is_array( $history_cache ) ) {
                $summary_result['amendment_name'] =
                    $history_cache['amendment_name'] ?? '';

                $summary_result['comparison_effective_date'] =
                    $history_cache['comparison_effective_date'] ?? '';
            }
        }

    } elseif (
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
            $usage_count >= $ai_summary_limit &&
            ! $already_used
        ) {

            $summary_error =
                new WP_Error(
                    'egov_law_monitor_ai_limit_reached',
                    '今月のAI要約利用回数の上限に達しています。'
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

    ob_start();
    ?>

    <div class="egov-ai-summary-page">

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

                /*
                 * The AI response may contain literal \n
                 * escape sequences. Convert them to real line breaks
                 * before wpautop() formats the body.
                 */
                $summary_body = str_replace(
                    array( '\r\n', '\n', '\r' ),
                    array( "
", "
", "" ),
                    $summary_body
                );

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

                    <p class="egov-ai-summary-meta">
                        改正法令：
                        <?php echo esc_html( $amendment_name ); ?>
                    </p>

                <?php endif; ?>

                <p class="egov-ai-summary-meta">
                    施行日：
                    <?php
                    echo esc_html( $effective_date );
                    ?>
                </p>

                <?php if ( $comparison_effective_date !== '' ) : ?>

                    <p class="egov-ai-summary-meta">
                        比較対象の施行日：
                        <?php echo esc_html( $comparison_effective_date ); ?>
                    </p>

                <?php endif; ?>

                <h3 class="egov-ai-summary-title">
                    🤖 <?php echo esc_html( $summary_title ); ?>
                </h3>

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


    </div>

    <?php

    return ob_get_clean();
}

add_shortcode(
    'egov_ai_summary',
    'egov_law_monitor_ai_summary_shortcode'
);
