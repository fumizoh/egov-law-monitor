<?php
/** Offline cleanup: actual SQL orchestration, metadata cleanup and audit/state handling. */
require __DIR__ . '/user-withdrawal-start-test.php';
class WatchCleanupDB extends StartDB {
    public $watches = array(), $notification_rows = array(), $deletes = 0, $audit_rows = array(), $snapshot_backup;
    public $engine = 'InnoDB', $mutated = false;
    function commit() {
        $GLOBALS['cleanup_committed'] = true;
        $GLOBALS['cleanup_meta_cache'][10] = 'repopulated before COMMIT';
        return parent::commit();
    }
    function begin() {
        $this->snapshot_backup = array( $GLOBALS['withdrawal_records'], $this->watches, $this->notification_rows, $this->audit_rows );
        return $this->failure !== 'begin';
    }
    function rows( $sql, $p = array() ) {
        if ( $this->failure === 'read' || ( $this->failure === 'post_read' && $this->mutated ) ) { $this->last_error = 'private DB detail'; return false; }
        if ( str_contains( $sql, 'information_schema.TABLES' ) && ( $p[0] ?? '' ) === 'wp_law_watch_settings' ) { return array( array( 'TABLE_NAME' => $p[0], 'ENGINE' => $this->engine ) ); }
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) {
            if ( $this->failure === 'invalid_count' ) { return array( array( 'total' => null ) ); }
            if ( str_contains( $sql, 'law_watch_settings' ) ) { return array( array( 'total' => (string) count( array_filter( $this->watches, static fn( $r ) => $r['user_id'] === $p[0] ) ) ) ); }
            if ( str_contains( $sql, 'wp_usermeta' ) && $p[1] === 'egov_law_monitor_notifications' ) { return array( array( 'total' => (string) count( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] === $p[0] && $r['meta_key'] === $p[1] ) ) ) ); }
            throw new LogicException( 'Unexpected count' );
        }
        if ( str_starts_with( $sql, 'SELECT action, new_value' ) ) {
            if ( str_contains( $sql, 'WHERE user_id = ? AND action = ?' ) ) {
                foreach ( array_reverse( $this->audit_rows, true ) as $id => $row ) { if ( $id >= $p[2] && $row['user_id'] === $p[0] && $row['action'] === $p[1] ) { return array( $row ); } }
                return array();
            }
            return isset( $this->audit_rows[$p[0]] ) && $this->audit_rows[$p[0]]['user_id'] === $p[1] ? array( $this->audit_rows[$p[0]] ) : array();
        }
        return parent::rows( $sql, $p );
    }
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'DELETE FROM `wp_law_watch_settings` WHERE user_id = ?' ) ) {
            if ( $this->failure === 'delete' ) { return false; }
            $this->mutated = true; ++$this->deletes;
            if ( $this->failure !== 'residual' ) { $this->watches = array_values( array_filter( $this->watches, static fn( $r ) => $r['user_id'] !== $p[0] ) ); }
            return true;
        }
        if ( str_starts_with( $sql, 'DELETE FROM `wp_usermeta` WHERE user_id = ? AND meta_key = ?' ) ) {
            if ( $this->failure === 'meta_delete' ) { return false; }
            if ( $p[1] !== 'egov_law_monitor_notifications' ) { throw new LogicException( 'Other meta touched' ); }
            $this->notification_rows = array_values( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] !== $p[0] || $r['meta_key'] !== $p[1] ) ); return true;
        }
        if ( str_starts_with( $sql, 'INSERT INTO `wp_egov_square_admin_log`' ) ) {
            if ( in_array( $this->failure, array( 'audit', 'audit_rollback' ), true ) ) { return false; }
            $this->insert_id = count( $this->audit_rows ) + 10;
            $this->audit_rows[$this->insert_id] = array( 'user_id' => $p[0], 'admin_user_id' => $p[1], 'action' => $p[2], 'new_value' => $p[4], 'old_value' => $p[3] ); return true;
        }
        if ( str_starts_with( $sql, 'UPDATE `wp_egov_user_withdrawals` SET revision' ) ) {
            if ( $this->failure === 'state' ) { return false; }
            $row = &$GLOBALS['withdrawal_records'][$p[3]];
            if ( ! $row || $row['process_uuid'] !== $p[2] || $row['phase'] !== $p[4] || (int) $row['revision'] !== $p[5] ) { $this->affected_rows = 0; return true; }
            $row['revision']++; $row['last_audit_id'] = $p[0]; $this->affected_rows = 1; return true;
        }
        throw new LogicException( 'Forbidden mutation: ' . $sql );
    }
    function rollback() {
        if ( in_array( $this->failure, array( 'rollback', 'audit_rollback' ), true ) ) { return false; }
        if ( $this->failure === 'commit' ) { return true; } // Simulated lost successful COMMIT response.
        list( $GLOBALS['withdrawal_records'], $this->watches, $this->notification_rows, $this->audit_rows ) = $this->snapshot_backup; return true;
    }
}
function watch_cleanup_fixture() {
    start_fixture();
    $GLOBALS['cleanup_cache_events'] = array(); $GLOBALS['cleanup_meta_cache'] = array( 10 => 'stale' ); $GLOBALS['cleanup_committed'] = false; $GLOBALS['cleanup_cache_throw'] = false;
    $GLOBALS['withdrawal_records'][10] = array( 'user_id' => 10, 'process_uuid' => '10000000-0000-4000-8000-000000000000', 'phase' => 'started', 'revision' => 1, 'last_audit_id' => null );
    $db = new WatchCleanupDB();
    $db->watches = array( array( 'user_id' => 10, 'is_active' => 1 ), array( 'user_id' => 10, 'is_active' => 0 ), array( 'user_id' => 20, 'is_active' => 1 ) );
    $db->notification_rows = array( array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_notifications' ), array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_notifications' ), array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_standard_consent' ) );
    $state = $GLOBALS['withdrawal_records'][10];
    $fields = array( 'watch_nonce' => 'valid:egov_withdrawal_watches_10', 'confirm_watch_delete' => '1', 'watch_token' => egov_law_monitor_withdrawal_watch_token( 10, $state, egov_law_monitor_withdrawal_watch_snapshot( 10, $state, $db ) ) );
    return array( $db, $fields );
}
list( $db, $fields ) = watch_cleanup_fixture();
$result = egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db );
preflight_check( ! is_wp_error( $result ) && ! $result['already'] && $result['remaining'] === array( 'watches' => 0, 'notifications' => 0 ), 'active and stopped watches and notification meta removed' );
preflight_check( $db->watches === array( array( 'user_id' => 20, 'is_active' => 1 ) ) && count( $db->notification_rows ) === 2, 'other user and consent meta untouched' );
preflight_check( $withdrawal_records[10]['phase'] === 'started' && $withdrawal_records[10]['revision'] === 2 && count( $db->audit_rows ) === 1, 'cleanup recorded without claiming AI cleanup' );
preflight_check( ! isset( $GLOBALS['cleanup_meta_cache'][10] ) && in_array( array( 10, 'user_meta', true ), $GLOBALS['cleanup_cache_events'], true ), 'watch metadata cache invalidated after COMMIT, including repopulated stale cache' );
preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === 1, 'stale double submit refused' );
$state = $withdrawal_records[10]; $fields['watch_token'] = egov_law_monitor_withdrawal_watch_token( 10, $state, egov_law_monitor_withdrawal_watch_snapshot( 10, $state, $db ) );
$deletes = $db->deletes;
preflight_check( egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db )['already'] && $db->deletes === $deletes && count( $db->audit_rows ) === 1, 'fresh retry of completed zero cleanup is no-op' );
$db->watches[] = array( 'user_id' => 10, 'is_active' => 1 );
$fields['watch_token'] = egov_law_monitor_withdrawal_watch_token( 10, $state, egov_law_monitor_withdrawal_watch_snapshot( 10, $state, $db ) );
preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === 2, 'late residual can be explicitly removed again' );
foreach ( array( 'schema', 'begin', 'read', 'invalid_count', 'delete', 'meta_delete', 'post_read', 'residual', 'audit', 'state', 'commit', 'audit_rollback' ) as $failure ) {
    list( $db, $fields ) = watch_cleanup_fixture(); $before = array( $db->watches, $db->notification_rows, $withdrawal_records ); $db->failure = $failure;
    $result = egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $result ) && $db->closed, 'failure not successful: ' . $failure );
    if ( in_array( $failure, array( 'commit', 'audit_rollback' ), true ) ) { preflight_check( $result->code === 'withdrawal_watches_unknown', 'unknown commit/rollback explicitly reported' ); }
    else { preflight_check( array( $db->watches, $db->notification_rows, $withdrawal_records ) === $before && ! $db->audit_rows, 'partial failure rolled back: ' . $failure ); }
}
list( $db, $fields ) = watch_cleanup_fixture(); $db->watches = array(); $db->notification_rows = array();
$fields['watch_token'] = egov_law_monitor_withdrawal_watch_token( 10, $withdrawal_records[10], egov_law_monitor_withdrawal_watch_snapshot( 10, $withdrawal_records[10], $db ) );
preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === 1, 'initially empty data safely gets cleanup audit' );
foreach ( array( null, 'cleaned', 'deleting', 'completed', 'invalid' ) as $phase ) {
    list( $db, $fields ) = watch_cleanup_fixture();
    if ( $phase === null ) { $withdrawal_records = array(); } else { $withdrawal_records[10]['phase'] = $phase; }
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db ) ) && $db->deletes === 0, 'only started phase accepted' );
}
foreach ( array( 'nonce', 'confirm', 'token', 'user', 'role', 'cap', 'actor', 'permission', 'engine', 'changed_counts' ) as $case ) {
    list( $db, $fields ) = watch_cleanup_fixture(); $uid = 10;
    if ( $case === 'nonce' ) { $fields['watch_nonce'] = 'bad'; }
    if ( $case === 'confirm' ) { unset( $fields['confirm_watch_delete'] ); }
    if ( $case === 'token' ) { $fields['watch_token'] = 'tampered'; }
    if ( $case === 'user' ) { $uid = 20; }
    if ( $case === 'role' ) { $person->roles = array( 'subscriber', 'editor' ); }
    if ( $case === 'cap' ) { $caps = array( 'manage_options' ); }
    if ( $case === 'actor' ) { $actor = 10; }
    if ( $case === 'permission' ) { $allowed = false; }
    if ( $case === 'engine' ) { $db->engine = 'MyISAM'; }
    if ( $case === 'changed_counts' ) { $db->watches[] = array( 'user_id' => 10, 'is_active' => 1 ); }
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_watches( $uid, $fields, fn() => $db ) ) && $db->deletes === 0, 'invalid operation refused: ' . $case );
}
class WatchCleanupReadBridge {
    public $prefix = 'wp_', $usermeta = 'wp_usermeta', $last_error = '';
    function __construct( public $db ) {}
    function prepare( $sql, ...$p ) { return array( $sql, $p ); }
    function get_results( $query, $mode ) { list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() ); $result = $this->db->rows( str_replace( array( '%s', '%d' ), '?', $sql ), $p ); $this->last_error = $this->db->last_error; return $result; }
}
function render_watch_step( $state, $result = null ) {
    ob_start(); egov_law_monitor_render_withdrawal_watch_step( 10, $state, false, $result ); return ob_get_clean();
}
list( $db, $fields ) = watch_cleanup_fixture(); $wpdb = new WatchCleanupReadBridge( $db );
$html = render_watch_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '合計）：2件' ) && str_contains( $html, '通知設定メタ：1件' ) && str_contains( $html, 'name="confirm_watch_delete"' ) && str_contains( $html, '法令ウォッチを削除</button>' ), 'step5 shows all target counts and explicit confirmation' );
// Use original WP identifier fixture for the mutation, then re-read the result for display.
$wpdb = new PreflightStore();
$result = egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db );
$wpdb = new WatchCleanupReadBridge( $db ); $html = render_watch_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '削除済み。現在の残存確認：0件' ) && ! str_contains( $html, '<button' ), 'step5 displays recorded cleanup and current zero residual' );
$db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); $html = render_watch_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '残存データがあります' ) && str_contains( $html, '<button' ), 'late residual is visible and can be explicitly retried' );
$db->failure = 'read'; $html = render_watch_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '読み取りエラー' ) && ! str_contains( $html, '<button' ), 'read failure never shows completed/zero result' );
$db->failure = ''; $db->last_error = ''; $html = render_watch_step( $withdrawal_records[10], new WP_Error( 'unknown', 'unknown' ) );
preflight_check( ! str_contains( $html, '<button' ), 'failed POST requires fresh reload before another destructive action' );
list( $db, $fields ) = watch_cleanup_fixture(); $GLOBALS['cleanup_cache_throw'] = true;
$result = egov_law_monitor_delete_withdrawal_watches( 10, $fields, fn() => $db );
preflight_check( is_wp_error( $result ) && $result->code === 'withdrawal_watches_cache_warning' && count( $db->audit_rows ) === 1, 'watch post-COMMIT cache exception warns without pretending rollback' );
$GLOBALS['cleanup_cache_throw'] = false;
echo "Withdrawal watch cleanup tests passed; no live DB, API or user deletion.\n";
