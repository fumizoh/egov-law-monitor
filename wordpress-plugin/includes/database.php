<?php
/**
 * e-Gov Law Monitor - Database
 */

/**
 * Create law watch tables.
 */
function egov_law_monitor_create_tables() {

    global $wpdb;

    $table_name      = $wpdb->prefix . 'law_watch_settings';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        keyword VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_keyword (user_id, keyword),
        KEY user_id (user_id),
        KEY keyword (keyword)
    ) {$charset_collate};";

    dbDelta( $sql );

    $summary_table = $wpdb->prefix . 'egov_law_ai_summaries';

    $sql = "CREATE TABLE {$summary_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        law_id VARCHAR(32) NOT NULL,
        effective_date DATE NOT NULL,
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY law_effective_date (law_id, effective_date),
        KEY law_id (law_id),
        KEY effective_date (effective_date)
    ) {$charset_collate};";

    dbDelta( $sql );
}

/**
 * Delete law watch settings when a user is deleted.
 */
function egov_law_monitor_delete_user_data( $user_id ) {

    global $wpdb;

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $wpdb->delete(
        $table_name,
        array(
            'user_id' => $user_id,
        ),
        array(
            '%d',
        )
    );
}

add_action(
    'delete_user',
    'egov_law_monitor_delete_user_data'
);