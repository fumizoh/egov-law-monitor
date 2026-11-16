<?php
/** Actual start orchestration and preflight; only WP/DB boundaries are replaced. */
require __DIR__ . '/user-withdrawal-check-test.php';
function wp_verify_nonce( $value, $action ) { return $value === 'valid:' . $action; }
function wp_generate_uuid4() { return '20000000-0000-4000-8000-000000000000'; }
function sanitize_textarea_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_cache_delete( $id, $group ) {
    $GLOBALS['cleanup_cache_events'][] = array( $id, $group, ! empty( $GLOBALS['cleanup_committed'] ) );
    if ( ! empty( $GLOBALS['cleanup_cache_throw'] ) && ! empty( $GLOBALS['cleanup_committed'] ) ) { throw new RuntimeException( 'private cache error' ); }
    unset( $GLOBALS['cleanup_meta_cache'][$id] );
    return true;
}
function wp_timezone() { return new DateTimeZone( 'Asia/Tokyo' ); }
function current_datetime() { return new DateTimeImmutable( '2026-12-13', wp_timezone() ); }
function egov_law_monitor_square_plan_tables() { return array( 'users' => 'wp_users', 'log' => 'wp_egov_square_admin_log' ); }
// Schema validators have separate regression coverage; this boundary simulates ready/unready storage.
function egov_law_monitor_square_plan_storage_ready( $db, $tables ) { return $db->failure !== 'schema'; }
function egov_law_monitor_cancellation_storage_ready( $db ) { return $db->failure !== 'schema'; }
class StartDB {
    public $last_error = '', $connection_lost = false, $insert_id = 0, $affected_rows = 1;
    public $failure = '', $logs = array(), $backup, $closed = false;
    function begin() { $this->backup = array( $GLOBALS['withdrawal_records'], $this->logs ); return $this->failure !== 'begin'; }
    function rows( $sql, $params = array() ) {
        if ( $this->failure === 'read' ) { $this->last_error = 'private'; return false; }
        if ( str_starts_with( $sql, 'SELECT TABLE_NAME, ENGINE' ) && $params[0] === 'wp_users' ) { return array( array( 'TABLE_NAME' => 'wp_users', 'ENGINE' => 'InnoDB' ) ); }
        if ( str_starts_with( $sql, 'SELECT ID ' ) ) { return array( array( 'ID' => 10 ) ); }
        if ( withdrawal_test_query( array( $sql, $params ) ) ) { return ( new WithdrawalReadStore() )->rows( $sql, $params ); }
        return $GLOBALS['wpdb']->get_results( array( str_replace( '?', '%s', $sql ), $params ), ARRAY_A );
    }
    function write( $sql, $p ) {
        if ( str_contains( $sql, 'egov_square_admin_log' ) ) {
            if ( $this->failure === 'audit' ) { return false; }
            $this->logs[] = $p; $this->insert_id = 7; return true;
        }
        if ( ! str_starts_with( $sql, 'INSERT INTO `wp_egov_user_withdrawals`' ) ) { throw new LogicException( 'Unexpected mutation' ); }
        if ( $this->failure === 'write' || isset( $GLOBALS['withdrawal_records'][$p[1]] ) ) { return false; }
        $GLOBALS['withdrawal_records'][$p[1]] = array( 'process_uuid' => $p[0], 'user_id' => $p[1], 'phase' => $p[2], 'revision' => $p[3], 'started_audit_id' => $p[4], 'last_audit_id' => $p[5] ); return true;
    }
    function commit() { return $this->failure !== 'commit'; }
    function rollback() { if ( $this->failure === 'commit' ) { return true; } list( $GLOBALS['withdrawal_records'], $this->logs ) = $this->backup; return true; }
    function close() { $this->closed = true; }
}
function start_fixture() {
    global $wpdb, $allowed, $caps, $actor, $person;
    reset_withdrawal_reads(); $wpdb = new PreflightStore(); $allowed = true; $caps = array(); $actor = 99; $person->roles = array( 'subscriber' );
    $fields = array( 'withdrawal_nonce' => 'valid:egov_withdrawal_start_10', 'withdrawal_reason' => '本人の申請とDashboardを確認。契約なし。2026-12-13 UTC。' );
    foreach ( egov_law_monitor_withdrawal_start_confirmations() as $key => $label ) { $fields['confirm_' . $key] = '1'; }
    return $fields;
}
$fields = start_fixture(); $db = new StartDB();
preflight_check( egov_law_monitor_start_withdrawal( 10, $fields, fn() => $db ) === true && count( $db->logs ) === 1 && $withdrawal_records[10]['phase'] === 'started', 'atomic start and audit' );
$audit = json_decode( $db->logs[0][4], true );
preflight_check( $db->logs[0][0] === 10 && $db->logs[0][1] === 99 && $db->logs[0][2] === 'withdrawal_started' && count( $audit['confirmations'] ) === 5 && $audit['manual_evidence_only'] === true && $withdrawal_records[10]['started_audit_id'] === 7, 'audit identity, confirmations and linked start' );
preflight_check( is_wp_error( egov_law_monitor_withdrawal_check_save( 10 ) ), 'normal writes blocked after start' );
preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields, fn() => $db ) ) && count( $db->logs ) === 1, 'duplicate start rejected' );
foreach ( array( 'schema', 'begin', 'read', 'audit', 'write', 'commit' ) as $failure ) {
    $fields = start_fixture(); $db = new StartDB(); $db->failure = $failure;
    $r = egov_law_monitor_start_withdrawal( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $r ) && $db->closed, 'failure handled: ' . $failure );
    if ( $failure !== 'commit' ) { preflight_check( ! $withdrawal_records && ! $db->logs, 'no partial start: ' . $failure ); }
    else { preflight_check( $r->code === 'withdrawal_outcome_unknown', 'unknown commit not reported successful' ); }
}
foreach ( array( 'confirm_request', 'confirm_identity', 'confirm_square', 'confirm_cancelled', 'confirm_period', 'withdrawal_reason', 'withdrawal_nonce' ) as $missing ) {
    $fields = start_fixture(); unset( $fields[$missing] );
    preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields, fn() => new StartDB() ) ) && ! $withdrawal_records, 'required field: ' . $missing );
}
$fields = start_fixture(); $allowed = false; preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields ) ), 'permission denied' );
$fields = start_fixture(); $caps = array( 'manage_options' ); preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields ) ), 'protected account denied' );
foreach ( array( 'pending', 'legacy', 'standard', 'read', 'future', 'invalid_date' ) as $case ) {
    $fields = start_fixture();
    if ( $case === 'pending' ) { $wpdb->pending = '1'; }
    if ( $case === 'legacy' ) { $wpdb->legacy_exists = true; }
    if ( $case === 'standard' ) { $wpdb->meta = array( array( 'meta_key' => 'egov_law_monitor_plan', 'meta_value' => 'standard' ) ); }
    if ( $case === 'read' ) { $wpdb->fail = 'egov_square_subscriptions'; }
    if ( in_array( $case, array( 'future', 'invalid_date' ), true ) ) { $wpdb->cancellations = array( array( 'completed_at' => '2026-12-01', 'end_date' => $case === 'future' ? '2026-12-13' : '2026-02-30' ) ); }
    preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields, fn() => new StartDB() ) ) && ! $withdrawal_records, 'unresolved evidence: ' . $case );
}
foreach ( array( 'post', 'attachment', 'comments', 'links', 'all' ) as $owned ) {
    $fields = start_fixture();
    if ( in_array( $owned, array( 'post', 'attachment', 'all' ), true ) ) { $wpdb->posts_rows = array( array( 'post_type' => $owned === 'attachment' ? 'attachment' : 'post', 'total' => '3' ) ); }
    if ( in_array( $owned, array( 'comments', 'all' ), true ) ) { $wpdb->comments_total = '2'; }
    if ( in_array( $owned, array( 'links', 'all' ), true ) ) { $wpdb->links_total = '1'; }
    $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
    $html = render_preflight();
    preflight_check( str_contains( $html, '本人が投稿したコメント' ) && str_contains( $html, '所有リンク' ) && str_contains( $html, '退会処理を開始</button>' ), 'owned data remains visible with start: ' . $owned );
    $data = egov_law_monitor_withdrawal_check_data( 10 );
    preflight_check( $data['posts'] === $wpdb->posts_rows && $data['comments'][0]['total'] === $wpdb->comments_total && $data['links'][0]['total'] === $wpdb->links_total, 'owned counts preserved: ' . $owned );
    preflight_check( egov_law_monitor_start_withdrawal( 10, $fields, fn() => new StartDB() ) === true, 'owned data permits start: ' . $owned );
}
foreach ( array( 'wp_posts', 'wp_comments', 'wp_links' ) as $failed_read ) {
    $fields = start_fixture(); $wpdb->fail = $failed_read;
    preflight_check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $fields, fn() => new StartDB() ) ) && ! $withdrawal_records, 'ownership read failure still refuses start: ' . $failed_read );
}
$fields = start_fixture();
$wpdb->contracts = array( array( 'id' => 1, 'square_subscription_id' => 'SUB', 'square_customer_id' => 'CUSTOMER' ) );
$wpdb->cancellations = array( array( 'id' => 2, 'contract_id' => 1, 'approval_id' => 4, 'completed_at' => '2026-12-13 00:00:00', 'end_date' => '2026-12-12' ) );
$db = new StartDB();
preflight_check( egov_law_monitor_start_withdrawal( 10, $fields, fn() => $db ) === true, 'Free user with completed paid period may start on next site date' );
$evidence = json_decode( $db->logs[0][4], true )['saved_evidence'];
preflight_check( $evidence['contracts'][0]['square_subscription_id'] === 'SUB' && $evidence['cancellations'][0]['end_date'] === '2026-12-12', 'contract IDs and end date retained in audit' );
echo "Withdrawal start tests passed; offline only.\n";
