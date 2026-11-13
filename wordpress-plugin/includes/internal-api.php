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
         WHERE w.is_active = 1
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
                'user_id'       => $user_id,
                'email'         => $row['user_email'],
                'plan'          => egov_law_monitor_get_plan( $user_id ),
                'notifications' => egov_law_monitor_get_notifications( $user_id ),
                'watches'       => [],
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
/** Strict timestamp parser shared by ingestion and display. */
function egov_law_monitor_parse_checked_at( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/', $value ) ) { return null; }
    if ( substr( $value, 0, 4 ) < '1000' || preg_match( '/[+-]14:(?!00)/', $value ) ) { return null; }
    try {
        $date = new DateTimeImmutable( $value );
        $errors = DateTimeImmutable::getLastErrors();
        if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) { return null; }
        return $date->setTimezone( new DateTimeZone( 'UTC' ) );
    } catch ( Throwable $error ) { return null; }
}

/** Atomic compare-and-swap: never starts a shared-connection transaction. */
function egov_law_monitor_save_checked_at( $request ) {
    global $wpdb;
    $date = egov_law_monitor_parse_checked_at( $request->get_param( 'last_checked' ) );
    if ( ! $date || $date->getTimestamp() > time() + 300 ) { return new WP_Error( 'invalid_last_checked', '有効なタイムゾーン付き確認日時を指定してください。', array( 'status' => 400 ) ); }
    $value = $date->format( 'Y-m-d\TH:i:s.u\Z' );
    $key = 'egov_law_monitor_last_checked';
    for ( $attempt = 0; $attempt < 3; ++$attempt ) {
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ), ARRAY_A );
        if ( $wpdb->last_error !== '' ) { break; }
        if ( ! $row ) {
            if ( add_option( $key, $value, '', false ) ) { return array( 'last_checked' => $value ); }
            continue; // Another request may have inserted the option first.
        }
        $old = egov_law_monitor_parse_checked_at( $row['option_value'] );
        if ( ! $old ) { break; }
        if ( $old >= $date ) { return array( 'last_checked' => $old->format( 'Y-m-d\TH:i:s.u\Z' ) ); }
        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $value, 'off', $key, $row['option_value'] ) );
        if ( $updated === false ) { break; }
        if ( $updated === 1 ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            return array( 'last_checked' => $value );
        }
        // A concurrent update won. Re-read, then compare against that value.
    }
    return new WP_Error( 'watch_status_save_failed', '確認日時を保存できません。再試行してください。', array( 'status' => 503 ) );
}

function egov_law_monitor_last_checked_label() {
    $value = get_option( 'egov_law_monitor_last_checked', null );
    if ( $value === null ) { return '未確認'; }
    $date = egov_law_monitor_parse_checked_at( $value );
    return $date ? wp_date( 'Y/n/j H:i', $date->getTimestamp(), wp_timezone() ) : '確認できません';
}

add_action(
    'rest_api_init',
    function () {

        register_rest_route(
            'egov-law-monitor/v1',
            '/internal/watch-status',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => 'egov_law_monitor_save_checked_at',
                'permission_callback' => 'egov_law_monitor_internal_api_permission',
            )
        );

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
