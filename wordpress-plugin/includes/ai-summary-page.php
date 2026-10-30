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

    $entitlement = egov_law_monitor_get_effective_entitlement( $user_id );
    $entitlement_error = is_wp_error( $entitlement );

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

        $history_item =
            egov_law_monitor_get_ai_summary_history_by_revision(
                $user_id,
                $law_id,
                $effective_date,
                $law_data_id,
                $sub_revision
            );

        $already_used = is_array( $history_item );

        /*
         * If this exact revision has already been summarized for this user,
         * use the saved WordPress history directly.
         *
         * Cloud Run is not called in this path.
         */
        if ( $already_used ) {

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
             * Restore display metadata from the local WordPress cache.
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

        } else {

            $usage_reservation = null;
            $bypass_usage = current_user_can( 'manage_options' );
            if ( ! $bypass_usage && $entitlement_error ) {
                $summary_error = new WP_Error(
                    'egov_law_monitor_entitlement_unavailable',
                    'プラン情報を確認できません。時間をおいて再度お試しください。'
                );
            } elseif ( ! $bypass_usage ) {
                // Use this single resolved plan for idempotency and quota reservation.
                $request_key = hash( 'sha256', implode( '|', array( $user_id, $entitlement['effective_plan'], $law_id, $effective_date, $law_data_id, $sub_revision ) ) );
                $usage_reservation = egov_law_monitor_ai_usage_service()->reserve(
                    $user_id,
                    $entitlement['effective_plan'],
                    $request_key
                );
                if ( is_wp_error( $usage_reservation ) ) {
                    $summary_error = $usage_reservation;
                } elseif ( ! empty( $usage_reservation['already_reserved'] ) ) {
                    $summary_error = new WP_Error(
                        'egov_law_monitor_ai_request_in_progress',
                        '同じAI要約の処理を実行中です。しばらくしてから履歴をご確認ください。'
                    );
                } elseif ( ! empty( $usage_reservation['already_committed'] ) ) {
                    $summary_error = new WP_Error(
                        'egov_law_monitor_ai_usage_history_missing',
                        'AI要約の利用記録を確認できませんでした。'
                    );
                }
            }

            if ( ! is_wp_error( $summary_error ) ) {

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
                if ( is_array( $usage_reservation ) ) {
                    egov_law_monitor_ai_usage_service()->release( $usage_reservation['id'], $user_id );
                }

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
                    if ( is_array( $usage_reservation ) ) {
                        egov_law_monitor_ai_usage_service()->release( $usage_reservation['id'], $user_id );
                    }

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

                    if ( $already_used && ! $bypass_usage ) {
                        $existing_history = egov_law_monitor_get_ai_summary_history_by_revision( $user_id, $law_id, $effective_date, $law_data_id, $sub_revision );
                        $history_id = is_array( $existing_history ) ? absint( $existing_history['id'] ?? 0 ) : 0;
                        if ( $history_id ) {
                            $completion = egov_law_monitor_ai_usage_service()->commit_with_history(
                                $usage_reservation['id'],
                                $user_id,
                                function () use ( $history_id ) { return $history_id; }
                            );
                            if ( is_wp_error( $completion ) ) {
                                $summary_error = $completion;
                                $summary_result = null;
                            }
                        } else {
                            $summary_error = new WP_Error( 'egov_law_monitor_ai_history_not_found', '利用済みAI要約の履歴を確認できませんでした。' );
                            $summary_result = null;
                        }
                    }

                    /*
                     * New revision:
                     * record the usage only after the summary
                     * has been successfully obtained.
                     */
                    if ( ! $already_used && ! is_wp_error( $summary_error ) ) {
                        $save_history = function () use ( $user_id, $law_id, $summary_result, $effective_date, $law_data_id, $sub_revision, $revision_hash ) {
                            $saved = egov_law_monitor_save_ai_summary_history(
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
                            if ( false === $saved ) {
                                return false;
                            }
                            $history = egov_law_monitor_get_ai_summary_history_by_revision( $user_id, $law_id, $effective_date, $law_data_id, $sub_revision );
                            return is_array( $history ) && ! empty( $history['id'] ) ? (int) $history['id'] : false;
                        };

                        if ( ! $bypass_usage ) {
                            $completion = egov_law_monitor_ai_usage_service()->commit_with_history( $usage_reservation['id'], $user_id, $save_history );
                            $saved = is_wp_error( $completion ) ? false : $completion['history_id'];
                        } else {
                            $saved = $save_history();
                        }

                        if ( empty( $saved ) ) {

                            $summary_error =
                                new WP_Error(
                                    'egov_law_monitor_ai_usage_commit_error',
                                    'AI要約の利用を確定できませんでした。'
                                );

                            $summary_result = null;
                        }
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
", "
" ),
                    $summary_body
                );

                /*
                 * 【...】形式の見出しの直前で段落を分ける。
                 */
                $summary_body = preg_replace(
                    '/(?<!^)(?<!\n)\s*(【[^】]+】)/u',
                    "\n\n$1",
                    $summary_body
                );

                $law_name =
                    $summary_result['law_name'] ?? '';

                $amendment_name =
                    $summary_result['amendment_name'] ?? '';

                $comparison_effective_date =
                    $summary_result['comparison_effective_date'] ?? '';

                $law_url = '';
                if ( $law_id !== '' && $effective_date !== '' ) {
                    $law_url = add_query_arg(
                        'occasion_date',
                        $effective_date,
                        'https://laws.e-gov.go.jp/law/' . rawurlencode( $law_id )
                    );
                }

                /*
                 * AI要約の対象種別。
                 *
                 * 新規制定は改正法令名・比較対象施行日の両方がないことで判定する。
                 * 改正の未施行は、施行日が現在日より後の場合に判定する。
                 */
                $is_new_law =
                    $amendment_name === ''
                    && $comparison_effective_date === '';

                $is_pending =
                    ! $is_new_law
                    && $effective_date !== ''
                    && $effective_date > current_time( 'Y-m-d' );
                ?>

                <?php if ( $law_name !== '' ) : ?>

                    <h2 class="egov-ai-summary-law-name">
                        <?php echo esc_html( $law_name ); ?>
                    </h2>

                <?php endif; ?>

                <?php if ( $is_new_law ) : ?>

                    <p class="egov-ai-summary-meta">
                        新規制定
                    </p>

                <?php elseif ( $amendment_name !== '' ) : ?>

                    <p class="egov-ai-summary-meta">
                        改正・<?php echo $is_pending ? '未施行' : '施行済み'; ?>
                    </p>

                    <p class="egov-ai-summary-meta">
                        改正法令：
                        <?php echo esc_html( $amendment_name ); ?>
                    </p>

                <?php endif; ?>

                <p class="egov-ai-summary-meta">
                    施行日：
                    <?php
                    echo esc_html( $effective_date );
                    if ( $is_pending ) {
                        echo '（未施行）';
                    }
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

                <?php if ( $law_url !== '' ) : ?>
                    <p class="egov-ai-summary-law-link">
                        <a
                            href="<?php echo esc_url( $law_url ); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            法令を見る
                        </a>
                    </p>
                <?php endif; ?>

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
