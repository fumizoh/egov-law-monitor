<?php
/** Administrator-only withdrawal cleanup. Never calls Square or deletes the user. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class EGOV_Law_Monitor_Withdrawal_Watch_Error extends RuntimeException {}

/** Resolve stage evidence even after another cleanup advances last_audit_id. */
function egov_law_monitor_withdrawal_cleanup_recorded( $user_id, $state, $action, $remaining, $db = null ) {
    if ( empty( $state['last_audit_id'] ) ) { return false; }
    $tables = egov_law_monitor_withdrawal_watch_tables();
    $logs = egov_law_monitor_withdrawal_rows( "SELECT action, new_value FROM `{$tables['log']}` WHERE id = ? AND user_id = ?", array( (int) $state['last_audit_id'], $user_id ), $db );
    if ( count( $logs ) !== 1 ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '段階の監査記録を確認できません。' ); }
    if ( $logs[0]['action'] !== $action ) {
        $logs = egov_law_monitor_withdrawal_rows( "SELECT action, new_value FROM `{$tables['log']}` WHERE user_id = ? AND action = ? AND id >= ? ORDER BY id DESC LIMIT 1", array( $user_id, $action, (int) ( $state['started_audit_id'] ?? 0 ) ), $db );
        if ( count( $logs ) > 1 ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '段階の監査記録を確認できません。' ); }
    }
    $evidence = $logs ? json_decode( $logs[0]['new_value'] ?? '', true ) : null;
    return $logs && $logs[0]['action'] === $action && is_array( $evidence ) && ( $evidence['process_uuid'] ?? null ) === $state['process_uuid'] && ( $evidence['remaining'] ?? null ) === $remaining;
}

function egov_law_monitor_withdrawal_watch_tables() {
    global $wpdb;
    $tables = array( 'watches' => $wpdb->prefix . 'law_watch_settings', 'meta' => $wpdb->usermeta, 'log' => $wpdb->prefix . 'egov_square_admin_log' );
    foreach ( $tables as $name ) { if ( ! is_string( $name ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '保存先を確認できません。' ); } }
    return $tables;
}

/** Strict aggregate reads: failure/invalid values never mean zero. */
function egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state, $db = null ) {
    egov_law_monitor_withdrawal_validate_state( $state, $user_id );
    $tables = egov_law_monitor_withdrawal_watch_tables();
    $counts = array();
    foreach ( array(
        'watches' => array( "SELECT COUNT(*) AS total FROM `{$tables['watches']}` WHERE user_id = ?", array( $user_id ) ),
        'notifications' => array( "SELECT COUNT(*) AS total FROM `{$tables['meta']}` WHERE user_id = ? AND meta_key = ?", array( $user_id, 'egov_law_monitor_notifications' ) ),
    ) as $key => $query ) {
        $rows = egov_law_monitor_withdrawal_rows( $query[0], $query[1], $db );
        $value = $rows[0]['total'] ?? null;
        if ( count( $rows ) !== 1 || ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', (string) $value ) || (string) (int) $value !== (string) $value ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '削除対象件数を確認できません。' ); }
        $counts[$key] = (int) $value;
    }
    $recorded = egov_law_monitor_withdrawal_cleanup_recorded( $user_id, $state, 'withdrawal_watches_deleted', array( 'watches' => 0, 'notifications' => 0 ), $db );
    return array( 'counts' => $counts, 'recorded' => $recorded );
}

function egov_law_monitor_withdrawal_watch_token( $user_id, $state, $snapshot ) {
    return hash_hmac( 'sha256', wp_json_encode( array( $user_id, $state['process_uuid'], (string) $state['revision'], $snapshot['counts'] ) ), wp_salt( 'auth' ) );
}

function egov_law_monitor_delete_withdrawal_watches( $user_id, $fields, $factory = null ) {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', '管理者権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { return new WP_Error( 'invalid_user', '対象外または保護対象のユーザーです。' ); }
    if ( ! is_string( $fields['watch_nonce'] ?? null ) || ! wp_verify_nonce( $fields['watch_nonce'], 'egov_withdrawal_watches_' . $user_id ) ) { return new WP_Error( 'invalid_nonce', '操作を確認できません。再読み込みしてください。' ); }
    if ( ( $fields['confirm_watch_delete'] ?? null ) !== '1' || ! is_string( $fields['watch_token'] ?? null ) ) { return new WP_Error( 'confirmation_required', '削除対象件数と通知設定の削除を確認してください。' ); }
    $db = null; $started = false; $committing = false; $committed = false; $invalidate = false; $rollback_failed = false;
    try {
        $tables = egov_law_monitor_square_plan_tables();
        $local = egov_law_monitor_withdrawal_watch_tables();
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '監査・ユーザー情報の保存先を確認できません。' ); }
        // All mutated tables must support rollback. No DDL/engine conversion here.
        $engine = egov_law_monitor_withdrawal_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $local['watches'], 'BASE TABLE' ), $db );
        if ( count( $engine ) !== 1 || $engine[0]['TABLE_NAME'] !== $local['watches'] || strcasecmp( $engine[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( 'ウォッチ保存先のInnoDBを確認できないため削除できません。' ); }
        if ( ! $db->begin() ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '削除処理を開始できません。' ); }
        $started = true;
        $state = egov_law_monitor_withdrawal_lock( $db, $user_id );
        if ( ! $state || $state['phase'] !== 'started' ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '退会状態がstartedの場合だけ削除できます。' ); }
        wp_cache_delete( $user_id, 'user_meta' );
        if ( ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '対象ユーザーの権限が変更されています。' ); }
        $before = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state, $db );
        if ( ! hash_equals( egov_law_monitor_withdrawal_watch_token( $user_id, $state, $before ), $fields['watch_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '件数または処理状態が変わりました。再読み込みして確認してください。' ); }
        if ( $before['recorded'] && $before['counts'] === array( 'watches' => 0, 'notifications' => 0 ) ) {
            if ( ! $db->rollback() ) { $rollback_failed = true; throw new RuntimeException(); }
            $started = false;
            return array( 'already' => true, 'remaining' => $before['counts'] );
        }
        $invalidate = true;
        if ( ! $db->write( "DELETE FROM `{$local['watches']}` WHERE user_id = ?", array( $user_id ) ) || ! $db->write( "DELETE FROM `{$local['meta']}` WHERE user_id = ? AND meta_key = ?", array( $user_id, 'egov_law_monitor_notifications' ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( 'ウォッチ情報を削除できません。' ); }
        $after = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state, $db );
        if ( $after['counts'] !== array( 'watches' => 0, 'notifications' => 0 ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '残存データがあるため削除完了にできません。' ); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $old_json = wp_json_encode( array( 'process_uuid' => $state['process_uuid'], 'counts' => $before['counts'] ) );
        $new_json = wp_json_encode( array( 'process_uuid' => $state['process_uuid'], 'remaining' => $after['counts'], 'checked_at_utc' => $now, 'phase' => 'started' ) );
        if ( $old_json === false || $new_json === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", array( $user_id, get_current_user_id(), 'withdrawal_watches_deleted', $old_json, $new_json, '管理者がウォッチ全件・通知設定の削除と残存0件を確認', $now ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '監査履歴を保存できません。' ); }
        $audit_id = (int) $db->insert_id;
        if ( $audit_id < 1 || ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET revision = revision + 1, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ?', array( $audit_id, $now, $state['process_uuid'], $user_id, 'started', (int) $state['revision'] ) ) || $db->affected_rows !== 1 ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '段階の記録が競合しました。' ); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( ! $saved || (int) $saved['revision'] !== (int) $state['revision'] + 1 || (int) $saved['last_audit_id'] !== $audit_id ) { throw new EGOV_Law_Monitor_Withdrawal_Watch_Error( '段階の記録を確認できません。' ); }
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException(); }
        $committed = true;
        return array( 'already' => false, 'remaining' => $after['counts'] );
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $ignored ) {} }
        $unknown = $started && ( $committing || ! $rolled_back || $rollback_failed );
        return new WP_Error( $unknown ? 'withdrawal_watches_unknown' : 'withdrawal_watches_failed', $unknown ? '確定・取消結果が不明です。再実行せず状態・件数・監査履歴を再確認してください。' : ( $error instanceof EGOV_Law_Monitor_Withdrawal_Watch_Error ? $error->getMessage() : '削除に必要な情報を確認できません。再読み込みしてください。' ) );
    } finally {
        if ( $db ) { $db->close(); }
        if ( $invalidate ) {
            try { wp_cache_delete( $user_id, 'user_meta' ); }
            catch ( Throwable $ignored ) { if ( $committed ) { return new WP_Error( 'withdrawal_watches_cache_warning', '削除・監査保存は確定しましたが、通知設定のキャッシュを更新できません。再削除せず件数・状態・キャッシュを確認してください。' ); } }
        }
    }
}
