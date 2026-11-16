<?php
/** Actual recovery handlers and SQL, with offline WP/database boundaries only. */
require __DIR__ . '/user-withdrawal-flow-test.php';
class RecoveryDB extends WithdrawalFlowDB {
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'DELETE FROM `wp_egov_law_ai_summary_history`' ) && $this->failure === 'partial' ) { return false; }
        if ( str_starts_with( $sql, 'DELETE' ) && $this->failure === 'delete' ) { return false; }
        if ( str_starts_with( $sql, 'INSERT INTO `wp_egov_square_admin_log`' ) && $this->failure === 'audit' ) { return false; }
        return parent::write( $sql, $p );
    }
}
function recovery_fixture( $kind = 'all' ) {
    list( $base ) = completion_fixture();
    $db = new RecoveryDB(); foreach ( get_object_vars( $base ) as $key => $value ) { $db->$key = $value; }
    $db->next_audit_id = max( array_keys( $db->audit_rows ) ) + 1;
    $store = new WithdrawalFlowStore(); foreach ( get_object_vars( $GLOBALS['wpdb'] ) as $key => $value ) { $store->$key = $value; }
    $GLOBALS['wpdb'] = $store; $GLOBALS['user_db'] = $db;
    if ( in_array( $kind, array( 'all', 'watch' ), true ) ) { $db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); $store->watch_total = '1'; $store->stopped = '1'; }
    if ( in_array( $kind, array( 'all', 'ai' ), true ) ) { $db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); $store->ai = '1'; }
    foreach ( array( 'notification' => 'egov_law_monitor_notifications', 'trial' => 'egov_law_monitor_ai_summary_trial_usage' ) as $type => $key ) { if ( $kind === 'all' || $kind === $type ) { $db->notification_rows[] = array( 'user_id' => 10, 'meta_key' => $key, 'meta_value' => '1' ); } }
    $state = $GLOBALS['withdrawal_records'][10];
    $report = egov_law_monitor_withdrawal_recovery_snapshot( 10, $state, $db );
    return array( $db, array( 'recovery_nonce' => 'valid:egov_withdrawal_recovery_10', 'confirm_recovery' => '1', 'recovery_token' => egov_law_monitor_withdrawal_completion_token( 10, $state, $report ) ) );
}
foreach ( array( 'watch', 'notification', 'ai', 'trial', 'all' ) as $kind ) {
    list( $db, $fields ) = recovery_fixture( $kind ); $state = $withdrawal_records[10];
    $kept = array( $wpdb->contracts, $wpdb->cancellations, $db->shared_cache, egov_law_monitor_withdrawal_user_archive( 10, $state, $db ) );
    $_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
    preflight_check( str_contains( render_preflight(), '削除後の残存データを再清掃' ), 'recovery shown only for validated residuals' );
    preflight_check( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) === true, 'explicit reclean: ' . $kind );
    preflight_check( $withdrawal_records[10]['phase'] === 'deleting' && $withdrawal_records[10]['revision'] === $state['revision'] + 1 && $user_deletion_calls === 1, 'recovery never calls WP deletion or completion' );
    preflight_check( $kept === array( $wpdb->contracts, $wpdb->cancellations, $db->shared_cache, egov_law_monitor_withdrawal_user_archive( 10, $withdrawal_records[10], $db ) ) && count( $db->history ) === 1 && $db->history[0]['user_id'] === 20, 'retained records and other user protected' );
    preflight_check( is_wp_error( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) ), 'stale duplicate denied' );
    $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
    preflight_check( $report['ok'], 'completion valid after explicit reclean' );
    if ( $kind === 'all' ) {
        $db->history[] = array( 'user_id' => 10, 'law_id' => 'second late write' ); $wpdb->ai = '1';
        $report = egov_law_monitor_withdrawal_recovery_snapshot( 10, $withdrawal_records[10], $db );
        $fields['recovery_token'] = egov_law_monitor_withdrawal_completion_token( 10, $withdrawal_records[10], $report );
        preflight_check( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) === true, 'another explicitly confirmed late write can be cleaned' );
        $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
        preflight_check( $report['ok'], 'second cleanup audit remains valid' );
        $latest = $db->audit_rows[$withdrawal_records[10]['last_audit_id']]; $value = json_decode( $latest['new_value'], true );
        $previous_id = $value['previous_audit_id']; $previous = $db->audit_rows[$previous_id]; unset( $db->audit_rows[$previous_id] );
        preflight_check( ! egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db )['ok'], 'missing intermediate recovery audit blocks completion' );
        $db->audit_rows[$previous_id] = $previous; ksort( $db->audit_rows );
    }

    $complete = array( 'complete_nonce' => 'valid:egov_withdrawal_complete_10', 'confirm_complete' => '1', 'complete_token' => egov_law_monitor_withdrawal_completion_token( 10, $withdrawal_records[10], $report ) );
    preflight_check( egov_law_monitor_complete_withdrawal( 10, $complete, fn() => $db ) === true, 'manual completion remains separate' );
}
/** Complete with zero/one/two explicit cleanups, then inspect immutable historical anchors. */
function recovery_completed_chain_fixture( $cleanups ) {
    if ( $cleanups === 0 ) { list( $db ) = completion_fixture(); }
    else {
        list( $db, $fields ) = recovery_fixture();
        preflight_check( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) === true, 'completed-chain fixture first cleanup' );
        if ( $cleanups === 2 ) {
            $db->history[] = array( 'user_id' => 10, 'law_id' => 'late again' ); $GLOBALS['wpdb']->ai = '1';
            $state = $GLOBALS['withdrawal_records'][10];
            $report = egov_law_monitor_withdrawal_recovery_snapshot( 10, $state, $db );
            $fields['recovery_token'] = egov_law_monitor_withdrawal_completion_token( 10, $state, $report );
            preflight_check( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) === true, 'completed-chain fixture second cleanup' );
        }
    }
    $state = $GLOBALS['withdrawal_records'][10];
    $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $state, $db );
    $fields = array( 'complete_nonce' => 'valid:egov_withdrawal_complete_10', 'confirm_complete' => '1', 'complete_token' => egov_law_monitor_withdrawal_completion_token( 10, $state, $report ) );
    preflight_check( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) === true, 'completed-chain fixture completes' );
    $done = json_decode( $db->audit_rows[$GLOBALS['withdrawal_records'][10]['last_audit_id']]['new_value'], true );
    preflight_check( $done['cleanup_chain'] === array( 'head_audit_id' => (int) $state['last_audit_id'], 'revision' => (int) $state['revision'] ), 'completion stores only chain head and pre-completion revision' );
    return array( $db, $fields );
}
foreach ( array( 0, 1, 2 ) as $cleanups ) {
    list( $db ) = recovery_completed_chain_fixture( $cleanups );
    $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
    preflight_check( $report['ok'] && $report['completed'] !== null, 'completed chain valid with cleanup count ' . $cleanups );
}
$chain_faults = array( 'missing_head', 'missing_previous', 'uuid', 'revision', 'order', 'proof', 'anchor_missing', 'head_missing', 'head_type', 'head_order', 'revision_missing', 'anchor_revision', 'state_revision', 'done_uuid' );
foreach ( $chain_faults as $fault ) {
    list( $db, $fields ) = recovery_completed_chain_fixture( 2 );
    $state = $withdrawal_records[10]; $done_id = $state['last_audit_id'];
    $done = json_decode( $db->audit_rows[$done_id]['new_value'], true );
    $head_id = $done['cleanup_chain']['head_audit_id']; $head = json_decode( $db->audit_rows[$head_id]['new_value'], true );
    if ( $fault === 'missing_head' ) { unset( $db->audit_rows[$head_id] ); }
    if ( $fault === 'missing_previous' ) { unset( $db->audit_rows[$head['previous_audit_id']] ); }
    if ( $fault === 'uuid' ) { $head['process_uuid'] = '30000000-0000-4000-8000-000000000000'; }
    if ( $fault === 'revision' ) { $head['revision_before']++; }
    if ( $fault === 'order' ) { $head['previous_audit_id'] = $head_id; }
    if ( $fault === 'proof' ) { $head['proof']['audit_ids'][0]++; }
    if ( in_array( $fault, array( 'uuid', 'revision', 'order', 'proof' ), true ) ) { $db->audit_rows[$head_id]['new_value'] = json_encode( $head ); }
    if ( $fault === 'anchor_missing' ) { unset( $done['cleanup_chain'] ); }
    if ( $fault === 'head_missing' ) { unset( $done['cleanup_chain']['head_audit_id'] ); }
    if ( $fault === 'head_type' ) { $done['cleanup_chain']['head_audit_id'] = (string) $head_id; }
    if ( $fault === 'head_order' ) { $done['cleanup_chain']['head_audit_id'] = (int) $done_id; }
    if ( $fault === 'revision_missing' ) { unset( $done['cleanup_chain']['revision'] ); }
    if ( $fault === 'anchor_revision' ) { $done['cleanup_chain']['revision']++; }
    if ( $fault === 'state_revision' ) { $withdrawal_records[10]['revision']++; }
    if ( $fault === 'done_uuid' ) { $done['process_uuid'] = '30000000-0000-4000-8000-000000000000'; }
    $db->audit_rows[$done_id]['new_value'] = json_encode( $done );
    $records_before = $withdrawal_records; $logs_before = $db->audit_rows;
    $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
    preflight_check( ! $report['ok'] && $report['errors'], 'completed audit fault is detected: ' . $fault );
    ob_start(); egov_law_monitor_render_withdrawal_completion_step( 10, $withdrawal_records[10], false ); $html = ob_get_clean();
    preflight_check( str_contains( $html, '完了不可・要確認' ) && ! str_contains( $html, '>退会完了</button>' ), 'completed audit fault warns without offering repeat completion: ' . $fault );
    preflight_check( is_wp_error( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) ), 'completed process cannot run again' );
    preflight_check( $withdrawal_records === $records_before && $db->audit_rows === $logs_before && $withdrawal_records[10]['completed_at'] === $state['completed_at'], 'read/warning/repeated completion never rewrites historical completion: ' . $fault );
}
// A historical completion without the new anchor is not silently migrated, even without cleanup.
list( $db ) = recovery_completed_chain_fixture( 0 ); $state = $withdrawal_records[10];
$id = $state['last_audit_id']; $old = json_decode( $db->audit_rows[$id]['new_value'], true ); unset( $old['cleanup_chain'] ); $db->audit_rows[$id]['new_value'] = json_encode( $old );
preflight_check( ! egov_law_monitor_withdrawal_completion_snapshot( 10, $state, $db )['ok'] && $withdrawal_records[10] === $state, 'old completion missing verification anchor stops safely without modification' );
echo "Completed cleanup-chain checks passed: 3 normal paths, 14 injected faults and a historical missing-anchor case.\n";

foreach ( array( 'read', 'delete', 'partial', 'audit', 'state', 'commit' ) as $failure ) {
    list( $db, $fields ) = recovery_fixture(); $prior = $withdrawal_records; $logs = $db->audit_rows; $personal = array( $db->watches, $db->history, $db->notification_rows ); $db->failure = $failure;
    preflight_check( is_wp_error( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) ), 'failure stops recovery: ' . $failure );
    if ( $failure !== 'commit' ) { preflight_check( $prior === $withdrawal_records && $logs === $db->audit_rows && $personal === array( $db->watches, $db->history, $db->notification_rows ), 'state and audit rollback' ); }
}
foreach ( array( 'nonce', 'confirm', 'token', 'permission', 'uuid', 'revision', 'phase', 'missing_success', 'missing_start', 'lost_contract', 'lost_consent', 'user_present', 'unknown_result' ) as $fault ) {
    list( $db, $fields ) = recovery_fixture();
    if ( $fault === 'nonce' ) { $fields['recovery_nonce'] = 'bad'; }
    if ( $fault === 'confirm' ) { unset( $fields['confirm_recovery'] ); }
    if ( $fault === 'token' ) { $fields['recovery_token'] = 'bad'; }
    if ( $fault === 'permission' ) { $allowed = false; }
    if ( $fault === 'uuid' ) { $withdrawal_records[10]['process_uuid'] = '20000000-0000-4000-8000-000000000000'; }
    if ( $fault === 'revision' ) { $withdrawal_records[10]['revision']++; }
    if ( $fault === 'phase' ) { $withdrawal_records[10]['phase'] = 'started'; }
    if ( $fault === 'missing_success' ) { unset( $db->audit_rows[completion_action_id( $db, 'withdrawal_user_deleted' )] ); }
    if ( $fault === 'missing_start' ) { unset( $db->audit_rows[1] ); }
    if ( $fault === 'lost_contract' ) { $wpdb->contracts = array(); }
    if ( $fault === 'lost_consent' ) { $id = completion_action_id( $db, 'withdrawal_user_deleting' ); $value = json_decode( $db->audit_rows[$id]['new_value'], true ); unset( $value['evidence']['consent'] ); $db->audit_rows[$id]['new_value'] = json_encode( $value ); }
    if ( $fault === 'user_present' ) { $GLOBALS['withdrawal_test_user_deleted'] = false; }
    if ( $fault === 'unknown_result' ) { $id = completion_action_id( $db, 'withdrawal_user_deleted' ); $value = json_decode( $db->audit_rows[$id]['new_value'], true ); $value['call_error'] = true; $db->audit_rows[$id]['new_value'] = json_encode( $value ); }
    $logs = $db->audit_rows;
    preflight_check( is_wp_error( egov_law_monitor_reclean_withdrawal( 10, $fields, fn() => $db ) ) && $logs === $db->audit_rows && $user_deletion_calls === 1, 'unsafe recovery refused without invented audit: ' . $fault );
    $allowed = true;
}
list( $db, $fields ) = user_delete_fixture();
preflight_check( str_contains( egov_law_monitor_withdrawal_delete_status( 10, $withdrawal_records[10] ), '未実行' ), 'unexecuted distinguished' );
$user_delete_mode = 'false'; egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
preflight_check( str_contains( egov_law_monitor_withdrawal_delete_status( 10, $withdrawal_records[10] ), '明確に失敗' ), 'false result distinguished' );
list( $db, $fields ) = user_delete_fixture(); $db->failure = 'result_audit';
preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $withdrawal_test_user_deleted, 'core succeeds but result audit fails' );
$db->failure = '';
preflight_check( str_contains( egov_law_monitor_withdrawal_delete_status( 10, $withdrawal_records[10] ), '復旧不能' ), 'absence without durable result cannot become success' );
try { egov_law_monitor_withdrawal_recovery_snapshot( 10, $withdrawal_records[10], $db ); preflight_check( false, 'unproven success must stop' ); } catch ( EGOV_Law_Monitor_Withdrawal_User_Error $expected ) {}
list( $db, $fields ) = user_delete_fixture(); $db->gap_owned = true;
egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
preflight_check( $user_deletion_calls === 0 && str_contains( egov_law_monitor_withdrawal_delete_status( 10, $withdrawal_records[10] ), '未実行' ), 'pre-core verification stop is distinguished from core exception' );
list( $db, $fields ) = user_delete_fixture(); $user_delete_mode = 'throw';
egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
preflight_check( str_contains( egov_law_monitor_withdrawal_delete_status( 10, $withdrawal_records[10] ), '結果不明' ), 'throwing core cannot be turned into success' );
list( $db, $fields ) = recovery_fixture();
foreach ( array( 0, -1, '10', 20 ) as $id ) { preflight_check( is_wp_error( egov_law_monitor_reclean_withdrawal( $id, $fields, fn() => $db ) ), 'invalid/different target rejected' ); }
echo "Post-deletion recovery tests passed; simulated DB/WP only, no live integration.\n";
