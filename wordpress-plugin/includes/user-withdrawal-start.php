<?php
/** Manual start only. No cleanup, user deletion, Square API or plan writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/user-withdrawal-retention.php';
class EGOV_Law_Monitor_Withdrawal_Start_Error extends RuntimeException {}

function egov_law_monitor_withdrawal_start_confirmations() {
    return array( 'request' => '退会申請を受け付けている', 'identity' => '本人確認を完了している', 'square' => 'Squareの契約状況を確認した', 'cancelled' => '必要な解約手続きが完了している', 'period' => '利用終了日を経過している、または契約履歴から退会可能と確認できる' );
}

/** Shared display/server rules. DB records are not proof of Square termination. */
function egov_law_monitor_withdrawal_start_evidence( $data ) {
    if ( in_array( null, $data, true ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '読み取りエラーがあるため開始できません。' ); }
    $meta = array();
    foreach ( $data['meta'] as $row ) {
        if ( array_key_exists( $row['meta_key'], $meta ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '重複したユーザー情報があります。' ); }
        $meta[$row['meta_key']] = $row['meta_value'];
    }
    if ( ! in_array( $meta['egov_law_monitor_plan'] ?? '', array( '', 'free' ), true ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '既存の管理機能でFree変更を完了してください。' ); }
    if ( isset( $meta['egov_law_monitor_standard_consent'] ) ) {
        $consent = @unserialize( $meta['egov_law_monitor_standard_consent'], array( 'allowed_classes' => false ) );
        if ( ! is_array( $consent ) || ! is_string( $consent['agreed_at'] ?? null ) || ! is_string( $consent['terms_version'] ?? null ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '同意記録の形式を確認してください。' ); }
    }
    if ( (string) $data['pending'][0]['total'] !== '0' ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '未完了の解約申請があります。' ); }
    foreach ( array( 'contracts', 'cancellations', 'legacy' ) as $key ) {
        if ( is_array( $data[$key] ) && count( $data[$key] ) >= 100 ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '確認件数の上限に達しています。開始できません。' ); }
    }
    if ( is_array( $data['legacy'] ) ) { foreach ( $data['legacy'] as $row ) { if ( ( $row['state'] ?? '' ) !== 'closed' ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '旧申込の未解決記録があります。' ); } } }
    $ends = array();
    foreach ( $data['cancellations'] as $row ) {
        $date = $row['end_date'] ?? null;
        $parsed = is_string( $date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() ) : false;
        if ( empty( $row['completed_at'] ) || ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date >= current_datetime()->format( 'Y-m-d' ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '解約の完了・利用終了日を確認できません。' ); }
        $ends[] = array_intersect_key( $row, array_flip( array( 'id', 'contract_id', 'approval_id', 'end_date', 'completed_at' ) ) );
    }
    $contracts = array();
    foreach ( $data['contracts'] as $row ) {
        if ( empty( $row['square_subscription_id'] ) || empty( $row['square_customer_id'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '契約識別情報が不足しています。' ); }
        $contracts[] = array_intersect_key( $row, array_flip( array( 'id', 'square_subscription_id', 'square_customer_id' ) ) );
    }
    return array( 'plan' => 'free', 'contracts' => $contracts, 'cancellations' => $ends, 'legacy_closed_count' => is_array( $data['legacy'] ) ? count( $data['legacy'] ) : 0 );
}

function egov_law_monitor_start_withdrawal( $user_id, $fields, $factory = null ) {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', '管理者権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { return new WP_Error( 'invalid_user', '対象ユーザーを確認できません。保護対象は開始できません。' ); }
    if ( ! is_string( $fields['withdrawal_nonce'] ?? null ) || ! wp_verify_nonce( $fields['withdrawal_nonce'], 'egov_withdrawal_start_' . $user_id ) ) { return new WP_Error( 'invalid_nonce', '操作を確認できません。再読み込みしてください。' ); }
    foreach ( egov_law_monitor_withdrawal_start_confirmations() as $key => $label ) { if ( ( $fields['confirm_' . $key] ?? null ) !== '1' ) { return new WP_Error( 'confirmation_required', 'すべての管理者確認が必要です。' ); } }
    $reason = $fields['withdrawal_reason'] ?? null;
    if ( ! is_string( $reason ) || strlen( $reason ) > 3000 || trim( sanitize_textarea_field( $reason ) ) === '' ) { return new WP_Error( 'reason_required', '本人確認・Square確認の根拠を入力してください。不要な個人情報は記載しないでください。' ); }
    if ( function_exists( 'egov_law_monitor_withdrawal_request_start_check' ) ) {
        $ready = egov_law_monitor_withdrawal_request_start_check( $user_id );
        if ( is_wp_error( $ready ) ) { return $ready; }
    }
    $db = null; $started = false; $committing = false;
    try {
        $tables = egov_law_monitor_square_plan_tables();
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) || ! egov_law_monitor_cancellation_storage_ready( $db ) || ! $db->begin() ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '保存先を確認できません。' ); }
        $started = true;
        if ( egov_law_monitor_withdrawal_lock( $db, $user_id ) !== null ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '既に退会処理が開始されています。再実行できません。' ); }
        if ( function_exists( 'egov_law_monitor_withdrawal_request_start_check' ) ) {
            $ready = egov_law_monitor_withdrawal_request_start_check( $user_id );
            if ( is_wp_error( $ready ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( $ready->get_error_message() ); }
        }
        // Refresh target metadata after the existing users-row lock; no plan mutation.
        wp_cache_delete( $user_id, 'user_meta' );
        if ( ! egov_law_monitor_withdrawal_target( get_userdata( $user_id ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '対象アカウントが変更されています。' ); }
        $data = egov_law_monitor_withdrawal_check_data( $user_id, $db );
        $evidence = egov_law_monitor_withdrawal_start_evidence( $data );
        $manifest = egov_law_monitor_withdrawal_retention_manifest( $user_id, $data );
        $uuid = wp_generate_uuid4(); $now = gmdate( 'Y-m-d H:i:s' );
        $json = wp_json_encode( array( 'process_uuid' => $uuid, 'phase' => 'started', 'confirmed_at_utc' => $now, 'confirmations' => array_keys( egov_law_monitor_withdrawal_start_confirmations() ), 'manual_evidence_only' => true, 'target_validation' => array( 'subscriber_only' => true, 'protected_check_passed' => true ), 'saved_evidence' => $evidence, 'retention_manifest' => $manifest ) );
        if ( $json === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", array( $user_id, get_current_user_id(), 'withdrawal_started', null, $json, sanitize_textarea_field( $reason ), $now ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '監査履歴を保存できません。' ); }
        $audit_id = (int) $db->insert_id;
        if ( $audit_id < 1 || ! $db->write( 'INSERT INTO `' . egov_law_monitor_withdrawal_table() . '` (process_uuid, user_id, phase, revision, started_audit_id, last_audit_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array( $uuid, $user_id, 'started', 1, $audit_id, $audit_id, $now, $now ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '退会開始を保存できません。' ); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( ! $saved || $saved['process_uuid'] !== $uuid || $saved['phase'] !== 'started' || (int) $saved['started_audit_id'] !== $audit_id ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error( '開始記録を確認できません。' ); }
        $committing = true;
        if ( ! $db->commit() ) { throw new EGOV_Law_Monitor_Withdrawal_Start_Error(); }
        return true;
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $ignored ) {} }
        $unknown = $started && ( $committing || ! $rolled_back );
        return new WP_Error( $unknown ? 'withdrawal_outcome_unknown' : 'withdrawal_start_failed', $unknown ? '確定・取り消し結果が不明です。再実行せず、再読み込みして退会状態と監査履歴を確認してください。' : ( $error instanceof EGOV_Law_Monitor_Withdrawal_Start_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Retention_Error ? $error->getMessage() : '退会開始を保存できません。再読み込みして確認してください。' ) );
    } finally { if ( $db ) { $db->close(); } }
}
