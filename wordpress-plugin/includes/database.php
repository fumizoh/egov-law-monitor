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
        law_id VARCHAR(32) NOT NULL,
        law_name TEXT NOT NULL,
        law_no TEXT NOT NULL,
        law_type VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_law (user_id, law_id),
        KEY user_id (user_id),
        KEY law_id (law_id)
    ) {$charset_collate};";

    dbDelta( $sql );
}