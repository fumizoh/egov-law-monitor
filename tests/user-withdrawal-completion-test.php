<?php
/** Actual complete/recheck functions; DB and WP boundaries only are doubled. */
require __DIR__ . '/user-withdrawal-user-test.php';
class CompletionDB extends UserDeleteDB {
    function rows( $sql, $p = array() ) {
        if ( $this->failure === 'read' || ( $this->failure === 'count_read' && str_starts_with( $sql, 'SELECT COUNT(*)' ) ) ) { $this->last_error = 'private database detail'; return false; }
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) {
            if ( $this->failure === 'invalid_count' ) { return array( array( 'total' => null ) ); }
            if ( str_contains( $sql, '`wp_users`' ) ) { return array( array( 'total' => empty( $GLOBALS['withdrawal_test_user_deleted'] ) ? '1' : '0' ) ); }
            if ( str_contains( $sql, '`wp_usermeta`' ) ) {
                $count = count( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] === $p[0] && ( ! isset( $p[1] ) || $r['meta_key'] === $p[1] ) ) );
                $count += count( array_filter( $GLOBALS['wpdb']->meta, static fn( $r ) => ! isset( $p[1] ) || $r['meta_key'] === $p[1] ) );
                return array( array( 'total' => (string) $count ) );
            }
            if ( str_contains( $sql, '`wp_posts`' ) ) { return array( array( 'total' => (string) array_sum( array_column( $GLOBALS['wpdb']->posts_rows, 'total' ) ) ) ); }
            if ( str_contains( $sql, '`wp_comments`' ) ) { return array( array( 'total' => $GLOBALS['wpdb']->comments_total ) ); }
            if ( str_contains( $sql, '`wp_links`' ) ) { return array( array( 'total' => $GLOBALS['wpdb']->links_total ) ); }
        }
        $rows = parent::rows( $sql, $p );
        if ( is_array( $rows ) && str_contains( $sql, ', id, admin_user_id, created_at' ) ) {
            foreach ( $rows as &$row ) {
                foreach ( array_reverse( $this->audit_rows, true ) as $id => $record ) {
                    if ( $row['action'] === $record['action'] && $row['new_value'] === $record['new_value'] ) { $row['id'] = (string) $id; $row['admin_user_id'] = $record['admin_user_id'] ?? '99'; $row['created_at'] = $record['created_at'] ?? '2026-12-13 00:00:00'; break; }
                }
            } unset( $row );
        }
        return $rows;
    }
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'UPDATE `wp_egov_user_withdrawals` SET phase' ) && str_contains( $sql, 'completed_at = ?' ) ) {
            if ( $this->failure === 'complete_state' ) { return false; }
            $row = &$GLOBALS['withdrawal_records'][$p[5]];
            if ( ! $row || $row['phase'] !== $p[6] || $row['process_uuid'] !== $p[4] || (int) $row['revision'] !== $p[7] ) { $this->affected_rows = 0; return true; }
            $row['phase'] = $p[0]; $row['completed_at'] = $p[1]; $row['last_audit_id'] = $p[2]; $row['updated_at'] = $p[3]; $row['revision']++; $this->affected_rows = 1; return true;
        }
        if ( str_starts_with( $sql, 'DELETE' ) ) { throw new LogicException( 'completion must never delete data' ); }
        $result = parent::write( $sql, $p );
        if ( $result && str_starts_with( $sql, 'INSERT INTO `wp_egov_square_admin_log`' ) ) { $this->audit_rows[$this->insert_id]['created_at'] = $p[6]; }
        return $result;
    }
}
class CompletionStore extends UserDeleteStore {
    function get_results( $query, $mode ) {
        list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() );
        // Only the completion-specific quoted-column aggregates use this boundary.
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) && str_contains( $sql, 'WHERE `' ) ) {
            $rows = $GLOBALS['user_db']->rows( str_replace( array( '%d', '%s' ), '?', $sql ), $p ); $this->last_error = $GLOBALS['user_db']->last_error; return $rows;
        }
        return parent::get_results( $query, $mode );
    }
}
function completion_fixture( $free_only = false ) {
    list( $base, $user_fields ) = user_delete_fixture();
    $db = new CompletionDB(); foreach ( get_object_vars( $base ) as $key => $value ) { $db->$key = $value; }
    $store = new CompletionStore(); foreach ( get_object_vars( $GLOBALS['wpdb'] ) as $key => $value ) { $store->$key = $value; }
    $GLOBALS['wpdb'] = $store; $GLOBALS['user_db'] = $db;
    if ( $free_only ) { $store->contracts = array(); $store->cancellations = array(); $store->meta = array(); $db->notification_rows = array_values( array_filter( $db->notification_rows, static fn( $r ) => $r['user_id'] !== 10 ) ); }
    $square = egov_law_monitor_withdrawal_start_evidence( egov_law_monitor_withdrawal_check_data( 10, $db ) );
    $manifest = egov_law_monitor_withdrawal_retention_manifest( 10, egov_law_monitor_withdrawal_check_data( 10, $db ) );
    $db->audit_rows[1] = array( 'user_id' => 10, 'admin_user_id' => 99, 'action' => 'withdrawal_started', 'new_value' => json_encode( array( 'process_uuid' => $GLOBALS['withdrawal_records'][10]['process_uuid'], 'phase' => 'started', 'target_validation' => array( 'subscriber_only' => true, 'protected_check_passed' => true ), 'confirmations' => array_keys( egov_law_monitor_withdrawal_start_confirmations() ), 'manual_evidence_only' => true, 'saved_evidence' => $square, 'retention_manifest' => $manifest ) ) );
    $evidence = egov_law_monitor_withdrawal_user_evidence( 10, $GLOBALS['withdrawal_records'][10], $db );
    $user_fields['user_token'] = egov_law_monitor_withdrawal_user_token( 10, $GLOBALS['withdrawal_records'][10], $evidence );
    $deleted = egov_law_monitor_delete_withdrawal_user( 10, $user_fields, fn() => $db );
    if ( is_wp_error( $deleted ) ) { throw new RuntimeException( 'completion fixture could not delete: ' . $deleted->get_error_message() ); }
    $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $GLOBALS['withdrawal_records'][10], $db );
    if ( ! $report['ok'] ) { throw new RuntimeException( implode( ' ', $report['errors'] ) ); }
    $fields = array( 'complete_nonce' => 'valid:egov_withdrawal_complete_10', 'confirm_complete' => '1', 'complete_token' => egov_law_monitor_withdrawal_completion_token( 10, $GLOBALS['withdrawal_records'][10], $report ) );
    return array( $db, $fields );
}
function completion_action_id( $db, $action ) { foreach ( array_reverse( $db->audit_rows, true ) as $id => $row ) { if ( $row['action'] === $action ) { return $id; } } throw new LogicException( 'missing fixture action' ); }
list( $db, $fields ) = completion_fixture(); $before = array( $wpdb->contracts, $wpdb->cancellations, $db->shared_cache, $db->watches, $db->history, $db->notification_rows ); $audit_count = count( $db->audit_rows ); $revision = $withdrawal_records[10]['revision'];
preflight_check( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) === true && $withdrawal_records[10]['phase'] === 'completed' && $withdrawal_records[10]['revision'] === $revision + 1 && ! empty( $withdrawal_records[10]['completed_at'] ), 'explicit completion updates phase/time/revision' );
preflight_check( count( $db->audit_rows ) === $audit_count + 1 && $db->audit_rows[$withdrawal_records[10]['last_audit_id']]['action'] === 'withdrawal_completed' && $before === array( $wpdb->contracts, $wpdb->cancellations, $db->shared_cache, $db->watches, $db->history, $db->notification_rows ) && $user_deletion_calls === 1, 'completion only appends audit and updates state, never deletes again' );
$report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
preflight_check( $report['ok'] && $report['completed']['admin_id'] === 99, 'completed report confirms audit and administrator' );
preflight_check( is_wp_error( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === $audit_count + 1, 'double completion cannot add a second audit' );
$_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
$html = render_preflight();
preflight_check( str_contains( $html, '完了済みです。再実行できません' ) && str_contains( $html, '操作管理者ID：99' ) && str_contains( $html, '完了監査ID' ) && str_contains( $html, '契約・監査履歴を確認' ) && ! str_contains( $html, '>退会完了</button>' ), 'completed absent user remains inspectable on same page' );
$db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $withdrawal_records[10], $db );
preflight_check( ! $report['ok'] && $report['completed'] !== null, 'post-completion residual warns while preserving historical completion information' );
list( $db, $fields ) = completion_fixture( true );
preflight_check( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) === true, 'Free-only no contracts/consent is valid when baseline agrees' );
foreach ( array( 'schema', 'read', 'count_read', 'invalid_count', 'audit', 'complete_state', 'commit' ) as $failure ) {
    list( $db, $fields ) = completion_fixture(); $prior = $withdrawal_records; $audit_count = count( $db->audit_rows ); $db->failure = $failure;
    $result = egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $result ), 'completion failure not success: ' . $failure );
    if ( $failure === 'commit' ) { preflight_check( $result->code === 'withdrawal_completion_unknown', 'unknown COMMIT explicitly reported' ); }
    else { preflight_check( $withdrawal_records === $prior && count( $db->audit_rows ) === $audit_count, 'completion state/audit rolled back: ' . $failure ); }
}
foreach ( array( 'nonce', 'permission', 'confirm', 'token', 'missing_process', 'phase', 'user', 'watch', 'notifications', 'history', 'trial', 'other_meta', 'post', 'comment', 'link', 'pending', 'legacy', 'contract_lost', 'consent_lost', 'consent_flag', 'start_target', 'delete_target', 'wp_unknown' ) as $case ) {
    list( $db, $fields ) = completion_fixture(); $before_count = count( $db->audit_rows );
    if ( $case === 'nonce' ) { $fields['complete_nonce'] = 'bad'; }
    if ( $case === 'permission' ) { $allowed = false; }
    if ( $case === 'confirm' ) { unset( $fields['confirm_complete'] ); }
    if ( $case === 'token' ) { $fields['complete_token'] = 'tampered'; }
    if ( $case === 'missing_process' ) { $withdrawal_records = array(); }
    if ( $case === 'phase' ) { $withdrawal_records[10]['phase'] = 'started'; }
    if ( $case === 'user' ) { $withdrawal_test_user_deleted = false; }
    if ( $case === 'watch' ) { $db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); }
    if ( in_array( $case, array( 'notifications', 'trial', 'other_meta' ), true ) ) { $db->notification_rows[] = array( 'user_id' => 10, 'meta_key' => $case === 'notifications' ? 'egov_law_monitor_notifications' : ( $case === 'trial' ? 'egov_law_monitor_ai_summary_trial_usage' : 'orphan_meta' ) ); }
    if ( $case === 'history' ) { $db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); }
    if ( $case === 'post' ) { $wpdb->posts_rows = array( array( 'post_type' => 'attachment', 'total' => '1' ) ); }
    if ( $case === 'comment' ) { $wpdb->comments_total = '1'; }
    if ( $case === 'link' ) { $wpdb->links_total = '1'; }
    if ( $case === 'pending' ) { $wpdb->pending = '1'; }
    if ( $case === 'legacy' ) { $wpdb->legacy_exists = true; }
    if ( $case === 'contract_lost' ) { $wpdb->contracts = array(); }
    if ( in_array( $case, array( 'consent_lost', 'consent_flag', 'delete_target', 'wp_unknown', 'start_target' ), true ) ) {
        $action = $case === 'wp_unknown' ? 'withdrawal_user_deleted' : ( $case === 'start_target' ? 'withdrawal_started' : 'withdrawal_user_deleting' );
        $id = completion_action_id( $db, $action ); $payload = json_decode( $db->audit_rows[$id]['new_value'], true );
        if ( $case === 'consent_lost' ) { $payload['evidence']['consent'] = null; }
        if ( $case === 'consent_flag' ) { unset( $payload['evidence']['consent_required'] ); }
        if ( in_array( $case, array( 'delete_target', 'start_target' ), true ) ) { unset( $payload['target_validation'] ); }
        if ( $case === 'wp_unknown' ) { $payload['call_error'] = true; }
        $db->audit_rows[$id]['new_value'] = json_encode( $payload );
    }
    $result = egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $result ) && count( $db->audit_rows ) === $before_count && ( ! isset( $withdrawal_records[10] ) || $withdrawal_records[10]['phase'] !== 'completed' ), 'completion precondition refused: ' . $case );
}
foreach ( array( 'withdrawal_started', 'withdrawal_watches_deleted', 'withdrawal_ai_history_deleted', 'withdrawal_user_deleting', 'withdrawal_user_deleted' ) as $action ) {
    list( $db, $fields ) = completion_fixture(); unset( $db->audit_rows[completion_action_id( $db, $action )] );
    preflight_check( is_wp_error( egov_law_monitor_complete_withdrawal( 10, $fields, fn() => $db ) ) && $withdrawal_records[10]['phase'] === 'deleting', 'required audit loss refuses completion: ' . $action );
}
list( $db, $fields ) = completion_fixture();
ob_start(); egov_law_monitor_render_withdrawal_completion_step( 10, $withdrawal_records[10], false ); $html = ob_get_clean();
preflight_check( str_contains( $html, 'name="complete_nonce"' ) && str_contains( $html, 'name="confirm_complete"' ) && str_contains( $html, '>退会完了</button>' ) && str_contains( $html, '削除対象の現在の残存' ) && str_contains( $html, '保持対象の確認' ), 'completion UI has current check and final consent for absent user' );
$db->failure = 'count_read';
ob_start(); egov_law_monitor_render_withdrawal_completion_step( 10, $withdrawal_records[10], false ); $html = ob_get_clean();
preflight_check( str_contains( $html, '読み取りエラー' ) && ! str_contains( $html, '>退会完了</button>' ), 'failed residual read hides completion control' );
echo "Withdrawal completion tests passed; no live DB, API or additional deletion.\n";
