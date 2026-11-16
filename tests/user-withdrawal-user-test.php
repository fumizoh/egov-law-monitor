<?php
/** Real preparation/result functions; WP core boundary only is simulated. */
require __DIR__ . '/user-withdrawal-ai-test.php';
function wp_delete_user( $user_id, $reassign = null ) {
    if ( $user_id !== 10 || $reassign !== null || ! empty( $GLOBALS['user_db']->active ) ) { throw new LogicException( 'unsafe WP deletion boundary' ); }
    ++$GLOBALS['user_deletion_calls'];
    if ( $GLOBALS['user_delete_mode'] === 'throw' ) { throw new RuntimeException( 'private plugin error' ); }
    if ( $GLOBALS['user_delete_mode'] === 'false' ) { return false; }
    $GLOBALS['withdrawal_test_user_deleted'] = true;
    $GLOBALS['wpdb']->meta = array();
    $GLOBALS['user_db']->notification_rows = array_values( array_filter( $GLOBALS['user_db']->notification_rows, static fn( $r ) => $r['user_id'] !== 10 ) );
    return true;
}
class UserDeleteDB extends AICleanupDB {
    public $active = false, $commit_count = 0, $gap_owned = false;
    function begin() { $this->active = true; return parent::begin(); }
    function commit() { $this->active = false; ++$this->commit_count; if ( $this->gap_owned && $this->commit_count === 1 ) { $GLOBALS['wpdb']->posts_rows = array( array( 'post_type' => 'post', 'total' => '1' ) ); } return parent::commit(); }
    function rollback() { $this->active = false; return parent::rollback(); }
    function rows( $sql, $p = array() ) {
        if ( $this->failure === 'result_read' && $GLOBALS['user_deletion_calls'] > 0 ) { $this->last_error = 'private'; return false; }
        if ( str_contains( $sql, 'AND action IN (' ) ) {
            $rows = array(); $logs = $this->audit_rows; ksort( $logs );
            foreach ( $logs as $id => $row ) { if ( $row['user_id'] === $p[0] && $id > $p[1] && in_array( $row['action'], array_slice( $p, 2 ), true ) ) { $rows[] = $row + array( 'id' => $id, 'admin_user_id' => 99 ); } }
            return array_slice( $rows, 0, 201 );
        }
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) && ( str_contains( $sql, 'COALESCE' ) || str_contains( $sql, 'egov_square_cancellations' ) || str_contains( $sql, 'wp_comments' ) || str_contains( $sql, 'wp_links' ) ) ) { return $GLOBALS['wpdb']->get_results( array( $sql, $p ), ARRAY_A ); }
        if ( str_starts_with( $sql, 'SELECT ID FROM' ) ) {
            if ( $this->failure === 'read' ) { $this->last_error = 'private'; return false; }
            return empty( $GLOBALS['withdrawal_test_user_deleted'] ) ? array( array( 'ID' => 10 ) ) : array();
        }
        if ( str_starts_with( $sql, 'SELECT id FROM `wp_egov_square_admin_log`' ) ) { return array(); }
        $rows = parent::rows( $sql, $p );
        if ( is_array( $rows ) && str_contains( $sql, ', id, admin_user_id, created_at' ) ) {
            foreach ( $rows as &$row ) { foreach ( array_reverse( $this->audit_rows, true ) as $id => $record ) { if ( $row['action'] === $record['action'] && $row['new_value'] === $record['new_value'] ) { $row += array( 'id' => $id, 'admin_user_id' => 99, 'created_at' => '2026-12-13 00:00:00' ); break; } } } unset( $row );
        }
        if ( is_array( $rows ) && str_starts_with( $sql, 'SELECT *' ) && ( str_contains( $sql, 'egov_square_subscriptions' ) || str_contains( $sql, 'egov_square_cancellations' ) ) ) {
            foreach ( $rows as &$row ) { foreach ( array( 'id', 'contract_id', 'approval_id', 'user_id' ) as $key ) { if ( isset( $row[$key] ) ) { $row[$key] = (int) $row[$key]; } } } unset( $row );
        }
        return $rows;
    }
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'INSERT INTO `wp_egov_square_admin_log`' ) && $this->failure === 'result_audit' && $GLOBALS['user_deletion_calls'] > 0 ) { return false; }
        if ( str_starts_with( $sql, 'UPDATE `wp_egov_user_withdrawals` SET phase' ) ) {
            if ( $this->failure === 'prepare_state' ) { return false; }
            if ( str_contains( $sql, 'claim_token = NULL' ) ) {
                $row = &$GLOBALS['withdrawal_records'][$p[4]];
                if ( ! $row || $row['phase'] !== $p[5] || $row['process_uuid'] !== $p[3] || (int) $row['revision'] !== $p[6] || $row['claim_token'] !== $p[7] ) { $this->affected_rows = 0; return true; }
                $row['phase'] = $p[0]; $row['claim_token'] = null; $row['claimed_at'] = null; $row['last_audit_id'] = $p[1]; $row['revision']++; $this->affected_rows = 1; return true;
            }
            $row = &$GLOBALS['withdrawal_records'][$p[6]];
            if ( ! $row || $row['phase'] !== $p[7] || $row['process_uuid'] !== $p[5] || (int) $row['revision'] !== $p[8] ) { $this->affected_rows = 0; return true; }
            $row['phase'] = $p[0]; $row['claim_token'] = $p[1]; $row['claimed_at'] = $p[2]; $row['last_audit_id'] = $p[3]; $row['revision']++; $this->affected_rows = 1; return true;
        }
        if ( str_starts_with( $sql, 'UPDATE `wp_egov_user_withdrawals` SET revision' ) && $this->failure === 'result_state' && $GLOBALS['user_deletion_calls'] > 0 ) { return false; }
        if ( str_starts_with( $sql, 'DELETE' ) ) { throw new LogicException( 'user deletion must use WP core only' ); }
        return parent::write( $sql, $p );
    }
}
class UserDeleteStore extends PreflightStore {
    function get_results( $query, $mode ) {
        list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() );
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) && str_contains( $sql, 'wp_usermeta' ) && count( $p ) === 1 ) { return array( array( 'total' => (string) count( $this->meta ) ) ); }
        if ( str_starts_with( $sql, 'SELECT ID FROM' ) || str_contains( $sql, 'wp_egov_square_admin_log' ) ) {
            $rows = $GLOBALS['user_db']->rows( str_replace( array( '%d', '%s' ), '?', $sql ), $p ); $this->last_error = $GLOBALS['user_db']->last_error; return $rows;
        }
        return parent::get_results( $query, $mode );
    }
}
function user_delete_fixture() {
    list( $old, $unused ) = ai_cleanup_fixture();
    $db = new UserDeleteDB(); $db->watches = $old->watches;
    $db->history = array( array( 'user_id' => 20, 'law_id' => 'C' ) );
    $db->notification_rows = array( array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_standard_consent' ), array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage' ) );
    $db->audit_rows = $old->audit_rows;
    $db->audit_rows[11] = array( 'user_id' => 10, 'action' => 'withdrawal_ai_history_deleted', 'new_value' => json_encode( array( 'process_uuid' => $GLOBALS['withdrawal_records'][10]['process_uuid'], 'remaining' => array( 'history' => 0, 'trial' => 0 ) ) ) );
    $GLOBALS['withdrawal_records'][10]['last_audit_id'] = 11;
    $GLOBALS['withdrawal_records'][10]['revision'] = 3;
    $GLOBALS['wpdb'] = new UserDeleteStore();
    $GLOBALS['wpdb']->watch_total = '0'; $GLOBALS['wpdb']->active = '0'; $GLOBALS['wpdb']->stopped = '0'; $GLOBALS['wpdb']->ai = '0';
    $GLOBALS['wpdb']->meta = array( array( 'meta_key' => 'egov_law_monitor_plan', 'meta_value' => 'free' ), array( 'meta_key' => 'egov_law_monitor_standard_consent', 'meta_value' => serialize( array( 'agreed_at' => '2026-11-14T00:00:00Z', 'terms_version' => '2026-11-14', 'conditions_version' => '2026-11-14' ) ) ) );
    $GLOBALS['wpdb']->contracts = array( array( 'id' => '1', 'square_subscription_id' => 'SUB', 'square_customer_id' => 'CUSTOMER' ) );
    $GLOBALS['wpdb']->cancellations = array( array( 'id' => '2', 'contract_id' => '1', 'approval_id' => '4', 'end_date' => '2026-12-12', 'completed_at' => '2026-12-13 00:00:00' ) );
    $GLOBALS['user_db'] = $db; $GLOBALS['withdrawal_test_user_deleted'] = false; $GLOBALS['user_deletion_calls'] = 0; $GLOBALS['user_delete_mode'] = 'true';
    $start_data = egov_law_monitor_withdrawal_check_data( 10, $db );
    $db->audit_rows[1] = array( 'user_id' => 10, 'admin_user_id' => 99, 'action' => 'withdrawal_started', 'new_value' => json_encode( array( 'process_uuid' => $GLOBALS['withdrawal_records'][10]['process_uuid'], 'phase' => 'started', 'target_validation' => array( 'subscriber_only' => true, 'protected_check_passed' => true ), 'confirmations' => array_keys( egov_law_monitor_withdrawal_start_confirmations() ), 'manual_evidence_only' => true, 'saved_evidence' => egov_law_monitor_withdrawal_start_evidence( $start_data ), 'retention_manifest' => egov_law_monitor_withdrawal_retention_manifest( 10, $start_data ) ) ) );
    $evidence = egov_law_monitor_withdrawal_user_evidence( 10, $GLOBALS['withdrawal_records'][10], $db );
    $fields = array( 'user_nonce' => 'valid:egov_withdrawal_user_10', 'confirm_user_delete' => '1', 'user_token' => egov_law_monitor_withdrawal_user_token( 10, $GLOBALS['withdrawal_records'][10], $evidence ) );
    return array( $db, $fields );
}
list( $db, $fields ) = user_delete_fixture(); $contracts = $wpdb->contracts; $cancellations = $wpdb->cancellations;
ob_start(); egov_law_monitor_render_withdrawal_user_step( 10, $withdrawal_records[10], false, null ); $form = ob_get_clean();
preflight_check( str_contains( $form, 'name="confirm_user_delete"' ) && str_contains( $form, 'name="user_nonce"' ) && str_contains( $form, $fields['user_token'] ) && str_contains( $form, 'WordPressユーザーを削除</button>' ), 'eligible user sees bound nonce/token and explicit confirmation, including cross-driver numeric normalization' );
$result = egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
preflight_check( ! is_wp_error( $result ) && $result['deleted'] && $user_deletion_calls === 1 && $withdrawal_records[10]['phase'] === 'deleting' && empty( $withdrawal_records[10]['completed_at'] ), 'core deletion outside DB transaction, never auto-completes' );
preflight_check( $wpdb->contracts === $contracts && $wpdb->cancellations === $cancellations && $db->shared_cache === array( 'cached summary' ) && count( $db->audit_rows ) === 5, 'contracts, previous audits and shared cache retained' );
preflight_check( $db->notification_rows === array( array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage' ) ), 'other user metadata not removed by simulated core deletion' );
$archive = egov_law_monitor_withdrawal_user_archive( 10, $withdrawal_records[10], $db );
preflight_check( $archive['evidence']['consent'] === array( 'agreed_at' => '2026-11-14T00:00:00Z', 'terms_version' => '2026-11-14', 'conditions_version' => '2026-11-14' ) && ! $wpdb->meta, 'consent preserved in immutable audit before core removes usermeta' );
preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $user_deletion_calls === 1, 'deleted user never deleted twice' );
$_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
$html = render_preflight();
preflight_check( str_contains( $html, 'WordPressユーザー不存在' ) && str_contains( $html, '8. 残存データ確認・退会完了' ) && str_contains( $html, '2026-11-14T00:00:00Z' ) && str_contains( $html, 'SUB' ) && ! str_contains( $html, '<button' ), 'deleted user remains on same management route with consent and history' );
foreach ( array( 'false', 'throw' ) as $mode ) {
    list( $db, $fields ) = user_delete_fixture(); $user_delete_mode = $mode;
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $withdrawal_records[10]['phase'] === 'deleting' && ! $withdrawal_test_user_deleted && $user_deletion_calls === 1, 'core failure retained for review: ' . $mode );
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $user_deletion_calls === 1, 'failed deletion not automatically replayed' );
    $state = $withdrawal_records[10];
    if ( $mode === 'false' ) {
        $evidence = egov_law_monitor_withdrawal_user_recovery_evidence( 10, $state, $db );
        $rearm = array( 'rearm_nonce' => 'valid:egov_withdrawal_rearm_10', 'confirm_rearm' => '1', 'rearm_token' => egov_law_monitor_withdrawal_user_token( 10, $state, $evidence ) );
        preflight_check( egov_law_monitor_rearm_withdrawal_user( 10, $rearm, fn() => $db ) === true && $withdrawal_records[10]['phase'] === 'started' && empty( $withdrawal_records[10]['claim_token'] ) && $user_deletion_calls === 1, 'confirmed returned-false failure can be explicitly rearmed without deleting' );
        $user_delete_mode = 'true'; $fields['user_token'] = egov_law_monitor_withdrawal_user_token( 10, $withdrawal_records[10], egov_law_monitor_withdrawal_user_evidence( 10, $withdrawal_records[10], $db ) );
        preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $user_deletion_calls === 2, 'separate explicit deletion works after revalidation' );
    } else {
        try { egov_law_monitor_withdrawal_user_recovery_evidence( 10, $state, $db ); throw new LogicException( 'exception unlocked' ); } catch ( EGOV_Law_Monitor_Withdrawal_User_Error $expected ) { preflight_check( true, 'exception/uncertain execution cannot be unlocked' ); }
    }
}
foreach ( array( 'schema', 'read', 'audit', 'prepare_state', 'commit', 'result_read', 'result_audit', 'result_state' ) as $failure ) {
    list( $db, $fields ) = user_delete_fixture(); $db->failure = $failure;
    $result = egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $result ), 'DB failure reported: ' . $failure );
    if ( str_starts_with( $failure, 'result_' ) ) { preflight_check( $user_deletion_calls === 1 && $withdrawal_test_user_deleted && $withdrawal_records[10]['phase'] === 'deleting', 'post-delete record failure does not undo or repeat core deletion' ); }
    else { preflight_check( $user_deletion_calls === 0, 'core never called without confirmed preparation' ); }
}
foreach ( array( 'nonce', 'confirm', 'permission', 'role', 'protected', 'actor', 'not_started', 'watch_missing', 'ai_missing', 'watch_residual', 'ai_residual', 'pending', 'standard', 'consent_missing', 'owned_post', 'owned_comment', 'owned_link', 'read_data', 'token' ) as $case ) {
    list( $db, $fields ) = user_delete_fixture();
    if ( $case === 'nonce' ) { $fields['user_nonce'] = 'bad'; }
    if ( $case === 'confirm' ) { unset( $fields['confirm_user_delete'] ); }
    if ( $case === 'permission' ) { $allowed = false; }
    if ( $case === 'role' ) { $person->roles = array( 'subscriber', 'editor' ); }
    if ( $case === 'protected' ) { $caps = array( 'law_watch_internal_api' ); }
    if ( $case === 'actor' ) { $actor = 10; }
    if ( $case === 'not_started' ) { $withdrawal_records = array(); }
    if ( $case === 'watch_missing' ) { $db->audit_rows[10]['action'] = 'other'; }
    if ( $case === 'ai_missing' ) { $db->audit_rows[11]['action'] = 'other'; }
    if ( $case === 'watch_residual' ) { $db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); }
    if ( $case === 'ai_residual' ) { $db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); }
    if ( $case === 'pending' ) { $wpdb->pending = '1'; }
    if ( $case === 'standard' ) { $wpdb->meta[0]['meta_value'] = 'standard'; }
    if ( $case === 'consent_missing' ) { array_pop( $wpdb->meta ); }
    if ( $case === 'owned_post' ) { $wpdb->posts_rows = array( array( 'post_type' => 'attachment', 'total' => '1' ) ); }
    if ( $case === 'owned_comment' ) { $wpdb->comments_total = '1'; }
    if ( $case === 'owned_link' ) { $wpdb->links_total = '1'; }
    if ( $case === 'read_data' ) { $wpdb->fail = 'wp_posts'; }
    if ( $case === 'token' ) { $fields['user_token'] = 'bad'; }
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $user_deletion_calls === 0, 'delete precondition refused: ' . $case );
}
list( $db, $fields ) = user_delete_fixture(); $db->gap_owned = true;
preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db ) ) && $user_deletion_calls === 0 && $withdrawal_records[10]['phase'] === 'deleting', 'final pre-core check catches newly owned data without holding transaction over hooks' );
list( $db, $fields ) = user_delete_fixture(); $withdrawal_test_user_deleted = true;
$_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
$html = render_preflight();
preflight_check( str_contains( $html, '退会管理' ) && str_contains( $html, '処理開始（ユーザー状態の確認が必要）' ) && str_contains( $html, '残存データを再確認' ), 'unexpected absent user with process record remains inspectable without claiming deletion success' );
echo "Withdrawal user deletion tests passed; only the offline wp_delete_user double was called.\n";
