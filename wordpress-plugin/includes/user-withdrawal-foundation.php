<?php
/** Withdrawal storage and fencing. No start operation or deletion is provided. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/user-withdrawal-policy.php';

class EGOV_Law_Monitor_Withdrawal_Error extends RuntimeException {}

function egov_law_monitor_withdrawal_table() {
    global $wpdb;
    $name = $wpdb->prefix . 'egov_user_withdrawals';
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) { throw new RuntimeException(); }
    return $name;
}

function egov_law_monitor_withdrawal_columns() {
    return array( 'process_uuid' => array( 'char(36)', false ), 'user_id' => array( 'bigint unsigned', false ), 'phase' => array( 'varchar(20)', false ), 'revision' => array( 'bigint unsigned', false ), 'claim_token' => array( 'char(32)', true ), 'claimed_at' => array( 'datetime', true ), 'started_audit_id' => array( 'bigint unsigned', true ), 'last_audit_id' => array( 'bigint unsigned', true ), 'created_at' => array( 'datetime', false ), 'updated_at' => array( 'datetime', false ), 'completed_at' => array( 'datetime', true ) );
}

function egov_law_monitor_withdrawal_rows( $sql, $params = array(), $db = null ) {
    global $wpdb;
    $rows = $db ? $db->rows( $sql, $params ) : $wpdb->get_results( $params ? $wpdb->prepare( str_replace( '?', '%s', $sql ), ...$params ) : $sql, ARRAY_A );
    if ( ! is_array( $rows ) || ( $db ?: $wpdb )->last_error !== '' || ( $db && $db->connection_lost ) ) { throw new RuntimeException( '退会状態を確認できません。再操作せず管理者へお問い合わせください。' ); }
    return $rows;
}

function egov_law_monitor_withdrawal_storage_ready( $db = null ) {
    try {
        $table = egov_law_monitor_withdrawal_table();
        $rows = egov_law_monitor_withdrawal_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $table, 'BASE TABLE' ), $db );
        if ( count( $rows ) !== 1 || $rows[0]['TABLE_NAME'] !== $table || strcasecmp( $rows[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { return false; }
        $columns = array_column( egov_law_monitor_withdrawal_rows( "SHOW FULL COLUMNS FROM `{$table}`", array(), $db ), null, 'Field' );
        foreach ( egov_law_monitor_withdrawal_columns() as $name => $spec ) {
            $column = $columns[$name] ?? array();
            if ( preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $column['Type'] ?? '' ) ) !== $spec[0] || ( $column['Null'] ?? '' ) !== ( $spec[1] ? 'YES' : 'NO' ) ) { return false; }
        }
        $groups = array(); $found = array();
        foreach ( egov_law_monitor_withdrawal_rows( "SHOW INDEX FROM `{$table}`", array(), $db ) as $index ) { $groups[$index['Key_name']][] = $index; }
        foreach ( $groups as $key => $parts ) {
            if ( count( $parts ) !== 1 ) { continue; }
            $index = $parts[0];
            if ( (int) $index['Non_unique'] !== 0 || ( $index['Sub_part'] ?? null ) !== null || ( $index['Visible'] ?? 'YES' ) === 'NO' || ( $index['Ignored'] ?? 'NO' ) === 'YES' ) { continue; }
            if ( $key === 'PRIMARY' && $index['Column_name'] === 'process_uuid' ) { $found['uuid'] = true; }
            if ( $index['Column_name'] === 'user_id' ) { $found['user'] = true; }
        }
        return count( $found ) === 2;
    } catch ( Throwable $error ) { return false; }
}

function egov_law_monitor_install_withdrawal_table() {
    global $wpdb;
    if ( get_option( 'egov_law_monitor_withdrawal_schema_version' ) === '1' ) { return; }
    try {
        $exists = egov_law_monitor_withdrawal_rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array( egov_law_monitor_withdrawal_table() ) );
    } catch ( Throwable $error ) { return; }
    // Never attempt to repair an incompatible existing table on a page view.
    if ( $exists ) {
        if ( egov_law_monitor_withdrawal_storage_ready() ) { update_option( 'egov_law_monitor_withdrawal_schema_version', '1', false ); }
        return;
    }
    if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
    $table = egov_law_monitor_withdrawal_table(); $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        process_uuid CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        phase VARCHAR(20) NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        claim_token CHAR(32) NULL,
        claimed_at DATETIME NULL,
        started_audit_id BIGINT UNSIGNED NULL,
        last_audit_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        PRIMARY KEY  (process_uuid),
        UNIQUE KEY user_id (user_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( egov_law_monitor_withdrawal_storage_ready() ) { update_option( 'egov_law_monitor_withdrawal_schema_version', '1', false ); }
}
add_action( 'admin_init', 'egov_law_monitor_install_withdrawal_table' );
register_activation_hook( dirname( __DIR__ ) . '/egov-law-monitor.php', 'egov_law_monitor_install_withdrawal_table' );
add_action( 'admin_notices', function () {
    if ( current_user_can( 'manage_options' ) && ! egov_law_monitor_withdrawal_storage_ready() ) { echo '<div class="notice notice-error"><p>退会状態の保存先を確認できません。関連する保存処理を停止しています。</p></div>'; }
} );

/** Caller owns the transaction: users row first, withdrawal row second, then business rows. */
function egov_law_monitor_withdrawal_lock( $db, $user_id ) {
    global $wpdb;
    if ( ! is_int( $user_id ) || $user_id < 1 ) { throw new RuntimeException( 'ユーザーIDが不正です。' ); }
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $wpdb->users ) || ! egov_law_monitor_withdrawal_storage_ready( $db ) ) { throw new RuntimeException( '退会状態の保存先を確認できません。' ); }
    $status = egov_law_monitor_withdrawal_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array( $wpdb->users ), $db );
    if ( count( $status ) !== 1 || strcasecmp( $status[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { throw new RuntimeException( 'ユーザー行の排他を保証できません。' ); }
    $users = egov_law_monitor_withdrawal_rows( "SELECT ID FROM `{$wpdb->users}` WHERE ID = ? FOR UPDATE", array( (int) $user_id ), $db );
    if ( count( $users ) !== 1 || (int) $users[0]['ID'] !== (int) $user_id ) { throw new RuntimeException( '対象ユーザーを確認できません。' ); }
    $rows = egov_law_monitor_withdrawal_rows( 'SELECT * FROM `' . egov_law_monitor_withdrawal_table() . '` WHERE user_id = ? FOR UPDATE', array( (int) $user_id ), $db );
    if ( count( $rows ) > 1 ) { throw new RuntimeException( '退会状態が重複しています。' ); }
    if ( $rows ) { egov_law_monitor_withdrawal_validate_state( $rows[0], $user_id ); }
    return $rows[0] ?? null;
}

function egov_law_monitor_withdrawal_assert_writable( $db, $user_id, $operation = 'save' ) {
    $allowed = egov_law_monitor_withdrawal_check_save( $user_id, $db, $operation );
    if ( is_wp_error( $allowed ) ) { throw new EGOV_Law_Monitor_Withdrawal_Error( $allowed->get_error_message() ); }
}

/** Read-only lookup; an exception is distinct from no process record. */
function egov_law_monitor_withdrawal_state( $user_id, $db = null ) {
    global $wpdb;
    if ( ! is_int( $user_id ) || $user_id < 1 ) { throw new RuntimeException( '退会状態を確認できません。' ); }
    // Cache schema validation only within this request, never a user's state.
    $key = spl_object_id( $db ?: $wpdb ) . ':' . egov_law_monitor_withdrawal_table();
    if ( $db || empty( $GLOBALS['egov_withdrawal_schema_checks'][$key] ) ) {
        if ( ! egov_law_monitor_withdrawal_storage_ready( $db ) ) { throw new RuntimeException( '退会状態を確認できません。' ); }
        if ( ! $db ) { $GLOBALS['egov_withdrawal_schema_checks'][$key] = true; }
    }
    $rows = egov_law_monitor_withdrawal_rows( 'SELECT * FROM `' . egov_law_monitor_withdrawal_table() . '` WHERE user_id = ?', array( $user_id ), $db );
    if ( count( $rows ) > 1 ) { throw new RuntimeException(); }
    if ( $rows ) { egov_law_monitor_withdrawal_validate_state( $rows[0], $user_id ); }
    return $rows[0] ?? null;
}

function egov_law_monitor_withdrawal_validate_state( $row, $user_id ) {
    if ( ! is_array( $row ) || (int) ( $row['user_id'] ?? 0 ) !== $user_id || ! is_string( $row['process_uuid'] ?? null ) ||
        ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $row['process_uuid'] ) ||
        ! in_array( $row['phase'] ?? null, array( 'started', 'cleaned', 'deleting', 'completed' ), true ) ||
        ! preg_match( '/\A[1-9][0-9]*\z/', (string) ( $row['revision'] ?? '' ) ) ) { throw new RuntimeException( '退会状態の形式を確認できません。' ); }
}

/** Best-effort check only: no reservation, transaction or connection is created. */
function egov_law_monitor_withdrawal_check_save( $user_id, $db = null, $operation = 'save' ) {
    try {
        $state = egov_law_monitor_withdrawal_state( (int) $user_id, $db );
        if ( $state === null ) { return true; }
        // Only these existing, authenticated administrator operations are allowed
        // to finish a withdrawal. No client-provided bypass flag is accepted.
        if ( $state['phase'] !== 'completed' && in_array( $operation, array( 'make_free', 'cancellation_admin' ), true ) && current_user_can( 'manage_options' ) ) { return true; }
        return new WP_Error( 'withdrawal_write_refused', '退会処理中または退会完了済みのため変更できません。', array( 'status' => 409 ) );
    } catch ( Throwable $error ) {
        return new WP_Error( 'withdrawal_state_unavailable', '退会状態を確認できないため保存できません。管理者へお問い合わせください。', array( 'status' => 503 ) );
    }
}

/** The callback uses the normal WordPress APIs. In-flight saves can still race. */
function egov_law_monitor_withdrawal_checked_save( $user_id, $callback ) {
    $allowed = egov_law_monitor_withdrawal_check_save( $user_id );
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    return $callback();
}

function egov_law_monitor_withdrawal_status( $state ) {
    if ( $state === null ) { return 'not_started'; }
    egov_law_monitor_withdrawal_validate_state( $state, (int) ( $state['user_id'] ?? 0 ) );
    return $state['phase'] === 'completed' ? 'completed' : 'in_progress';
}

/** Retirement progress CAS only; no start/insert API in this stage. Caller holds withdrawal_lock. */
function egov_law_monitor_withdrawal_compare_update( $db, $before, $phase, $claim_token ) {
    egov_law_monitor_withdrawal_validate_state( $before, (int) ( $before['user_id'] ?? 0 ) );
    if ( ! in_array( $phase, array( 'started', 'cleaned', 'deleting', 'completed' ), true ) || ! is_string( $claim_token ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $claim_token ) ) { throw new RuntimeException(); }
    $phases = array( 'started', 'cleaned', 'deleting', 'completed' );
    $advance = array_search( $phase, $phases, true ) - array_search( $before['phase'], $phases, true );
    if ( $advance < 0 || $advance > 1 || $before['phase'] === 'completed' ) { throw new RuntimeException( '退会進捗を巻き戻す・飛ばすことはできません。' ); }
    $now = gmdate( 'Y-m-d H:i:s' );
    $ok = $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET phase = ?, revision = revision + 1, claim_token = ?, claimed_at = ?, updated_at = ?, completed_at = ? WHERE process_uuid = ? AND user_id = ? AND revision = ?', array( $phase, $claim_token, $now, $now, $phase === 'completed' ? $now : null, $before['process_uuid'], (int) $before['user_id'], (int) $before['revision'] ) );
    if ( ! $ok || $db->affected_rows !== 1 ) { throw new RuntimeException( '退会状態が変更されました。再確認してください。' ); }
}
