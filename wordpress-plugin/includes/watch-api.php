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
 * Check whether the user can add or resume a watch under their effective plan.
 *
 * This check never changes existing watch rows. Entitlement and database
 * errors are converted to generic REST-safe errors so internal details are
 * not exposed to the client.
 *
 * @param int $user_id User ID.
 * @return array|WP_Error Effective entitlement and active watch count, or an error.
 */
function egov_law_monitor_check_watch_capacity( $user_id ) {
	global $wpdb;

	$entitlement = egov_law_monitor_get_effective_entitlement( (int) $user_id );
	if ( is_wp_error( $entitlement ) ) {
		return new WP_Error(
			'watch_entitlement_unavailable',
			'プラン情報を確認できません。時間をおいて再度お試しください。',
			array( 'status' => 503 )
		);
	}

	$expected_limits = array(
		'free'     => 5,
		'standard' => 10,
	);
	if ( ! is_array( $entitlement ) ) {
		return new WP_Error(
			'watch_entitlement_unavailable',
			'プラン情報を確認できません。時間をおいて再度お試しください。',
			array( 'status' => 503 )
		);
	}

	$plan = $entitlement['effective_plan'] ?? '';
	if (
		! is_string( $plan )
		|| ! isset( $expected_limits[ $plan ] )
		|| ! isset( $entitlement['watch_limit'] )
		|| $entitlement['watch_limit'] !== $expected_limits[ $plan ]
	) {
		return new WP_Error(
			'watch_entitlement_unavailable',
			'プラン情報を確認できません。時間をおいて再度お試しください。',
			array( 'status' => 503 )
		);
	}

	$table_name = $wpdb->prefix . 'law_watch_settings';
	$wpdb->last_error = '';
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND is_active = 1",
			(int) $user_id
		)
	);

	if ( '' !== $wpdb->last_error || null === $count || ! is_numeric( $count ) ) {
		return new WP_Error(
			'watch_limit_check_failed',
			'ウォッチ登録数を確認できません。時間をおいて再度お試しください。',
			array( 'status' => 503 )
		);
	}

	$count = (int) $count;
	if ( $count >= $entitlement['watch_limit'] ) {
		return new WP_Error(
			'watch_limit_reached',
			'現在のプランでは、これ以上キーワードを登録できません。',
			array( 'status' => 409 )
		);
	}

	return array(
		'entitlement' => $entitlement,
		'active_count' => $count,
	);
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

						$capacity = egov_law_monitor_check_watch_capacity( $user_id );
						if ( is_wp_error( $capacity ) ) {
							return $capacity;
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
            '/watches/(?P<keyword>[^/]+)/resume',
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

                    $watch = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id, is_active
                             FROM {$table_name}
                             WHERE user_id = %d
                               AND keyword = %s
                             LIMIT 1",
                            $user_id,
                            $keyword
                        ),
                        ARRAY_A
                    );

                    if ( ! is_array( $watch ) ) {
                        return new WP_Error(
                            'not_watched',
                            'このキーワードは登録されていません。',
                            [
                                'status' => 404,
                            ]
                        );
                    }

                    if ( (int) $watch['is_active'] === 1 ) {
                        return new WP_Error(
                            'already_active',
                            'このキーワードはすでに有効です。',
                            [
                                'status' => 409,
                            ]
                        );
                    }

					$capacity = egov_law_monitor_check_watch_capacity( $user_id );
					if ( is_wp_error( $capacity ) ) {
						if ( 'watch_limit_reached' === $capacity->get_error_code() ) {
							return new WP_Error(
								'watch_limit_reached',
								'プランの有効キーワード上限に達しています。既存のキーワードを解除するか、プランを変更してから再開してください。',
								array( 'status' => 409 )
							);
						}
						return $capacity;
					}

                    $updated = $wpdb->update(
                        $table_name,
                        [
                            'is_active'  => 1,
                            'updated_at' => current_time( 'mysql' ),
                        ],
                        [
                            'id'      => (int) $watch['id'],
                            'user_id' => $user_id,
                        ],
                        [ '%d', '%s' ],
                        [ '%d', '%d' ]
                    );

                    if ( false === $updated ) {
                        return new WP_Error(
                            'watch_resume_failed',
                            'キーワードの再開に失敗しました。',
                            [
                                'status' => 500,
                            ]
                        );
                    }

                    return [
                        'keyword' => $keyword,
                        'success' => true,
                    ];
                },
                'permission_callback' => 'egov_law_monitor_watch_permission',
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
