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

    $history_table = $wpdb->prefix . 'egov_law_ai_summary_history';

    $sql = "CREATE TABLE {$history_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        law_id VARCHAR(32) NOT NULL,
        effective_date DATE NOT NULL,
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        used_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_summary (
            user_id,
            law_id,
            effective_date,
            revision_hash
        ),
        KEY user_id (user_id),
        KEY law_id (law_id),
        KEY effective_date (effective_date)
    ) {$charset_collate};";

    dbDelta( $sql );
}

/**
 * Get cached AI summary.
 *
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_cache(
    $law_id,
    $effective_date
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summaries';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE law_id = %s
             AND effective_date = %s
             LIMIT 1",
            $law_id,
            $effective_date
        ),
        ARRAY_A
    );
}

/**
 * Save AI summary cache.
 *
 * @param string $law_id          Law ID.
 * @param string $effective_date  Effective date.
 * @param string $revision_hash   Revision hash.
 * @param string $summary_title   Summary title.
 * @param string $summary_body    Summary body.
 * @return int|false
 */
function egov_law_monitor_save_ai_summary_cache(
    $law_id,
    $effective_date,
    $revision_hash,
    $summary_title,
    $summary_body
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summaries';

    $now = current_time( 'mysql' );

    return $wpdb->replace(
        $table_name,
        array(
            'law_id'          => $law_id,
            'effective_date'  => $effective_date,
            'revision_hash'   => $revision_hash,
            'summary_title'   => $summary_title,
            'summary_body'    => $summary_body,
            'created_at'      => $now,
            'updated_at'      => $now,
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Check whether a user has used an AI summary.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $revision_hash  Revision hash.
 * @return bool
 */
function egov_law_monitor_has_ai_summary_history(
    $user_id,
    $law_id,
    $effective_date,
    $revision_hash
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_name}
             WHERE user_id = %d
             AND law_id = %s
             AND effective_date = %s
             AND revision_hash = %s",
            $user_id,
            $law_id,
            $effective_date,
            $revision_hash
        )
    );

    return (int) $count > 0;
}

/**
 * Save AI summary usage history.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $revision_hash  Revision hash.
 * @return int|false
 */
function egov_law_monitor_save_ai_summary_history(
    $user_id,
    $law_id,
    $effective_date,
    $revision_hash,
    $summary_title,
    $summary_body
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->replace(
        $table_name,
        array(
            'user_id'        => $user_id,
            'law_id'         => $law_id,
            'effective_date' => $effective_date,
            'revision_hash'  => $revision_hash,
            'summary_title'  => $summary_title,
            'summary_body'   => $summary_body,
            'used_at'        => current_time( 'mysql' ),
        ),
        array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Get AI summary usage count for a user.
 *
 * @param int $user_id User ID.
 * @return int
 */
function egov_law_monitor_get_ai_summary_usage_count(
    $user_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_name}
             WHERE user_id = %d",
            $user_id
        )
    );

    return (int) $count;
}

/**
 * Get AI summary usage history for a user.
 *
 * @param int $user_id User ID.
 * @return array
 */
function egov_law_monitor_get_ai_summary_history(
    $user_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE user_id = %d
             ORDER BY used_at DESC",
            $user_id
        ),
        ARRAY_A
    );
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