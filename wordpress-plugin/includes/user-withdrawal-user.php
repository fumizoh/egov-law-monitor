<?php
/** WordPress deletion is deliberately OUTSIDE the audit/state transactions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/user-withdrawal-retention.php';
class EGOV_Law_Monitor_Withdrawal_User_Error extends RuntimeException {}

function egov_law_monitor_withdrawal_retained_fingerprint( $data ) {
    $sets = array();
    foreach ( array( 'contracts', 'cancellations', 'legacy' ) as $key ) { $sets[$key] = is_array( $data[$key] ) ? egov_law_monitor_withdrawal_canonical_rows( $data[$key] ) : $data[$key]; }
    $json = wp_json_encode( $sets );
    if ( $json === false ) { throw new RuntimeException(); }
    return hash( 'sha256', $json );
}

/** Uncached SQL, not get_userdata(false): failed reads never prove absence. */
function egov_law_monitor_withdrawal_user_exists( $user_id, $db = null ) {
    $tables = egov_law_monitor_square_plan_tables();
    $rows = egov_law_monitor_withdrawal_rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ?", array( $user_id ), $db );
    if ( count( $rows ) > 1 || ( $rows && (int) $rows[0]['ID'] !== $user_id ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( 'ユーザーの存在を確認できません。' ); }
    return count( $rows ) === 1;
}

function egov_law_monitor_withdrawal_user_evidence( $user_id, $state, $db = null ) {
    if ( ! $state || $state['phase'] !== 'started' ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '退会状態がstartedの場合のみ削除できます。' ); }
    $watch = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state, $db );
    $ai = egov_law_monitor_withdrawal_ai_snapshot( $user_id, $state, $db );
    if ( ! $watch['recorded'] || $watch['counts'] !== array( 'watches' => 0, 'notifications' => 0 ) || ! $ai['recorded'] || $ai['counts'] !== array( 'history' => 0, 'trial' => 0 ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '手順5・6の同じ退会処理の監査と残存0件を確認してください。' ); }
    $data = egov_law_monitor_withdrawal_check_data( $user_id, $db );
    $square = egov_law_monitor_withdrawal_start_evidence( $data );
    $retention = egov_law_monitor_withdrawal_verify_retention( $user_id, $state, $data, $db );
    $square['contracts'] = egov_law_monitor_withdrawal_canonical_rows( $square['contracts'] );
    $square['cancellations'] = egov_law_monitor_withdrawal_canonical_rows( $square['cancellations'] );
    if ( $data['posts'] || (string) $data['comments'][0]['total'] !== '0' || (string) $data['links'][0]['total'] !== '0' ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '投稿・メディア・コメント・リンク等が残っています。先に整理してください。' ); }
    $consent = null;
    foreach ( $data['meta'] as $row ) { if ( $row['meta_key'] === 'egov_law_monitor_standard_consent' ) { $consent = @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) ); } }
    $tables = egov_law_monitor_square_plan_tables();
    $approvals = egov_law_monitor_withdrawal_rows( "SELECT id FROM `{$tables['log']}` WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT 1", array( $user_id, 'standard_approved' ), $db );
    if ( ( $data['contracts'] || $approvals ) && $consent === null ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( 'Standard利用の同意記録がありません。削除前に保持方法を確認してください。' ); }
    if ( $consent !== null && ( ! is_array( $consent ) || ! is_string( $consent['agreed_at'] ?? null ) || ! is_string( $consent['terms_version'] ?? null ) || ( isset( $consent['conditions_version'] ) && ! is_string( $consent['conditions_version'] ) ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '同意記録の形式を確認できません。' ); }
    if ( $consent !== null && ( trim( $consent['agreed_at'] ) === '' || trim( $consent['terms_version'] ) === '' ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '同意日時・規約バージョンが空です。' ); }
    // Only the established consent fields; no credentials, names or email copies.
    $consent = $consent === null ? null : array_intersect_key( $consent, array_flip( array( 'agreed_at', 'terms_version', 'conditions_version' ) ) );
    return array( 'square' => $square, 'consent' => $consent, 'consent_required' => $consent !== null || (bool) $data['contracts'] || (bool) $approvals, 'watch_remaining' => $watch['counts'], 'ai_remaining' => $ai['counts'], 'ownership' => array( 'posts' => 0, 'comments' => 0, 'links' => 0 ), 'retained_hash' => egov_law_monitor_withdrawal_retained_fingerprint( $data ), 'retention' => $retention );
}

function egov_law_monitor_withdrawal_user_token( $user_id, $state, $evidence ) {
    $json = wp_json_encode( array( $user_id, $state['process_uuid'], (string) $state['revision'], $evidence ) );
    if ( $json === false ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '確認情報を署名できません。' ); }
    return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
}

function egov_law_monitor_withdrawal_user_archive( $user_id, $state, $db = null ) {
    $tables = egov_law_monitor_square_plan_tables();
    $rows = egov_law_monitor_withdrawal_rows( "SELECT action, new_value FROM `{$tables['log']}` WHERE user_id = ? AND action = ? AND id >= ? ORDER BY id DESC LIMIT 1", array( $user_id, 'withdrawal_user_deleting', (int) ( $state['started_audit_id'] ?? 0 ) ), $db );
    $archive = count( $rows ) === 1 ? json_decode( $rows[0]['new_value'] ?? '', true ) : null;
    if ( ! is_array( $archive ) || ( $archive['process_uuid'] ?? null ) !== $state['process_uuid'] || ! is_array( $archive['evidence'] ?? null ) || ! array_key_exists( 'consent', $archive['evidence'] ) || ! is_string( $archive['evidence']['retained_hash'] ?? null ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除前の保持監査を確認できません。' ); }
    return $archive;
}

/** Owned short local transaction; permits an absent users row only for result recording. */
function egov_law_monitor_withdrawal_user_transaction( $user_id, $callback, $factory = null ) {
    global $wpdb;
    $db = null; $started = false; $committing = false;
    try {
        $tables = egov_law_monitor_square_plan_tables();
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) || ! egov_law_monitor_withdrawal_storage_ready( $db ) || ! $db->begin() ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '退会・監査の保存先を確認できません。' ); }
        $started = true;
        egov_law_monitor_withdrawal_rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ), $db );
        $rows = egov_law_monitor_withdrawal_rows( 'SELECT * FROM `' . egov_law_monitor_withdrawal_table() . '` WHERE user_id = ? FOR UPDATE', array( $user_id ), $db );
        if ( count( $rows ) !== 1 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '退会処理の記録を確認できません。' ); }
        egov_law_monitor_withdrawal_validate_state( $rows[0], $user_id );
        $result = $callback( $db, $tables, $rows[0] );
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException(); }
        return $result;
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $ignored ) {} }
        $unknown = $started && ( $committing || ! $rolled_back );
        return new WP_Error( $unknown ? 'withdrawal_user_unknown' : 'withdrawal_user_failed', $unknown ? '確定・取消結果が不明です。再削除せず退会状態と監査記録を再確認してください。' : ( $error instanceof EGOV_Law_Monitor_Withdrawal_User_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Start_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Retention_Error ? $error->getMessage() : '削除に必要な保存情報を確認できません。' ) );
    } finally { if ( $db ) { $db->close(); } }
}

function egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, $action, $value, $reason = '管理者の手動退会操作。WordPressユーザー削除段階' ) {
    $json = wp_json_encode( $value );
    if ( $json === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", array( $user_id, get_current_user_id(), $action, null, $json, $reason, gmdate( 'Y-m-d H:i:s' ) ) ) || (int) $db->insert_id < 1 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '監査を保存できません。' ); }
    return (int) $db->insert_id;
}

function egov_law_monitor_delete_withdrawal_user( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_users' ) ) { return new WP_Error( 'forbidden', '管理・ユーザー削除権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { return new WP_Error( 'invalid_user', '存在する一般会員のみ対象です。保護対象・削除済みユーザーは削除できません。' ); }
    if ( ! is_string( $fields['user_nonce'] ?? null ) || ! wp_verify_nonce( $fields['user_nonce'], 'egov_withdrawal_user_' . $user_id ) ) { return new WP_Error( 'invalid_nonce', '操作を確認できません。再読み込みしてください。' ); }
    if ( ( $fields['confirm_user_delete'] ?? null ) !== '1' || ! is_string( $fields['user_token'] ?? null ) ) { return new WP_Error( 'confirmation_required', 'ユーザー削除の最終確認が必要です。' ); }
    $prepared = egov_law_monitor_withdrawal_user_transaction( $user_id, static function ( $db, $tables, $state ) use ( $user_id, $fields ) {
        if ( ! egov_law_monitor_withdrawal_user_exists( $user_id, $db ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '対象ユーザーが存在しません。' ); }
        wp_cache_delete( $user_id, 'user_meta' );
        if ( ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '対象ユーザーの権限が変わりました。' ); }
        $evidence = egov_law_monitor_withdrawal_user_evidence( $user_id, $state, $db );
        if ( ! empty( $state['claim_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '退会操作の実行権が残っています。先に状態を確認してください。' ); }
        if ( ! hash_equals( egov_law_monitor_withdrawal_user_token( $user_id, $state, $evidence ), $fields['user_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '確認情報が変わりました。再読み込みしてください。' ); }
        $claim = bin2hex( random_bytes( 16 ) ); $now = gmdate( 'Y-m-d H:i:s' );
        $audit = egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, 'withdrawal_user_deleting', array( 'process_uuid' => $state['process_uuid'], 'phase' => 'deleting', 'checked_at_utc' => $now, 'target_validation' => array( 'subscriber_only' => true, 'protected_check_passed' => true ), 'evidence' => $evidence ) );
        // Dedicated transition, only after both cleanup stages and ownership checks.
        if ( ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET phase = ?, revision = revision + 1, claim_token = ?, claimed_at = ?, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ?', array( 'deleting', $claim, $now, $audit, $now, $state['process_uuid'], $user_id, 'started', (int) $state['revision'] ) ) || $db->affected_rows !== 1 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除開始が競合しました。' ); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( ! $saved || $saved['phase'] !== 'deleting' || $saved['claim_token'] !== $claim || (int) $saved['last_audit_id'] !== $audit ) { throw new RuntimeException(); }
        return array( 'state' => $saved, 'evidence' => $evidence );
    }, $factory );
    if ( is_wp_error( $prepared ) ) { return $prepared; }
    // No owned DB transaction survives this point. Do not retry this call automatically.
    $wp_result = false; $call_error = false; $core_called = false;
    try {
        $current = egov_law_monitor_withdrawal_state( $user_id );
        if ( ! $current || $current['phase'] !== 'deleting' || $current['claim_token'] !== $prepared['state']['claim_token'] || (string) $current['revision'] !== (string) $prepared['state']['revision'] ) { throw new RuntimeException(); }
        // Re-check the final gap, retaining the started validation semantics for evidence.
        $check = $current; $check['phase'] = 'started';
        wp_cache_delete( $user_id, 'user_meta' );
        if ( ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) || ! egov_law_monitor_withdrawal_user_exists( $user_id ) || egov_law_monitor_withdrawal_user_evidence( $user_id, $check ) !== $prepared['evidence'] ) { throw new RuntimeException(); }
        if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        // Intentionally internal: the ordinary map_meta_cap deletion ban is unchanged.
        $core_called = true;
        $wp_result = wp_delete_user( $user_id, null ) === true;
    } catch ( Throwable $ignored ) { $call_error = true; }
    return egov_law_monitor_withdrawal_user_result( $user_id, $prepared, $wp_result, $call_error, $factory, $core_called );
}

function egov_law_monitor_withdrawal_user_result( $user_id, $prepared, $wp_result, $call_error, $factory = null, $core_called = true ) {
    $recorded = egov_law_monitor_withdrawal_user_transaction( $user_id, static function ( $db, $tables, $state ) use ( $user_id, $prepared, $wp_result, $call_error, $core_called ) {
        if ( $state['phase'] !== 'deleting' || $state['process_uuid'] !== $prepared['state']['process_uuid'] || $state['claim_token'] !== $prepared['state']['claim_token'] || (string) $state['revision'] !== (string) $prepared['state']['revision'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除後の状態が競合しました。再確認してください。' ); }
        $exists = egov_law_monitor_withdrawal_user_exists( $user_id, $db );
        $archive = egov_law_monitor_withdrawal_user_archive( $user_id, $state, $db );
        if ( $archive['evidence'] !== $prepared['evidence'] ) { throw new RuntimeException(); }
        $data = egov_law_monitor_withdrawal_check_data( $user_id, $db );
        if ( in_array( null, $data, true ) ) { throw new RuntimeException(); }
        egov_law_monitor_withdrawal_verify_retention( $user_id, $state, $data, $db, array( 'evidence' => $prepared['evidence'], 'audit_id' => (int) $prepared['state']['last_audit_id'] ) );
        $retained = true;
        $audit = egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, $exists ? 'withdrawal_user_delete_failed' : 'withdrawal_user_deleted', array( 'process_uuid' => $state['process_uuid'], 'pre_delete_audit_id' => (int) $prepared['state']['last_audit_id'], 'preserved_evidence_hash' => hash( 'sha256', wp_json_encode( $archive['evidence'] ) ), 'user_exists' => $exists, 'wp_result' => $wp_result, 'call_error' => $call_error, 'core_called' => $core_called, 'retained_contracts_match' => $retained, 'phase' => 'deleting' ) );
        if ( ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET revision = revision + 1, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ? AND claim_token = ?', array( $audit, gmdate( 'Y-m-d H:i:s' ), $state['process_uuid'], $user_id, 'deleting', (int) $state['revision'], $state['claim_token'] ) ) || $db->affected_rows !== 1 ) { throw new RuntimeException(); }
        return array( 'deleted' => ! $exists, 'retained' => $retained, 'wp_result' => $wp_result && ! $call_error );
    }, $factory );
    if ( is_wp_error( $recorded ) ) { return new WP_Error( 'withdrawal_user_result_unknown', '削除結果の記録を確定できません。WordPressユーザーが既に削除されている可能性があります。再削除せず同じ画面で存在・監査・残存を確認してください。' ); }
    if ( ! $recorded['deleted'] || ! $recorded['retained'] || ! $recorded['wp_result'] ) { return new WP_Error( 'withdrawal_user_needs_review', '削除結果または保持記録に確認が必要です。deletingを維持しています。自動再実行せず、同じ画面で再確認してください。' ); }
    return $recorded;
}

/** Only a recorded, returned-false core call may be re-armed. Never a timed unlock. */
function egov_law_monitor_withdrawal_user_recovery_evidence( $user_id, $state, $db = null ) {
    if ( $state['phase'] !== 'deleting' || empty( $state['claim_token'] ) || ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) || ! egov_law_monitor_withdrawal_user_exists( $user_id, $db ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除再準備の対象ではありません。' ); }
    $tables = egov_law_monitor_square_plan_tables();
    $logs = egov_law_monitor_withdrawal_rows( "SELECT action, new_value FROM `{$tables['log']}` WHERE id = ? AND user_id = ?", array( (int) $state['last_audit_id'], $user_id ), $db );
    $value = count( $logs ) === 1 ? json_decode( $logs[0]['new_value'] ?? '', true ) : null;
    if ( ! $value || $logs[0]['action'] !== 'withdrawal_user_delete_failed' || ( $value['process_uuid'] ?? null ) !== $state['process_uuid'] || ( $value['user_exists'] ?? null ) !== true || ( $value['wp_result'] ?? null ) !== false || ( $value['call_error'] ?? null ) !== false ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '元の削除処理の終了・失敗を確定できません。実行中・結果不明の処理は解除しません。' ); }
    $check = $state; $check['phase'] = 'started';
    $evidence = egov_law_monitor_withdrawal_user_evidence( $user_id, $check, $db );
    if ( $evidence !== egov_law_monitor_withdrawal_user_archive( $user_id, $state, $db )['evidence'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除前の確認情報から変更があります。再準備を停止しています。' ); }
    return $evidence;
}

function egov_law_monitor_rearm_withdrawal_user( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_users' ) ) { return new WP_Error( 'forbidden', '管理・削除権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! is_string( $fields['rearm_nonce'] ?? null ) || ! wp_verify_nonce( $fields['rearm_nonce'], 'egov_withdrawal_rearm_' . $user_id ) || ( $fields['confirm_rearm'] ?? null ) !== '1' || ! is_string( $fields['rearm_token'] ?? null ) ) { return new WP_Error( 'invalid_rearm', '削除再準備の確認・nonceが不正です。' ); }
    return egov_law_monitor_withdrawal_user_transaction( $user_id, static function ( $db, $tables, $state ) use ( $user_id, $fields ) {
        wp_cache_delete( $user_id, 'user_meta' );
        $evidence = egov_law_monitor_withdrawal_user_recovery_evidence( $user_id, $state, $db );
        if ( ! hash_equals( egov_law_monitor_withdrawal_user_token( $user_id, $state, $evidence ), $fields['rearm_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '再準備の確認情報が変わりました。' ); }
        $audit = egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, 'withdrawal_user_delete_rearmed', array( 'process_uuid' => $state['process_uuid'], 'confirmed_failure_audit_id' => (int) $state['last_audit_id'], 'phase' => 'started' ) );
        if ( ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET phase = ?, revision = revision + 1, claim_token = NULL, claimed_at = NULL, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ? AND claim_token = ?', array( 'started', $audit, gmdate( 'Y-m-d H:i:s' ), $state['process_uuid'], $user_id, 'deleting', (int) $state['revision'], $state['claim_token'] ) ) || $db->affected_rows !== 1 ) { throw new RuntimeException(); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( ! $saved || $saved['phase'] !== 'started' || ! empty( $saved['claim_token'] ) || (int) $saved['last_audit_id'] !== $audit ) { throw new RuntimeException(); }
        return true;
    }, $factory );
}
