<?php
/**
 * e-Gov Law Monitor - Internal API
 */

/**
 * Check internal API permission.
 *
 * @return bool
 */
function egov_law_monitor_internal_api_permission() {

    if ( ! is_user_logged_in() ) {
        return false;
    }

    return current_user_can( 'law_watch_internal_api' );
}


/**
 * Get watch settings for all users.
 *
 * @return array
 */
function egov_law_monitor_get_all_watch_settings() {

    global $wpdb;

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $results = $wpdb->get_results(
        "SELECT
            w.user_id,
            u.user_email,
            w.keyword
         FROM {$table_name} AS w
         INNER JOIN {$wpdb->users} AS u
            ON w.user_id = u.ID
         ORDER BY w.user_id ASC, w.created_at ASC",
        ARRAY_A
    );

    if ( ! is_array( $results ) ) {
        return [];
    }

    $users = [];

    foreach ( $results as $row ) {

        $user_id = (int) $row['user_id'];

        if ( ! isset( $users[ $user_id ] ) ) {
            $users[ $user_id ] = [
                'user_id' => $user_id,
                'email'   => $row['user_email'],
                'watches' => [],
            ];
        }

        $users[ $user_id ]['watches'][] = [
            'keyword' => $row['keyword'],
        ];
    }

    return [
        'users' => array_values( $users ),
    ];
}


/**
 * Register internal REST API.
 */
add_action(
    'rest_api_init',
    function () {

        register_rest_route(
            'egov-law-monitor/v1',
            '/internal/watch-settings',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'egov_law_monitor_get_all_watch_settings',
                'permission_callback' => 'egov_law_monitor_internal_api_permission',
            ]
        );
    }
);