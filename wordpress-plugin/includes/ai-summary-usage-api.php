<?php

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

                    $entitlement = egov_law_monitor_get_effective_entitlement( $user_id );
                    if ( is_wp_error( $entitlement ) ) {
                        return new WP_Error(
                            'ai_summary_entitlement_unavailable',
                            'AI要約の利用状況を確認できません。時間をおいて再度お試しください。',
                            array( 'status' => 503 )
                        );
                    }

                    if ( ! is_array( $entitlement ) ) {
                        return new WP_Error(
                            'ai_summary_entitlement_invalid',
                            'AI要約の利用状況を確認できません。時間をおいて再度お試しください。',
                            array( 'status' => 503 )
                        );
                    }

                    $expected_entitlements = array(
                        'free'     => array( 'limit' => 3, 'period' => 'lifetime' ),
                        'standard' => array( 'limit' => 100, 'period' => 'calendar_month' ),
                    );
                    $effective_plan = $entitlement['effective_plan'] ?? '';
                    if ( ! is_string( $effective_plan ) || ! isset( $expected_entitlements[ $effective_plan ] ) ) {
                        return new WP_Error(
                            'ai_summary_entitlement_invalid',
                            'AI要約の利用状況を確認できません。時間をおいて再度お試しください。',
                            array( 'status' => 503 )
                        );
                    }

                    $limit = $expected_entitlements[ $effective_plan ]['limit'];
                    $usage_period = $entitlement['ai_summary_period'] ?? null;
                    if (
                        ! isset( $entitlement['ai_summary_limit'] )
                        || $limit !== $entitlement['ai_summary_limit']
                        || $expected_entitlements[ $effective_plan ]['period'] !== $usage_period
                    ) {
                        return new WP_Error(
                            'ai_summary_entitlement_invalid',
                            'AI要約の利用状況を確認できません。時間をおいて再度お試しください。',
                            array( 'status' => 503 )
                        );
                    }

                    $usage_count = egov_law_monitor_get_ai_summary_usage_count_for_plan( $user_id, $effective_plan );
                    if ( is_wp_error( $usage_count ) ) {
                        return new WP_Error(
                            'ai_summary_usage_unavailable',
                            'AI要約の利用状況を確認できません。時間をおいて再度お試しください。',
                            array( 'status' => 503 )
                        );
                    }

                    /*
                    * Check whether the user has already used
                    * an AI summary for this exact revision.
                    */
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

                    $history = null;

                    if (
                        $law_id !== ''
                        && $effective_date !== ''
                        && $law_data_id !== ''
                        && $sub_revision !== ''
                    ) {
                        $history =
                            egov_law_monitor_get_ai_summary_history_by_revision(
                                $user_id,
                                $law_id,
                                $effective_date,
                                $law_data_id,
                                $sub_revision
                            );
                    }

                    return rest_ensure_response(
                        array(
                            'effectivePlan' => $effective_plan,
                            'usagePeriod' => $usage_period,
                            'remaining' => max(
                                0,
                                $limit - $usage_count
                            ),
                            'limit' => $limit,
                            'usageCount' => $usage_count,
                            'alreadyUsed' => ! empty( $history ),
                            'historyId' => ! empty( $history )
                                ? (int) $history['id']
                                : 0,
                        )
                    );
                },
            )
        );
    }
);
