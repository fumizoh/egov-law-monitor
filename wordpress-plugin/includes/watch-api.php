<?php
/**
 * e-Gov Law Monitor - Watch API
 */

/**
 * Get watched laws for the current user.
 *
 * @return array
 */
function egov_law_monitor_get_watches() {

    global $wpdb;

    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return [];
    }

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                law_id,
                law_name,
                law_no,
                law_type,
                created_at,
                updated_at
             FROM {$table_name}
             WHERE user_id = %d
             ORDER BY created_at ASC",
            $user_id
        ),
        ARRAY_A
    );

    return is_array( $results ) ? $results : [];
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

                        $law_id   = $request->get_param( 'law_id' );
                        $law_name = $request->get_param( 'law_name' );
                        $law_no   = $request->get_param( 'law_no' );
                        $law_type = $request->get_param( 'law_type' );

                        if (
                            ! is_string( $law_id )
                            || trim( $law_id ) === ''
                            || ! is_string( $law_name )
                            || trim( $law_name ) === ''
                            || ! is_string( $law_no )
                            || trim( $law_no ) === ''
                            || ! is_string( $law_type )
                            || trim( $law_type ) === ''
                        ) {
                            return new WP_Error(
                                'invalid_watch',
                                'Invalid watch data.',
                                [
                                    'status' => 400,
                                ]
                            );
                        }

                        $law_id   = trim( $law_id );
                        $law_name = trim( $law_name );
                        $law_no   = trim( $law_no );
                        $law_type = trim( $law_type );

                        $table_name = $wpdb->prefix . 'law_watch_settings';

                        $existing = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id
                                 FROM {$table_name}
                                 WHERE user_id = %d
                                   AND law_id = %s
                                 LIMIT 1",
                                $user_id,
                                $law_id
                            )
                        );

                        if ( $existing ) {
                            return new WP_Error(
                                'already_watched',
                                'This law is already being watched.',
                                [
                                    'status' => 409,
                                ]
                            );
                        }

                        $now = current_time( 'mysql' );

                        $inserted = $wpdb->insert(
                            $table_name,
                            [
                                'user_id'    => $user_id,
                                'law_id'     => $law_id,
                                'law_name'   => $law_name,
                                'law_no'     => $law_no,
                                'law_type'   => $law_type,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                            [
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                            ]
                        );

                        if ( false === $inserted ) {
                            return new WP_Error(
                                'watch_save_failed',
                                'ウォッチ登録に失敗しました。',
                                [
                                    'status' => 500,
                                ]
                            );
                        }

                        return [
                            'law_id' => $law_id,
                            'watches' => egov_law_monitor_get_watches(),
                        ];
                    },
                    'permission_callback' => 'egov_law_monitor_watch_permission',
                ],
            ]
        );

        register_rest_route(
            'egov-law-monitor/v1',
            '/watches/(?P<law_id>[A-Za-z0-9]+)',
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

                    $law_id = $request->get_param( 'law_id' );

                    $table_name = $wpdb->prefix . 'law_watch_settings';

                    $deleted = $wpdb->delete(
                        $table_name,
                        [
                            'user_id' => $user_id,
                            'law_id'  => $law_id,
                        ],
                        [
                            '%d',
                            '%s',
                        ]
                    );

                    if ( false === $deleted ) {
                        return new WP_Error(
                            'watch_delete_failed',
                            'ウォッチ解除に失敗しました。',
                            [
                                'status' => 500,
                            ]
                        );
                    }

                    if ( 0 === $deleted ) {
                        return new WP_Error(
                            'not_watched',
                            'This law is not being watched.',
                            [
                                'status' => 404,
                            ]
                        );
                    }

                    return [
                        'law_id'  => $law_id,
                        'watches' => egov_law_monitor_get_watches(),
                    ];
                },
                'permission_callback' => 'egov_law_monitor_watch_permission',
            ]
        );
    }
);