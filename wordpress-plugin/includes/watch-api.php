<?php
/**
 * e-Gov Law Monitor - Watch API
 */

define(
    'EGOV_LAW_MONITOR_WATCH_OPTION',
    'egov_law_monitor_watches'
);


/**
 * Get watched laws.
 *
 * @return array
 */
function egov_law_monitor_get_watches() {

    $watches = get_option(
        EGOV_LAW_MONITOR_WATCH_OPTION,
        []
    );

    if ( ! is_array( $watches ) ) {
        return [];
    }

    return array_values( $watches );
}


/**
 * Save watched laws.
 *
 * @param array $watches Watched laws.
 * @return bool
 */
function egov_law_monitor_save_watches( $watches ) {

    return update_option(
        EGOV_LAW_MONITOR_WATCH_OPTION,
        array_values( $watches )
    );
}


/**
 * Check watch API permission.
 *
 * @return bool
 */
function egov_law_monitor_watch_permission() {

    return current_user_can( 'manage_options' );
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

                        $watch = [
                            'law_id'   => trim( $law_id ),
                            'law_name' => trim( $law_name ),
                            'law_no'   => trim( $law_no ),
                            'law_type' => trim( $law_type ),
                        ];

                        $watches = egov_law_monitor_get_watches();

                        foreach ( $watches as $existing_watch ) {

                            if (
                                isset( $existing_watch['law_id'] )
                                && $existing_watch['law_id'] === $watch['law_id']
                            ) {
                                return new WP_Error(
                                    'already_watched',
                                    'This law is already being watched.',
                                    [
                                        'status' => 409,
                                    ]
                                );
                            }
                        }

                        $watches[] = $watch;

                        egov_law_monitor_save_watches( $watches );

                        return [
                            'law_id'  => $watch['law_id'],
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

                    $law_id = $request->get_param( 'law_id' );

                    $watches = egov_law_monitor_get_watches();

                    $found = false;

                    $watches = array_values(
                        array_filter(
                            $watches,
                            static function ( $watch ) use ( $law_id, &$found ) {

                                if (
                                    isset( $watch['law_id'] )
                                    && $watch['law_id'] === $law_id
                                ) {
                                    $found = true;
                                    return false;
                                }

                                return true;
                            }
                        )
                    );

                    if ( ! $found ) {
                        return new WP_Error(
                            'not_watched',
                            'This law is not being watched.',
                            [
                                'status' => 404,
                            ]
                        );
                    }

                    egov_law_monitor_save_watches( $watches );

                    return [
                        'law_id'  => $law_id,
                        'watches' => $watches,
                    ];
                },
                'permission_callback' => 'egov_law_monitor_watch_permission',
            ]
        );
    }
);