<?php
/** Review-only integration: actual step handlers, simulated SQL/WP boundaries (NOT live WP/MySQL). */
require __DIR__ . '/user-withdrawal-completion-test.php';
class WithdrawalFlowDB extends CompletionDB {
    public $next_audit_id = 6;
    public $retention_read_failure = false;
    function rows( $sql, $p = array() ) {
        if ( $this->retention_read_failure && str_starts_with( $sql, 'SELECT action, new_value' ) ) { $this->last_error = 'private retention error'; return false; }
        return parent::rows( $sql, $p );
    }
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'INSERT INTO `wp_egov_square_admin_log`' ) ) {
            // AUTO_INCREMENT must not reuse a deleted audit ID (unlike count-based fixtures).
            $id = $this->next_audit_id++;
            $this->audit_rows[$id] = array( 'user_id' => $p[0], 'admin_user_id' => $p[1], 'action' => $p[2], 'old_value' => $p[3], 'new_value' => $p[4], 'reason' => $p[5], 'created_at' => $p[6] );
            $this->insert_id = $id; return true;
        }
        if ( str_starts_with( $sql, 'INSERT INTO `wp_egov_user_withdrawals`' ) ) {
            if ( isset( $GLOBALS['withdrawal_records'][$p[1]] ) ) { return false; }
            $GLOBALS['withdrawal_records'][$p[1]] = array( 'process_uuid' => $p[0], 'user_id' => $p[1], 'phase' => $p[2], 'revision' => $p[3], 'started_audit_id' => $p[4], 'last_audit_id' => $p[5], 'created_at' => $p[6], 'updated_at' => $p[7], 'claim_token' => null, 'completed_at' => null );
            return true;
        }
        if ( str_starts_with( $sql, 'DELETE FROM `wp_law_watch_settings` WHERE user_id = ?' ) ) {
            $this->watches = array_values( array_filter( $this->watches, static fn( $r ) => $r['user_id'] !== $p[0] ) );
            $GLOBALS['wpdb']->watch_total = '0'; $GLOBALS['wpdb']->active = '0'; $GLOBALS['wpdb']->stopped = '0'; return true;
        }
        if ( str_starts_with( $sql, 'DELETE FROM `wp_egov_law_ai_summary_history` WHERE user_id = ?' ) ) {
            $this->history = array_values( array_filter( $this->history, static fn( $r ) => $r['user_id'] !== $p[0] ) ); $GLOBALS['wpdb']->ai = '0'; return true;
        }
        if ( str_starts_with( $sql, 'DELETE FROM `wp_usermeta` WHERE user_id = ? AND meta_key = ?' ) && in_array( $p[1], array( 'egov_law_monitor_notifications', 'egov_law_monitor_ai_summary_trial_usage' ), true ) ) {
            $this->notification_rows = array_values( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] !== $p[0] || $r['meta_key'] !== $p[1] ) ); return true;
        }
        return parent::write( $sql, $p );
    }
}
class WithdrawalFlowStore extends CompletionStore {
    function get_results( $query, $mode ) {
        list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() );
        if ( str_contains( $sql, 'wp_usermeta' ) && str_contains( $sql, 'meta_key IN' ) ) {
            $rows = $this->meta;
            foreach ( $GLOBALS['user_db']->notification_rows as $row ) { if ( $row['user_id'] === $p[0] && in_array( $row['meta_key'], array( 'egov_law_monitor_notifications', 'egov_law_monitor_ai_summary_trial_usage' ), true ) ) { $rows[] = array( 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value'] ?? '1' ); } }
            return $rows;
        }
        return parent::get_results( $query, $mode );
    }
}
function withdrawal_flow_fixture() {
    list( $base, $unused ) = user_delete_fixture();
    $db = new WithdrawalFlowDB(); foreach ( get_object_vars( $base ) as $key => $value ) { $db->$key = $value; }
    $store = new WithdrawalFlowStore(); foreach ( get_object_vars( $GLOBALS['wpdb'] ) as $key => $value ) { $store->$key = $value; }
    $GLOBALS['wpdb'] = $store; $GLOBALS['user_db'] = $db; reset_withdrawal_reads();
    $db->audit_rows = array( 5 => array( 'user_id' => 10, 'admin_user_id' => 99, 'action' => 'subscription_created', 'new_value' => '{}' ) );
    $db->watches = array( array( 'user_id' => 10, 'is_active' => 1 ), array( 'user_id' => 10, 'is_active' => 0 ), array( 'user_id' => 20, 'is_active' => 1 ) );
    $db->history = array( array( 'user_id' => 10, 'law_id' => 'A' ), array( 'user_id' => 10, 'law_id' => 'B' ), array( 'user_id' => 20, 'law_id' => 'C' ) );
    $db->notification_rows = array( array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_notifications', 'meta_value' => '0' ), array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage', 'meta_value' => '2' ), array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_notifications', 'meta_value' => '1' ) );
    $store->watch_total = '2'; $store->active = '1'; $store->stopped = '1'; $store->ai = '2';
    $store->contracts[0] += array( 'user_id' => '10', 'admin_note' => 'original', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-12-01 00:00:00', 'verified_at' => null, 'verified_by' => null );
    $store->cancellations[0] += array( 'user_id' => '10', 'requested_at' => '2026-12-01 00:00:00', 'revision' => '3' );
    $store->legacy_exists = true; $store->legacy_rows = array( array( 'id' => '6', 'user_id' => '10', 'state' => 'closed', 'created_at' => '2026-11-01' ) );
    $_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
    return $db;
}
/** Failure injection around the actual eight-step business flow. */
function withdrawal_flow_run( $lose_cancellation = false ) {
    global $wpdb, $withdrawal_records, $user_deletion_calls;
    $db = withdrawal_flow_fixture();
    if ( $lose_cancellation === 'consent_only_lost' ) { $wpdb->contracts = array(); $wpdb->cancellations = array(); }
    $html = render_preflight();
    preflight_check( str_contains( $html, '1. 退会申請・本人確認' ) && str_contains( $html, '2. Square契約終了' ) && str_contains( $html, '3. 退会前チェック' ), 'flow: first three steps remain available before starting' );
    $start = array( 'withdrawal_nonce' => 'valid:egov_withdrawal_start_10', 'withdrawal_reason' => '本人申請・Dashboard確認済み。SUB、利用終了日2026-12-12。' );
    foreach ( egov_law_monitor_withdrawal_start_confirmations() as $key => $label ) { $start['confirm_' . $key] = '1'; }
    preflight_check( egov_law_monitor_start_withdrawal( 10, $start, fn() => $db ) === true && $withdrawal_records[10]['phase'] === 'started', 'flow: real start handler' );
    $state = $withdrawal_records[10];
    $watch = array( 'watch_nonce' => 'valid:egov_withdrawal_watches_10', 'confirm_watch_delete' => '1', 'watch_token' => egov_law_monitor_withdrawal_watch_token( 10, $state, egov_law_monitor_withdrawal_watch_snapshot( 10, $state, $db ) ) );
    preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_watches( 10, $watch, fn() => $db ) ) && $withdrawal_records[10]['phase'] === 'started' && count( $db->history ) === 3, 'flow: watch step does not delete AI or advance automatically' );
    $state = $withdrawal_records[10];
    $ai = array( 'ai_nonce' => 'valid:egov_withdrawal_ai_10', 'confirm_ai_delete' => '1', 'ai_token' => egov_law_monitor_withdrawal_ai_token( 10, $state, egov_law_monitor_withdrawal_ai_snapshot( 10, $state, $db ) ) );
    preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_ai_history( 10, $ai, fn() => $db ) ) && $withdrawal_records[10]['phase'] === 'started' && $user_deletion_calls === 0, 'flow: AI step does not delete the user' );
    if ( in_array( $lose_cancellation, array( 'valid_contract', 'valid_date', 'valid_chain' ), true ) ) {
        withdrawal_flow_change( $db, $lose_cancellation === 'valid_date' ? 'date' : 'contract' );
        if ( $lose_cancellation === 'valid_chain' ) { withdrawal_flow_change( $db, 'contract' ); }
    }
    $state = $withdrawal_records[10];
    $user = array( 'user_nonce' => 'valid:egov_withdrawal_user_10', 'confirm_user_delete' => '1', 'user_token' => egov_law_monitor_withdrawal_user_token( 10, $state, egov_law_monitor_withdrawal_user_evidence( 10, $state, $db ) ) );
    $faults = array( true, 'contract', 'start_audit', 'legacy', 'legacy_replaced', 'cancel_replaced', 'consent', 'consent_only_lost', 'watch_audit', 'ai_audit', 'unlogged', 'bad_chain', 'other_actor', 'no_admin', 'unconfirmed_date', 'audit_gap', 'forbidden_field', 'audit_limit', 'read_failure' );
    $fresh_rejected = false;
    if ( in_array( $lose_cancellation, $faults, true ) ) {
        withdrawal_flow_inject( $db, $lose_cancellation );
        try { egov_law_monitor_withdrawal_user_evidence( 10, $state, $db ); } catch ( Throwable $expected ) { $fresh_rejected = true; }
    }
    $deleted = egov_law_monitor_delete_withdrawal_user( 10, $user, fn() => $db );
    if ( is_wp_error( $deleted ) ) { return array( 'completed' => false, 'user_deleted' => $GLOBALS['withdrawal_test_user_deleted'], 'delete_rejected' => true, 'fresh_rejected' => $fresh_rejected, 'errors' => array( $deleted->get_error_message() ) ); }
    preflight_check( $withdrawal_records[10]['phase'] === 'deleting' && $user_deletion_calls === 1, 'flow: explicit WP deletion, not completion' );
    $html = render_preflight();
    preflight_check( str_contains( $html, 'WordPressユーザー不存在' ) && str_contains( $html, '8. 残存データ確認・退会完了' ), 'flow: same route continues after deletion' );
    if ( $lose_cancellation === 'valid_after_delete' ) { withdrawal_flow_change( $db, 'contract' ); }
    if ( $lose_cancellation === 'valid_after_delete_date' ) { withdrawal_flow_change( $db, 'date' ); }
    $state = $withdrawal_records[10]; $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $state, $db );
    if ( ! $report['ok'] ) { return array( 'completed' => false, 'user_deleted' => $GLOBALS['withdrawal_test_user_deleted'], 'errors' => $report['errors'] ); }
    $complete = array( 'complete_nonce' => 'valid:egov_withdrawal_complete_10', 'confirm_complete' => '1', 'complete_token' => egov_law_monitor_withdrawal_completion_token( 10, $state, $report ) );
    if ( is_string( $lose_cancellation ) && str_starts_with( $lose_cancellation, 'after_delete_' ) ) { withdrawal_flow_inject( $db, substr( $lose_cancellation, 13 ) ); }
    $result = egov_law_monitor_complete_withdrawal( 10, $complete, fn() => $db );
    return array( 'completed' => $result === true && $withdrawal_records[10]['phase'] === 'completed', 'user_deleted' => $GLOBALS['withdrawal_test_user_deleted'], 'errors' => is_wp_error( $result ) ? array( $result->get_error_message() ) : array() );
}
function withdrawal_flow_change( $db, $kind, $log_it = true ) {
    global $wpdb;
    $tables = array( 'log' => 'wp_egov_square_admin_log' );
    if ( $kind === 'contract' ) {
        $old = $wpdb->contracts[0]; $new = $old;
        $new['admin_note'] .= ' corrected'; $new['square_customer_id'] .= '_corrected'; $new['updated_at'] = '2026-12-13 00:00:00';
        $wpdb->contracts[0] = $new;
        if ( $log_it ) { $db->write( 'INSERT INTO `wp_egov_square_admin_log` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', array( 10, 99, 'subscription_updated', json_encode( $old ), json_encode( $new ), 'correction', '2026-12-13 00:00:00' ) ); }
    } else {
        $old = $wpdb->cancellations[0]; $new = $old; $new['end_date'] = '2026-12-11'; $new['revision'] = (int) $old['revision'] + 1;
        $wpdb->cancellations[0] = $new;
        if ( $log_it ) { $db->write( 'INSERT INTO `wp_egov_square_admin_log` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', array( 10, 99, 'subscription_end_date_changed', json_encode( $old ), json_encode( $new + array( 'dashboard_confirmed' => true ) ), 'date correction', '2026-12-13 00:00:00' ) ); }
    }
}
function withdrawal_flow_inject( $db, $kind ) {
    global $wpdb, $withdrawal_records;
    if ( $kind === true || $kind === 'cancellation' ) { $wpdb->cancellations = array(); }
    elseif ( $kind === 'contract' ) { $wpdb->contracts = array(); }
    elseif ( $kind === 'legacy' ) { $wpdb->legacy_rows = array(); }
    elseif ( $kind === 'legacy_replaced' ) { $wpdb->legacy_rows[0]['id'] = '7'; }
    elseif ( $kind === 'cancel_replaced' ) { $wpdb->cancellations[0]['id'] = '3'; }
    elseif ( in_array( $kind, array( 'consent', 'consent_only_lost' ), true ) ) {
        if ( empty( $GLOBALS['withdrawal_test_user_deleted'] ) ) { $wpdb->meta = array_values( array_filter( $wpdb->meta, static fn( $r ) => $r['meta_key'] !== 'egov_law_monitor_standard_consent' ) ); }
        else { $id = completion_action_id( $db, 'withdrawal_user_deleting' ); $value = json_decode( $db->audit_rows[$id]['new_value'], true ); unset( $value['evidence']['consent'] ); $db->audit_rows[$id]['new_value'] = json_encode( $value ); }
    }
    elseif ( in_array( $kind, array( 'start_audit', 'watch_audit', 'ai_audit' ), true ) ) { $action = array( 'start_audit' => 'withdrawal_started', 'watch_audit' => 'withdrawal_watches_deleted', 'ai_audit' => 'withdrawal_ai_history_deleted' )[$kind]; unset( $db->audit_rows[completion_action_id( $db, $action )] ); }
    elseif ( $kind === 'unlogged' ) { withdrawal_flow_change( $db, 'contract', false ); }
    elseif ( in_array( $kind, array( 'bad_chain', 'other_actor' ), true ) ) {
        withdrawal_flow_change( $db, 'contract' ); $id = completion_action_id( $db, 'subscription_updated' );
        if ( $kind === 'bad_chain' ) { $db->audit_rows[$id]['old_value'] = '{}'; } else { $db->audit_rows[$id]['user_id'] = 20; }
    }
    elseif ( $kind === 'no_admin' ) { withdrawal_flow_change( $db, 'contract' ); $db->audit_rows[completion_action_id( $db, 'subscription_updated' )]['admin_user_id'] = 0; }
    elseif ( $kind === 'unconfirmed_date' ) { withdrawal_flow_change( $db, 'date' ); $id = completion_action_id( $db, 'subscription_end_date_changed' ); $value = json_decode( $db->audit_rows[$id]['new_value'], true ); unset( $value['dashboard_confirmed'] ); $db->audit_rows[$id]['new_value'] = json_encode( $value ); }
    elseif ( $kind === 'audit_gap' ) { withdrawal_flow_change( $db, 'contract' ); $first = completion_action_id( $db, 'subscription_updated' ); withdrawal_flow_change( $db, 'contract' ); unset( $db->audit_rows[$first] ); }
    elseif ( $kind === 'forbidden_field' ) { withdrawal_flow_change( $db, 'contract' ); $id = completion_action_id( $db, 'subscription_updated' ); $value = json_decode( $db->audit_rows[$id]['new_value'], true ); $value['created_at'] = '2026-02-01 00:00:00'; $wpdb->contracts[0]['created_at'] = $value['created_at']; $db->audit_rows[$id]['new_value'] = json_encode( $value ); }
    elseif ( $kind === 'audit_limit' ) { for ( $i = 0; $i < 201; $i++ ) { withdrawal_flow_change( $db, 'contract' ); } }
    elseif ( $kind === 'read_failure' ) { $db->retention_read_failure = true; }
}
$flow = withdrawal_flow_run();
preflight_check( $flow['completed'] && $user_deletion_calls === 1 && $user_db->shared_cache === array( 'cached summary' ) && $user_db->watches === array( array( 'user_id' => 20, 'is_active' => 1 ) ) && $user_db->history === array( array( 'user_id' => 20, 'law_id' => 'C' ) ), 'full eight-step business flow completes and preserves other/shared data' );
foreach ( array( true, 'contract', 'start_audit', 'legacy', 'legacy_replaced', 'cancel_replaced', 'consent', 'consent_only_lost', 'watch_audit', 'ai_audit', 'unlogged', 'bad_chain', 'other_actor', 'no_admin', 'unconfirmed_date', 'audit_gap', 'forbidden_field', 'audit_limit', 'read_failure' ) as $fault ) {
    $result = withdrawal_flow_run( $fault );
    preflight_check( ! $result['completed'] && ! $result['user_deleted'] && $result['delete_rejected'] && $result['fresh_rejected'] && $user_deletion_calls === 0, 'retention fault now blocks fresh evidence AND core deletion: ' . (string) $fault );
}
foreach ( array( 'after_delete_cancellation', 'after_delete_contract', 'after_delete_legacy', 'after_delete_start_audit', 'after_delete_watch_audit', 'after_delete_ai_audit', 'after_delete_consent', 'after_delete_unlogged' ) as $fault ) {
    $result = withdrawal_flow_run( $fault ); preflight_check( ! $result['completed'] && $result['user_deleted'], 'post-delete loss blocks completion: ' . $fault );
}
foreach ( array( 'valid_contract', 'valid_date', 'valid_chain', 'valid_after_delete', 'valid_after_delete_date' ) as $correction ) { preflight_check( withdrawal_flow_run( $correction )['completed'], 'audited correction allowed: ' . $correction ); }
echo "Full withdrawal flow and 32 retention fault/correction cases passed using simulated DB/WP; NOT a real WordPress/MySQL integration test.\n";
