<?php
/**
 * e-Gov Law Monitor - Watch API
 */

/**
 * Get watched keywords for the current user.
 *
 * @return array
 */
function egov_law_monitor_get_watches() {

    global $wpdb;

    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return [];
    }

    // プラン上限を超えている場合は、新しいキーワードから停止する。
    egov_law_monitor_enforce_watch_limit( $user_id );

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                keyword,
                created_at,
                updated_at,
                is_active
             FROM {$table_name}
             WHERE user_id = %d
             ORDER BY created_at ASC, id ASC",
            $user_id
        ),
        ARRAY_A
    );

    if ( ! is_array( $results ) ) {
        return [];
    }

    return array_map(
        static function ( $watch ) {
            return [
                'keyword'    => $watch['keyword'],
                'created_at' => $watch['created_at'],
                'updated_at' => $watch['updated_at'],
                'is_active'  => (int) $watch['is_active'],
            ];
        },
        $results
    );
}

/**
 * Enforce the user's current watch limit without reactivating stopped keywords.
 * Older active keywords are kept; excess newer active keywords are stopped.
 *
 * @param int $user_id User ID.
 * @return void
 */
function egov_law_monitor_enforce_watch_limit( $user_id ) {

    global $wpdb;

    $user_id = (int) $user_id;

    if ( ! $user_id ) {
        return;
    }

    $table_name = $wpdb->prefix . 'law_watch_settings';
    $watch_limit = egov_law_monitor_get_watch_limit( $user_id );

    $active_watches = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id
             FROM {$table_name}
             WHERE user_id = %d
               AND is_active = 1
             ORDER BY created_at ASC, id ASC",
            $user_id
        ),
        ARRAY_A
    );

    if ( ! is_array( $active_watches ) || count( $active_watches ) <= $watch_limit ) {
        return;
    }

    $excess_watches = array_slice( $active_watches, $watch_limit );

    foreach ( $excess_watches as $watch ) {
        $wpdb->update(
            $table_name,
            array(
                'is_active'  => 0,
                'updated_at' => current_time( 'mysql' ),
            ),
            array(
                'id'      => (int) $watch['id'],
                'user_id' => $user_id,
            ),
            array( '%d', '%s' ),
            array( '%d', '%d' )
        );
    }
}


/**
 * Check watch API permission.
 *
 * @return bool
 */
function egov_law_monitor_watch_permission() {

    return is_user_logged_in();
}


/**
 * Register watch REST API.
 */
add_action(
    'rest_api_init',
    function () {

        register_rest_route(
            'egov-law-monitor/v1',
            '/watches',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => function () {

                        return egov_law_monitor_get_watches();
                    },
                    'permission_callback' => 'egov_law_monitor_watch_permission',
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => function ( WP_REST_Request $request ) {

                        global $wpdb;

                        $user_id = get_current_user_id();

                        if ( ! $user_id ) {
                            return new WP_Error(
                                'not_logged_in',
                                'ログインが必要です。',
                                [
                                    'status' => 401,
                                ]
                            );
                        }

                        $keyword = $request->get_param( 'keyword' );

                        if ( ! is_string( $keyword ) ) {
                            return new WP_Error(
                                'invalid_keyword',
                                'キーワードを入力してください。',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        $keyword = trim( $keyword );

                        if ( $keyword === '' ) {
                            return new WP_Error(
                                'empty_keyword',
                                'キーワードを入力してください。',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        if ( preg_match( '/\s/u', $keyword ) ) {
                            return new WP_Error(
                                'multiple_keywords',
                                'キーワードは1個だけ入力してください。',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        $table_name = $wpdb->prefix . 'law_watch_settings';

                        /*
                         * Apply the current plan limit, then count active keywords.
                         */
                        egov_law_monitor_enforce_watch_limit( $user_id );

                        $watch_limit = egov_law_monitor_get_watch_limit( $user_id );

                        $watch_count = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*)
                                FROM {$table_name}
                                WHERE user_id = %d
                                  AND is_active = 1",
                                $user_id
                            )
                        );

                        if ( $watch_count >= $watch_limit ) {
                            return new WP_Error(
                                'watch_limit_reached',
                                '現在のプランでは、これ以上キーワードを登録できません。',
                                [
                                    'status' => 409,
                                ]
                            );
                        }

                        /*
                         * Prevent duplicate keyword registration.
                         */
                        $existing = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id
                                 FROM {$table_name}
                                 WHERE user_id = %d
                                   AND keyword = %s
                                 LIMIT 1",
                                $user_id,
                                $keyword
                            )
                        );

                        if ( $existing ) {
                            return new WP_Error(
                                'already_watched',
                                'このキーワードはすでにウォッチ中です。',
                                [
                                    'status' => 409,
                                ]
                            );
                        }

                        /*
                        * Limit target laws.
                        */
                        $target_laws = egov_law_monitor_search_laws( $keyword );

                        if ( is_wp_error( $target_laws ) ) {
                            return new WP_Error(
                                'law_search_failed',
                                '対象法令の確認に失敗しました。しばらくしてからもう一度お試しください。',
                                [
                                    'status' => 503,
                                ]
                            );
                        }

                        if ( 0 === count( $target_laws ) ) {
                            return new WP_Error(
                                'no_target_laws',
                                '対象法令が見つかりません。法令名の一部など、別のキーワードでお試しください。',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        if ( count( $target_laws ) >= 100 ) {
                            return new WP_Error(
                                'too_many_target_laws',
                                '対象法令が100件以上となるキーワードは登録できません。より具体的なキーワードで絞り込んでください。',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        $now = current_time( 'mysql' );

                        $inserted = $wpdb->insert(
                            $table_name,
                            [
                                'user_id'    => $user_id,
                                'keyword'    => $keyword,
                                'created_at' => $now,
                                'updated_at' => $now,
                                'is_active'  => 1,
                            ],
                            [
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%d',
                            ]
                        );

                        if ( false === $inserted ) {
                            return new WP_Error(
                                'watch_save_failed',
                                'キーワードのウォッチ登録に失敗しました。',
                                [
                                    'status' => 500,
                                ]
                            );
                        }

                        return [
                            'keyword' => $keyword,
                            'watches' => egov_law_monitor_get_watches(),
                        ];
                    },
                    'permission_callback' => 'egov_law_monitor_watch_permission',
                ],
            ]
        );


        register_rest_route(
            'egov-law-monitor/v1',
            '/watches/(?P<keyword>[^/]+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => function ( WP_REST_Request $request ) {

                    global $wpdb;

                    $user_id = get_current_user_id();

                    if ( ! $user_id ) {
                        return new WP_Error(
                            'not_logged_in',
                            'ログインが必要です。',
                            [
                                'status' => 401,
                            ]
                        );
                    }

                    $keyword = $request->get_param( 'keyword' );

                    if ( ! is_string( $keyword ) ) {
                        return new WP_Error(
                            'invalid_keyword',
                            'キーワードが指定されていません。',
                            [
                                'status' => 400,
                            ]
                        );
                    }

                    $keyword = trim( rawurldecode( $keyword ) );
                    
                    if ( $keyword === '' ) {
                        return new WP_Error(
                            'empty_keyword',
                            'キーワードが指定されていません。',
                            [
                                'status' => 400,
                            ]
                        );
                    }

                    $table_name = $wpdb->prefix . 'law_watch_settings';

                    $deleted = $wpdb->delete(
                        $table_name,
                        [
                            'user_id' => $user_id,
                            'keyword' => $keyword,
                        ],
                        [
                            '%d',
                            '%s',
                        ]
                    );

                    if ( false === $deleted ) {
                        return new WP_Error(
                            'watch_delete_failed',
                            'キーワードのウォッチ解除に失敗しました。',
                            [
                                'status' => 500,
                            ]
                        );
                    }

                    if ( 0 === $deleted ) {
                        return new WP_Error(
                            'not_watched',
                            'このキーワードはウォッチされていません。',
                            [
                                'status' => 404,
                            ]
                        );
                    }

                    return [
                        'keyword' => $keyword,
                        'watches' => egov_law_monitor_get_watches(),
                    ];
                },
                'permission_callback' => 'egov_law_monitor_watch_permission',
            ]
        );
    }
);