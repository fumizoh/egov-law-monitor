<?php
/** Local service cancellation; never changes Square or automatically changes plans. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/square-cancellation-storage.php';

function egov_law_monitor_cancellation_date_valid( $date ) {
    if ( ! is_string( $date ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) || substr( $date, 0, 4 ) < '1000' ) { return false; }
    $value = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
    return $value !== false && $value->format( 'Y-m-d' ) === $date;
}

function egov_law_monitor_cancellation_domain( $row ) {
    return array_intersect_key( $row, array_flip( array( 'id', 'user_id', 'contract_id', 'approval_id', 'requested_at', 'end_date', 'completed_at', 'revision' ) ) );
}

function egov_law_monitor_cancellation_token( $row ) {
    return egov_law_monitor_square_record_token( egov_law_monitor_cancellation_domain( $row ) );
}

function egov_law_monitor_cancellation_audit( $db, $tables, $action, $before, $after, $reason, $actor_id ) {
    $old_json = $before === null ? null : wp_json_encode( $before ); $new_json = wp_json_encode( $after );
    if ( $old_json === false || $new_json === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", array( (int) $after['user_id'], $actor_id, $action, $old_json, $new_json, $reason, current_time( 'mysql' ) ) ) ) { throw new RuntimeException(); }
}

/** Select the explicitly approved contract, never the first of multiple contracts. */
function egov_law_monitor_cancellation_context( $user_id, $db = null ) {
    $tables = egov_law_monitor_square_plan_tables();
    $snapshot = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
    if ( $snapshot['plan'] !== 'standard' ) { throw new RuntimeException( 'Standardではありません。' ); }
    $logs = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$tables['log']}` WHERE id = ? AND user_id = ?", array( (int) $snapshot['revision'], $user_id ), $db );
    $log = $logs[0] ?? null; $data = $log ? json_decode( $log['new_value'], true ) : null;
    $evidence = is_array( $data ) ? ( $data['contract'] ?? null ) : null;
    if ( ! $log || $log['action'] !== 'standard_approved' || ! is_array( $evidence ) || (int) ( $evidence['user_id'] ?? 0 ) !== $user_id || (int) ( $evidence['id'] ?? 0 ) < 1 ) { throw new RuntimeException( '承認対象の契約を特定できません。' ); }
    $contracts = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$tables['contracts']}` WHERE id = ? AND user_id = ?" . ( $db ? ' FOR UPDATE' : '' ), array( (int) $evidence['id'], $user_id ), $db );
    $contract = $contracts[0] ?? null;
    if ( ! $contract || (int) $contract['user_id'] !== $user_id || $contract['square_subscription_id'] !== ( $evidence['square_subscription_id'] ?? null ) || $contract['square_customer_id'] !== ( $evidence['square_customer_id'] ?? null ) ) { throw new RuntimeException( '承認履歴と契約が一致しません。' ); }
    return array( 'approval_id' => (int) $snapshot['revision'], 'contract' => $contract, 'plan_token' => egov_law_monitor_square_plan_token( $user_id, $snapshot, $contract ) );
}

function egov_law_monitor_request_subscription_cancellation( $nonce, $token, $factory = null ) {
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_standard_cancel_' . $user_id ) || ! is_string( $token ) ) { return new WP_Error( 'cancellation_forbidden', 'ログイン状態と操作情報を確認し、画面を開き直してください。' ); }
    return egov_law_monitor_cancellation_transaction( $user_id, static function ( $db, $tables, $table ) use ( $user_id, $token ) {
        $context = egov_law_monitor_cancellation_context( $user_id, $db );
        if ( ! hash_equals( $context['plan_token'], $token ) ) { throw new RuntimeException(); }
        $rows = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE approval_id = ? FOR UPDATE", array( $context['approval_id'] ), $db );
        if ( $rows ) {
            if ( count( $rows ) !== 1 || (int) $rows[0]['user_id'] !== $user_id || (int) $rows[0]['contract_id'] !== (int) $context['contract']['id'] ) { throw new RuntimeException(); }
            return array( 'id' => (int) $rows[0]['id'], 'already' => true );
        }
        $now = egov_law_monitor_cancellation_now();
        if ( ! $db->write( "INSERT INTO `{$table}` (user_id, contract_id, approval_id, requested_at, updated_at, revision, user_notified_revision) VALUES (?, ?, ?, ?, ?, 1, 0)", array( $user_id, (int) $context['contract']['id'], $context['approval_id'], $now, $now ) ) ) { throw new RuntimeException(); }
        $id = (int) $db->insert_id;
        $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ?", array( $id, $user_id ), $db )[0] ?? null;
        if ( ! $row ) { throw new RuntimeException(); }
        egov_law_monitor_cancellation_audit( $db, $tables, 'subscription_cancellation_requested', null, egov_law_monitor_cancellation_domain( $row ) + array( 'actor_type' => 'user', 'actor_user_id' => $user_id ), null, 0 );
        return array( 'id' => $id, 'already' => false );
    }, $factory );
}

function egov_law_monitor_save_cancellation_date( $user_id, $id, $date, $nonce, $token, $confirmed, $reason, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) || ! is_int( $user_id ) || $user_id < 1 || ! is_int( $id ) || $id < 1 || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_cancel_date_' . $user_id . '_' . $id ) ) { return new WP_Error( 'cancellation_forbidden', '権限または操作情報を確認できません。' ); }
    if ( ! egov_law_monitor_cancellation_date_valid( $date ) || $confirmed !== true || ! is_string( $token ) || ! is_string( $reason ) || strlen( $reason ) > 6000 || trim( sanitize_textarea_field( $reason ) ) === '' ) { return new WP_Error( 'cancellation_invalid_date', '正しい終了日、Square Dashboardでの確認チェック、確認理由を入力してください。' ); }
    return egov_law_monitor_cancellation_transaction( $user_id, static function ( $db, $tables, $table ) use ( $user_id, $id, $date, $token, $reason ) {
        $old = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ? FOR UPDATE", array( $id, $user_id ), $db )[0] ?? null;
        $context = egov_law_monitor_cancellation_context( $user_id, $db );
        if ( ! $old || $old['completed_at'] !== null || (int) $old['approval_id'] !== $context['approval_id'] || (int) $old['contract_id'] !== (int) $context['contract']['id'] || ! hash_equals( egov_law_monitor_cancellation_token( $old ), $token ) ) { throw new RuntimeException(); }
        if ( $old['end_date'] === $date ) { return array( 'id' => $id, 'unchanged' => true ); }
        if ( ! $db->write( "UPDATE `{$table}` SET end_date = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND user_id = ?", array( $date, egov_law_monitor_cancellation_now(), $id, $user_id ) ) ) { throw new RuntimeException(); }
        $new = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ?", array( $id, $user_id ), $db )[0] ?? null;
        if ( ! $new || $new['end_date'] !== $date || (int) $new['revision'] !== (int) $old['revision'] + 1 ) { throw new RuntimeException(); }
        egov_law_monitor_cancellation_audit( $db, $tables, $old['end_date'] === null ? 'subscription_end_date_set' : 'subscription_end_date_changed', egov_law_monitor_cancellation_domain( $old ), egov_law_monitor_cancellation_domain( $new ) + array( 'dashboard_confirmed' => true ), sanitize_textarea_field( $reason ), (int) get_current_user_id() );
        return array( 'id' => $id, 'unchanged' => false );
    }, $factory );
}

/** Runs inside the existing Free transaction; never begins a nested transaction. */
function egov_law_monitor_complete_cancellations_for_free( $db, $tables, $user_id, $reason ) {
    if ( ! egov_law_monitor_cancellation_table_exists( $db ) ) { return; }
    if ( ! egov_law_monitor_cancellation_storage_ready( $db ) ) { throw new RuntimeException( '解約申請の保存先を確認できません。' ); }
    $table = egov_law_monitor_cancellation_table();
    $rows = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE user_id = ? AND completed_at IS NULL FOR UPDATE", array( $user_id ), $db );
    foreach ( $rows as $old ) {
        if ( ! $db->write( "UPDATE `{$table}` SET completed_at = ?, updated_at = ?, revision = revision + 1, mail_claim_token = NULL, mail_claimed_at = NULL WHERE id = ? AND user_id = ?", array( egov_law_monitor_cancellation_now(), egov_law_monitor_cancellation_now(), (int) $old['id'], $user_id ) ) ) { throw new RuntimeException(); }
        $new = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE id = ? AND user_id = ?", array( (int) $old['id'], $user_id ), $db )[0] ?? null;
        if ( ! $new || $new['completed_at'] === null ) { throw new RuntimeException(); }
        egov_law_monitor_cancellation_audit( $db, $tables, 'subscription_cancellation_completed', egov_law_monitor_cancellation_domain( $old ) + array( 'plan' => 'standard' ), egov_law_monitor_cancellation_domain( $new ) + array( 'plan' => 'free' ), $reason, (int) get_current_user_id() );
    }
}

function egov_law_monitor_cancellation_state( $row, $plan, $today = null ) {
    if ( ! $row ) { return 'none'; }
    if ( $row['completed_at'] !== null || $plan === 'free' ) { return 'completed'; }
    if ( $row['end_date'] === null ) { return 'requested'; }
    if ( ! egov_law_monitor_cancellation_date_valid( $row['end_date'] ) ) { return 'invalid'; }
    $today = $today ?? current_datetime()->format( 'Y-m-d' );
    return $row['end_date'] < $today ? 'due' : 'scheduled';
}

require_once __DIR__ . '/square-cancellation-mail.php';
require_once __DIR__ . '/square-cancellation-page.php';
