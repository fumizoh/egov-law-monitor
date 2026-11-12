<?php
/** Local cancellation requests; existing contracts and entitlement schemas stay unchanged. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_cancellation_now() {
    return current_datetime()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function egov_law_monitor_cancellation_table() {
    global $wpdb;
    $name = $wpdb->prefix . 'egov_square_cancellations';
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) { throw new RuntimeException( '解約申請の保存先を確認できません。' ); }
    return $name;
}

function egov_law_monitor_cancellation_columns() {
    return array( 'id' => array( 'bigint unsigned', false ), 'user_id' => array( 'bigint unsigned', false ), 'contract_id' => array( 'bigint unsigned', false ), 'approval_id' => array( 'bigint unsigned', false ),
        'requested_at' => array( 'datetime', false ), 'end_date' => array( 'date', true ), 'updated_at' => array( 'datetime', false ), 'completed_at' => array( 'datetime', true ), 'revision' => array( 'bigint unsigned', false ),
        'admin_notified_at' => array( 'datetime', true ), 'user_notified_revision' => array( 'bigint unsigned', false ), 'last_mail_attempt_at' => array( 'datetime', true ),
        'last_due_attempt_date' => array( 'date', true ), 'last_due_sent_date' => array( 'date', true ), 'mail_claim_token' => array( 'char(32)', true ), 'mail_claimed_at' => array( 'datetime', true ) );
}

/** Reads use the supplied session when inside a transaction. Never reconnect. */
function egov_law_monitor_cancellation_rows( $sql, $params = array(), $db = null ) {
    global $wpdb;
    $rows = $db ? $db->rows( $sql, $params ) : $wpdb->get_results( $params ? $wpdb->prepare( str_replace( '?', '%s', $sql ), ...$params ) : $sql, ARRAY_A );
    if ( ! is_array( $rows ) || ( $db ?: $wpdb )->last_error !== '' || ( $db && $db->connection_lost ) ) { throw new RuntimeException( '解約申請の読み取りに失敗しました。' ); }
    return $rows;
}

function egov_law_monitor_cancellation_table_exists( $db = null ) {
    $name = egov_law_monitor_cancellation_table();
    $rows = egov_law_monitor_cancellation_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $name, 'BASE TABLE' ), $db );
    return count( $rows ) === 1 && $rows[0]['TABLE_NAME'] === $name;
}

function egov_law_monitor_cancellation_storage_ready( $db = null ) {
    try {
        $name = egov_law_monitor_cancellation_table();
        $status = egov_law_monitor_cancellation_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $name, 'BASE TABLE' ), $db );
        if ( count( $status ) !== 1 || $status[0]['TABLE_NAME'] !== $name || strcasecmp( $status[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { return false; }
        $columns = array_column( egov_law_monitor_cancellation_rows( "SHOW FULL COLUMNS FROM `{$name}`", array(), $db ), null, 'Field' );
        foreach ( egov_law_monitor_cancellation_columns() as $column => $spec ) {
            $row = $columns[$column] ?? array();
            if ( preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $row['Type'] ?? '' ) ) !== $spec[0] || ( $row['Null'] ?? '' ) !== ( $spec[1] ? 'YES' : 'NO' ) || ( $column === 'id' && stripos( $row['Extra'] ?? '', 'auto_increment' ) === false ) ) { return false; }
        }
        $groups = array();
        foreach ( egov_law_monitor_cancellation_rows( "SHOW INDEX FROM `{$name}`", array(), $db ) as $index ) { $groups[$index['Key_name']][] = $index; }
        $found = array();
        foreach ( $groups as $key => $parts ) {
            if ( count( $parts ) !== 1 ) { continue; }
            $row = $parts[0];
            if ( ( $row['Sub_part'] ?? null ) !== null || ( $row['Visible'] ?? 'YES' ) === 'NO' || ( $row['Ignored'] ?? 'NO' ) === 'YES' ) { continue; }
            if ( $key === 'PRIMARY' && $row['Column_name'] === 'id' && (int) $row['Non_unique'] === 0 ) { $found['id'] = true; }
            if ( $row['Column_name'] === 'approval_id' && (int) $row['Non_unique'] === 0 ) { $found['approval'] = true; }
            if ( $row['Column_name'] === 'user_id' ) { $found['user'] = true; }
        }
        return count( $found ) === 3;
    } catch ( Throwable $error ) { return false; }
}

function egov_law_monitor_install_cancellation_table() {
    global $wpdb;
    if ( get_option( 'egov_law_monitor_cancellation_schema_version' ) === '1' && egov_law_monitor_cancellation_storage_ready() ) { return; }
    if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
    $name = egov_law_monitor_cancellation_table(); $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        contract_id BIGINT UNSIGNED NOT NULL,
        approval_id BIGINT UNSIGNED NOT NULL,
        requested_at DATETIME NOT NULL,
        end_date DATE NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        admin_notified_at DATETIME NULL,
        user_notified_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_mail_attempt_at DATETIME NULL,
        last_due_attempt_date DATE NULL,
        last_due_sent_date DATE NULL,
        mail_claim_token CHAR(32) NULL,
        mail_claimed_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY approval_id (approval_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( egov_law_monitor_cancellation_storage_ready() ) { update_option( 'egov_law_monitor_cancellation_schema_version', '1', false ); }
}
add_action( 'admin_init', 'egov_law_monitor_install_cancellation_table' );
register_activation_hook( dirname( __DIR__ ) . '/egov-law-monitor.php', 'egov_law_monitor_install_cancellation_table' );

/** One owned connection and one transaction for each local mutation. */
function egov_law_monitor_cancellation_transaction( $user_id, $callback, $factory = null ) {
    global $wpdb;
    $db = null; $started = false; $committing = false;
    try {
        $tables = egov_law_monitor_square_plan_tables(); $table = egov_law_monitor_cancellation_table();
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) || ! egov_law_monitor_cancellation_storage_ready( $db ) || ! $db->begin() ) { throw new RuntimeException( '同一DB・InnoDB・解約申請スキーマを確認できません。' ); }
        $started = true;
        $users = egov_law_monitor_cancellation_rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ), $db );
        if ( ! $users || (int) $users[0]['ID'] !== $user_id ) { throw new RuntimeException( '対象ユーザーが存在しません。' ); }
        $result = $callback( $db, $tables, $table );
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException(); }
        return $result;
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $rollback_error ) {} }
        $unknown = $started && ( $committing || ! $rolled_back );
        error_log( 'e-Gov Square: cancellation ' . ( $unknown ? 'outcome unknown' : 'operation failed' ) . '; user_id=' . $user_id );
        return new WP_Error( $unknown ? 'cancellation_outcome_unknown' : 'cancellation_failed', $unknown ? '確定または取り消し結果が不明です。再送信せず、申請・プラン・操作履歴を確認してください。' : '解約申請の状態または保存先を確認できません。再読み込みし、管理者へお問い合わせください。' );
    } finally { if ( $db ) { $db->close(); } }
}
