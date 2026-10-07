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
        law_data_id VARCHAR(32) NOT NULL DEFAULT '',
        sub_revision VARCHAR(32) NOT NULL DEFAULT '',
        amendment_name TEXT NOT NULL,
        comparison_effective_date VARCHAR(10) NOT NULL DEFAULT '',
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY law_effective_revision (
            law_id,
            effective_date,
            law_data_id,
            sub_revision
        ),
        KEY law_id (law_id),
        KEY effective_date (effective_date),
        KEY law_data_revision (
            law_data_id,
            sub_revision
        )
    ) {$charset_collate};";

    dbDelta( $sql );

    /*
     * Explicitly migrate the existing AI summary cache table.
     *
     * dbDelta() should normally add these columns, but older installations
     * may retain the legacy schema. Check the actual table and add anything
     * that is missing.
     */
    $summary_columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$summary_table}",
        ARRAY_A
    );

    $summary_column_names = array();

    foreach ( $summary_columns as $column ) {
        $summary_column_names[] = $column['Field'];
    }

    if ( ! in_array( 'law_data_id', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN law_data_id VARCHAR(32) NOT NULL DEFAULT ''
             AFTER effective_date"
        );
    }

    if ( ! in_array( 'sub_revision', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN sub_revision VARCHAR(32) NOT NULL DEFAULT ''
             AFTER law_data_id"
        );
    }

    if ( ! in_array( 'amendment_name', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN amendment_name TEXT NOT NULL
             AFTER sub_revision"
        );
    }

    if ( ! in_array( 'comparison_effective_date', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN comparison_effective_date VARCHAR(10) NOT NULL DEFAULT ''
             AFTER amendment_name"
        );
    }

    /*
     * Remove the old unique key used by the previous cache design.
     *
     * The AI summary cache is now identified by:
     * law_id + effective_date + law_data_id + sub_revision.
     */
    $old_summary_key = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM {$summary_table}
             WHERE Key_name = %s",
            'law_effective_date'
        )
    );

    if ( $old_summary_key !== null ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             DROP INDEX law_effective_date"
        );
    }

    $summary_index = $wpdb->get_results(
        "SHOW INDEX FROM {$summary_table}
         WHERE Key_name = 'law_effective_revision'",
        ARRAY_A
    );

    if ( empty( $summary_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD UNIQUE KEY law_effective_revision (
                 law_id,
                 effective_date,
                 law_data_id,
                 sub_revision
             )"
        );
    }

    $history_table = $wpdb->prefix . 'egov_law_ai_summary_history';

    $sql = "CREATE TABLE {$history_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        law_id VARCHAR(32) NOT NULL,
        law_name TEXT NOT NULL,
        effective_date DATE NOT NULL,
        law_data_id VARCHAR(32) NOT NULL DEFAULT '',
        sub_revision VARCHAR(32) NOT NULL DEFAULT '',
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        used_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_summary (
            user_id,
            law_id,
            effective_date,
            law_data_id,
            sub_revision,
            revision_hash
        ),
        KEY user_id (user_id),
        KEY law_id (law_id),
        KEY effective_date (effective_date),
        KEY law_data_revision (law_data_id, sub_revision)
    ) {$charset_collate};";

    dbDelta( $sql );

    /*
     * Migrate existing AI summary history tables.
     *
     * Legacy installations may not yet have law_data_id/sub_revision,
     * and the old unique key used only revision_hash.
     */
    $history_columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$history_table}",
        ARRAY_A
    );

    $history_column_names = array();

    foreach ( $history_columns as $column ) {
        $history_column_names[] = $column['Field'];
    }

    if ( ! in_array( 'law_data_id', $history_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD COLUMN law_data_id VARCHAR(32) NOT NULL DEFAULT ''
             AFTER effective_date"
        );
    }

    if ( ! in_array( 'sub_revision', $history_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD COLUMN sub_revision VARCHAR(32) NOT NULL DEFAULT ''
             AFTER law_data_id"
        );
    }

    $history_index = $wpdb->get_results(
        "SHOW INDEX FROM {$history_table}
         WHERE Key_name = 'user_summary'",
        ARRAY_A
    );

    if ( ! empty( $history_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             DROP INDEX user_summary"
        );
    }

    $new_history_index = $wpdb->get_results(
        "SHOW INDEX FROM {$history_table}
         WHERE Key_name = 'user_summary'",
        ARRAY_A
    );

    if ( empty( $new_history_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD UNIQUE KEY user_summary (
                 user_id,
                 law_id,
                 effective_date,
                 law_data_id,
                 sub_revision,
                 revision_hash
             )"
        );
    }
}

/**
 * Get cached AI summary.
 *
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_cache(
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
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
             AND law_data_id = %s
             AND sub_revision = %s
             LIMIT 1",
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
        ),
        ARRAY_A
    );
}

/**
 * Save AI summary cache.
 *
 * @param string $law_id          Law ID.
 * @param string $effective_date  Effective date.
 * @param string $law_data_id     e-Gov law data ID.
 * @param string $sub_revision    e-Gov sub revision.
 * @param string $amendment_name  Amendment law name.
 * @param string $comparison_effective_date Comparison target effective date.
 * @param string $revision_hash   Revision hash.
 * @param string $summary_title   Summary title.
 * @param string $summary_body    Summary body.
 * @return int|false
 */
function egov_law_monitor_save_ai_summary_cache(
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision,
    $amendment_name,
    $comparison_effective_date,
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
            'law_data_id'     => $law_data_id,
            'sub_revision'    => $sub_revision,
            'amendment_name'  => $amendment_name,
            'comparison_effective_date' => $comparison_effective_date,
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
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Check whether a user has used an AI summary for the exact revision.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return bool
 */
function egov_law_monitor_has_ai_summary_history(
    $user_id,
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
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
             AND law_data_id = %s
             AND sub_revision = %s",
            $user_id,
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
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
    $law_name,
    $effective_date,
    $law_data_id,
    $sub_revision,
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
            'law_name'       => $law_name,
            'effective_date' => $effective_date,
            'law_data_id'   => $law_data_id,
            'sub_revision'  => $sub_revision,
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

    $now = current_time( 'timestamp' );
    $month_start = wp_date(
        'Y-m-01 00:00:00',
        $now
    );
    $next_month_start = wp_date(
        'Y-m-01 00:00:00',
        strtotime( '+1 month', $now )
    );

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_name}
             WHERE user_id = %d
             AND used_at >= %s
             AND used_at < %s",
            $user_id,
            $month_start,
            $next_month_start
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
/**
 * Get one AI summary history record for the current user.
 *
 * @param int $user_id    User ID.
 * @param int $history_id History record ID.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_history_by_id(
    $user_id,
    $history_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE id = %d
             AND user_id = %d
             LIMIT 1",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
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