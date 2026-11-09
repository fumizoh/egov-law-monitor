<?php
/** php tests/square-approval-test.php: isolated DB/cache doubles, no real I/O. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    public function __construct( public $code, public $message ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_userdata( $id ) { return isset( $GLOBALS['wpdb']->people[$id] ) ? (object) array( 'ID' => $id ) : false; }
function get_current_user_id() { return $GLOBALS['checkout_user'] ?? 99; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
function wp_salt( $scheme ) { return 'test-key'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $format ) { return $format === 'timestamp' ? strtotime( $GLOBALS['now'] . ' UTC' ) : $GLOBALS['now']; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function get_user_meta( $id, $key, $single ) {
    if ( $key === 'egov_law_monitor_ai_summary_trial_usage' ) { return $GLOBALS['trial_usage']; }
    if ( array_key_exists( $id, $GLOBALS['cache'] ) ) { return $GLOBALS['cache'][$id]; }
    $rows = array_filter( $GLOBALS['wpdb']->meta, static fn( $row ) => $row['user_id'] === $id && $row['meta_key'] === $key );
    return $GLOBALS['cache'][$id] = $rows ? reset( $rows )['meta_value'] : '';
}
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function add_action( ...$args ) {}
function register_activation_hook( ...$args ) {}
function wp_cache_delete( $id, $group ) {
    if ( ! empty( $GLOBALS['cache_failure'] ) ) { throw new RuntimeException( 'private cache error' ); }
    $GLOBALS['evictions'][] = array( $id, $group ); unset( $GLOBALS['cache'][$id] ); return true;
}
function update_user_meta( ...$args ) { throw new LogicException( 'Shared metadata writes forbidden' ); }
function wp_safe_remote_get( ...$args ) { throw new LogicException( 'API forbidden' ); }
require __DIR__ . '/../wordpress-plugin/includes/square-subscriptions.php';
require __DIR__ . '/../wordpress-plugin/includes/square-approval.php';
require __DIR__ . '/../wordpress-plugin/includes/user-settings.php';
require __DIR__ . '/../wordpress-plugin/includes/database.php';
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-users.php';
require __DIR__ . '/../wordpress-plugin/includes/watch-api.php';

/** Shared database state. Core user tables deliberately use a different prefix. */
class ApprovalStore {
    public $prefix = 'site_';
    public $users = 'global_users';
    public $usermeta = 'global_usermeta';
    public $last_error = '';
    public $people = array( 10 => array( 'ID' => 10 ), 20 => array( 'ID' => 20 ) );
    public $meta = array();
    public $contracts = array();
    public $logs = array();
    public $engine = 'InnoDB';
    public $bad_schema = '';
    public $fail = '';
    public $commit_applied = false;
    public $queries = array();
    public $sql_queries = array();
    public $read_fail = '';
    public $watches = array();
    public $watch_fail = false;
    public function __construct() {
        $this->contracts[1] = array( 'id' => 1, 'user_id' => 10, 'square_customer_id' => 'CUSTOMER', 'square_subscription_id' => 'SUB1', 'verified_at' => null, 'verified_by' => null, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01', 'admin_note' => '' );
        $this->contracts[2] = array_replace( $this->contracts[1], array( 'id' => 2, 'square_subscription_id' => 'SUB2' ) );
    }
    public function prepare( $sql, ...$params ) { return array( $sql, $params ); }
    public function get_results( $query, $mode ) {
        $this->last_error = '';
        if ( $this->read_fail !== '' && str_contains( $query[0], $this->read_fail ) ) { $this->last_error = 'private read error'; return null; }
        if ( str_contains( $query[0], 'law_watch_settings' ) ) { return array_values( array_filter( $this->watches, static fn( $row ) => $row['user_id'] === $query[1][0] && $row['is_active'] === 1 ) ); }
        return $this->select( $query[0], $query[1] );
    }
    public function update( $table, $data, $where, $formats, $where_formats ) {
        $this->last_error = '';
        if ( ! str_ends_with( $table, 'law_watch_settings' ) ) { throw new LogicException( 'Unexpected update' ); }
        if ( $this->watch_fail === true || $this->watch_fail === $where['id'] ) { $this->last_error = 'private SQL failure'; return false; }
        $this->watches[$where['id']] = array_replace( $this->watches[$where['id']], $data );
        return 1;
    }
    public function get_var( $query ) {
        if ( ! str_contains( $query[0], 'egov_law_ai_summary_history' ) ) { throw new LogicException( 'Unexpected policy read' ); }
        return $GLOBALS['monthly_usage'];
    }
    public function select( $sql, $p ) {
        if ( str_contains( $sql, 'information_schema.TABLES' ) ) { return array( array( 'TABLE_NAME' => $p[0], 'ENGINE' => $this->engine ) ); }
        if ( str_starts_with( $sql, 'SHOW TABLE' ) ) { return array( array( 'Engine' => $this->engine ) ); }
        if ( str_starts_with( $sql, 'SHOW' ) ) {
            $core = str_contains( $sql, 'global_' );
            $users = str_contains( $sql, 'global_users' );
            $log = str_contains( $sql, 'admin_log' );
            if ( str_starts_with( $sql, 'SHOW INDEX' ) ) {
                $primary = $core ? ( $users ? 'ID' : 'umeta_id' ) : 'id';
                $indexes = array( array( 'Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => $primary, 'Non_unique' => 0, 'Sub_part' => null ), array( 'Key_name' => 'user_id', 'Seq_in_index' => 1, 'Column_name' => 'user_id', 'Non_unique' => 1, 'Sub_part' => null ) );
                if ( ! $core && ! $log ) { $indexes[] = array( 'Key_name' => 'square_subscription_id', 'Seq_in_index' => 1, 'Column_name' => 'square_subscription_id', 'Non_unique' => 0, 'Sub_part' => null ); }
                return $this->bad_schema === 'primary' && $core ? array() : $indexes;
            }
            $definitions = $core ? ( $users ? array( 'ID' => 'bigint unsigned' ) : array( 'umeta_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext' ) ) : array_map( static fn( $column ) => $column[0], egov_law_monitor_square_columns()[$log ? 'egov_square_admin_log' : 'egov_square_subscriptions'] );
            $nullable = array( 'verified_at', 'verified_by', 'admin_note', 'old_value', 'new_value', 'reason' );
            $columns = array();
            foreach ( $definitions as $name => $type ) { $columns[] = array( 'Field' => $name, 'Type' => $this->bad_schema === 'column' && $core ? 'wrong' : $type, 'Null' => in_array( $name, $nullable, true ) ? 'YES' : 'NO', 'Extra' => in_array( $name, array( 'id', 'ID', 'umeta_id' ), true ) ? 'auto_increment' : '' ); }
            return $columns;
        }
        if ( str_contains( $sql, 'global_users' ) ) { return isset( $this->people[$p[0]] ) ? array( $this->people[$p[0]] ) : array(); }
        if ( str_contains( $sql, 'global_usermeta' ) ) {
            return array_values( array_filter( $this->meta, static fn( $row ) => $row['user_id'] === $p[0] && $row['meta_key'] === $p[1] ) );
        }
        if ( str_contains( $sql, 'admin_log' ) ) {
            $logs = array_filter( $this->logs, static fn( $row ) => $row['user_id'] === $p[0] && in_array( $row['action'], array( 'standard_approved', 'plan_changed' ), true ) );
            $last = end( $logs );
            return $last ? array( array( 'id' => $last['id'] ) ) : array();
        }
        return isset( $this->contracts[$p[0]] ) ? array( $this->contracts[$p[0]] ) : array();
    }
}

/** Independent transactional session; only COMMIT publishes changes. */
class ApprovalSession extends EGOV_Law_Monitor_Square_Connection {
    public $local;
    public $closed = false;
    public $began = false;
    private $store;
    public function __construct( $store ) { $this->store = $store; $this->local = clone $store; $this->prefix = $store->prefix; }
    private function gate( $step ) {
        $this->store->queries[] = $step;
        if ( $this->closed || $this->connection_lost ) { return false; }
        if ( $this->store->fail === 'disconnect:' . $step ) { $this->connection_lost = true; return false; }
        return $this->store->fail !== $step && ! ( $this->store->fail === 'audit_rollback' && in_array( $step, array( 'audit', 'rollback' ), true ) );
    }
    public function rows( $sql, $params = array() ) {
        $this->store->sql_queries[] = $sql;
        if ( ! $this->gate( 'read' ) ) { $this->last_error = 'failed'; return false; }
        return $this->local->select( $sql, $params );
    }
    public function begin() {
        if ( ! $this->gate( 'begin' ) ) { return false; }
        $this->local = clone $this->store;
        return $this->began = true;
    }
    public function write( $sql, $p = array() ) {
        $step = str_contains( $sql, 'admin_log' ) ? 'audit' : ( str_contains( $sql, 'global_usermeta' ) ? 'meta' : 'contract' );
        if ( ! $this->gate( $step ) ) { return false; }
        if ( $step === 'audit' ) {
            $row = array_combine( array( 'user_id', 'admin_user_id', 'action', 'old_value', 'new_value', 'reason', 'created_at' ), $p );
            $row['id'] = count( $this->local->logs ) + 1;
            $this->local->logs[] = $row;
        } elseif ( $step === 'contract' ) {
            $this->local->contracts[$p[3]] = array_replace( $this->local->contracts[$p[3]], array( 'verified_at' => $p[0], 'verified_by' => $p[1], 'updated_at' => $p[2] ) );
        } elseif ( str_starts_with( $sql, 'INSERT' ) ) {
            $id = count( $this->local->meta ) + 1;
            $this->local->meta[$id] = array( 'umeta_id' => $id, 'user_id' => $p[0], 'meta_key' => $p[1], 'meta_value' => $p[2] );
        } else { $this->local->meta[$p[1]]['meta_value'] = $p[0]; }
        return true;
    }
    public function commit() {
        $ok = $this->gate( 'commit' );
        if ( $ok || $this->store->commit_applied ) {
            foreach ( array( 'meta', 'contracts', 'logs' ) as $name ) { $this->store->$name = $this->local->$name; }
        }
        return $ok;
    }
    public function rollback() { return $this->gate( 'rollback' ); }
    public function close() { $this->closed = true; }
}

$checks = 0;
function check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; echo "PASS: {$label}\n"; }
function reset_store() {
    $GLOBALS['wpdb'] = new ApprovalStore(); $GLOBALS['allowed'] = true;
    $GLOBALS['now'] = '2026-11-08 12:00:00'; $GLOBALS['evictions'] = array(); $GLOBALS['cache'] = array( 10 => 'free' );
    $GLOBALS['cache_failure'] = false;
    $GLOBALS['trial_usage'] = 2; $GLOBALS['monthly_usage'] = 37;
}
function token( $id = 1, $user = 10 ) { return egov_law_monitor_square_plan_form_token( $user, $id ? ( $GLOBALS['wpdb']->contracts[$id] ?? null ) : null ); }
function change( $plan = 'standard', $id = 1, $snapshot = null, $user = 10, $confirmed = true, $nonce = null, $reason = 'Dashboardで確認' ) {
    $GLOBALS['session'] = new ApprovalSession( $GLOBALS['wpdb'] );
    return egov_law_monitor_square_change_plan( $user, $plan, $snapshot ?? token( $id, $user ), $nonce ?? 'valid:' . ( $id ? 'egov_square_approve_' . $user . '_' . $id : 'egov_square_free_' . $user ), $id, $confirmed, $reason, $GLOBALS['session'] );
}
reset_store();
check( egov_law_monitor_get_plan( 10 ) === 'free' && egov_law_monitor_get_ai_summary_limit( 10 ) === 3 && egov_law_monitor_get_watch_limit( 10 ) === 5, 'existing Free policy before approval' );
$old = token();
check( change() === true, 'approval successful with absent meta' );
check( $wpdb->meta[1]['meta_value'] === 'standard' && $wpdb->contracts[1]['verified_by'] === 99 && $wpdb->contracts[1]['verified_at'] === $now, 'plan and confirmation committed together' );
check( $wpdb->contracts[2]['verified_at'] === null, 'only selected contract verified' );
$audit = $wpdb->logs[0];
check( $audit['action'] === 'standard_approved' && $audit['admin_user_id'] === 99 && $audit['reason'] === 'Dashboardで確認', 'approval audit actor/action/reason' );
check( json_decode( $audit['old_value'], true )['plan'] === 'free' && json_decode( $audit['new_value'], true )['contract']['square_subscription_id'] === 'SUB1', 'audit exact old/new plan and contract IDs' );
check( $session->closed && $evictions === array( array( 10, 'user_meta' ) ) && ! isset( $cache[10] ), 'owned connection closed and stale cache evicted' );
check( egov_law_monitor_get_plan( 10 ) === 'standard' && egov_law_monitor_get_ai_summary_limit( 10 ) === 100 && egov_law_monitor_get_watch_limit( 10 ) === 10, 'existing Standard policy reads committed meta after cache invalidation' );
check( egov_law_monitor_get_ai_summary_trial_usage_count( 10 ) === 2 && egov_law_monitor_get_ai_summary_usage_count( 10 ) === 37, 'approval preserves Free lifetime and monthly history counters' );
check( count( array_filter( $wpdb->sql_queries, static fn( $sql ) => str_contains( $sql, 'FOR UPDATE' ) ) ) === 3, 'user contract and meta locked on dedicated session' );
check( change( 'standard', 1, $old ) instanceof WP_Error && count( $wpdb->logs ) === 1, 'replayed approval cannot add history' );
check( change()->code === 'square_plan_failed' && count( $wpdb->logs ) === 1, 'fresh duplicate approval refused' );
check( change( 'free', 0 ) === true && $wpdb->meta[1]['meta_value'] === 'free', 'Free change shares transaction path' );
check( $wpdb->contracts[1]['verified_by'] === 99 && count( $wpdb->logs ) === 2 && $wpdb->logs[1]['action'] === 'plan_changed', 'downgrade retains historical confirmation and audit' );
check( egov_law_monitor_get_plan( 10 ) === 'free' && egov_law_monitor_get_ai_summary_limit( 10 ) === 3 && egov_law_monitor_get_ai_summary_trial_usage_count( 10 ) === 2, 'downgrade restores Free policy without resetting trial usage' );
check( change() === true && count( $wpdb->logs ) === 3, 'reapproval after downgrade adds fresh audit' );
reset_store();
$contractToken = token(); $legacyToken = token( 0 ); $otherContractToken = token( 2 );
check( change() === true, 'first administrator wins' );
check( change( 'free', 0, $legacyToken ) instanceof WP_Error && $wpdb->meta[1]['meta_value'] === 'standard', 'old Free form cannot reverse approval' );
check( change( 'standard', 2, $otherContractToken ) instanceof WP_Error, 'other contract cannot double approve same user' );
check( change( 'free', 0 ) === true, 'fresh downgrade accepted' );
check( change( 'standard', 1, $contractToken ) instanceof WP_Error, 'old approval rejected after free-standard-free cycle' );
reset_store(); $old = token(); $wpdb->contracts[1]['admin_note'] = 'edited';
check( change( 'standard', 1, $old ) instanceof WP_Error, 'contract edit invalidates approval snapshot' );
reset_store();
check( change( 'standard', 1, null, 20 ) instanceof WP_Error, 'other user contract refused' );
check( change( 'standard', 999 ) instanceof WP_Error, 'missing contract refused' );
check( change( 'standard', 1, '', 999 ) instanceof WP_Error, 'missing user refused' );
$allowed = false;
check( change() instanceof WP_Error && ! $session->began, 'permission failure before transaction' );
$allowed = true;
check( change( 'standard', 1, null, 10, true, 'wrong' ) instanceof WP_Error && ! $session->began, 'bad nonce before transaction' );
check( change( 'standard', 1, null, 10, true, 'valid:egov_square_approve_20_1' ) instanceof WP_Error, 'nonce bound to user and contract' );
check( change( 'standard', 1, null, 10, false ) instanceof WP_Error, 'explicit Dashboard confirmation required' );
check( change( 'standard', 1, null, 10, true, null, '' ) instanceof WP_Error, 'confirmation reason required' );
check( change( 'free', 1 ) instanceof WP_Error, 'approval cannot downgrade' );
check( change( 'invalid', 0 ) instanceof WP_Error, 'unknown target plan refused' );
reset_store();
foreach ( array( '', 'free' ) as $raw ) {
    $wpdb->meta = array( 1 => array( 'umeta_id' => 1, 'user_id' => 10, 'meta_key' => EGOV_LAW_MONITOR_META_PLAN, 'meta_value' => $raw ) );
    check( change() === true && count( $wpdb->meta ) === 1, 'existing Free/empty meta updated without insertion' );
    reset_store();
}
$wpdb->meta = array( 1 => array( 'umeta_id' => 1, 'user_id' => 10, 'meta_key' => EGOV_LAW_MONITOR_META_PLAN, 'meta_value' => 'unknown' ) );
check( is_wp_error( token() ), 'unknown stored plan blocks form token' );
check( change( 'standard', 1, 'forged' ) instanceof WP_Error && ! $wpdb->logs, 'unknown stored plan blocks save' );
$wpdb->meta[1]['meta_value'] = 'free'; $wpdb->meta[2] = array_replace( $wpdb->meta[1], array( 'umeta_id' => 2 ) );
check( is_wp_error( token() ) && change( 'standard', 1, 'forged' ) instanceof WP_Error, 'duplicate plan rows rejected, never repaired' );
foreach ( array( 'MyISAM', 'missing' ) as $engine ) {
    reset_store(); $wpdb->engine = $engine;
    check( change() instanceof WP_Error && ! $session->began, 'nontransactional/missing tables refused' );
}
foreach ( array( 'column', 'primary' ) as $schema ) {
    reset_store(); $wpdb->bad_schema = $schema;
    check( change() instanceof WP_Error && ! $session->began, 'core table schema failure refused' );
}
reset_store(); $wpdb->usermeta = 'other_db.users';
check( is_wp_error( token() ), 'cross-database table name rejected' );
foreach ( array( 'begin', 'meta', 'contract', 'audit', 'audit_rollback', 'commit', 'disconnect:meta', 'disconnect:contract', 'disconnect:audit', 'disconnect:commit' ) as $failure ) {
    reset_store(); $wpdb->fail = $failure;
    $before = array( $wpdb->meta, $wpdb->contracts, $wpdb->logs );
    $result = change();
    check( $result instanceof WP_Error && array( $wpdb->meta, $wpdb->contracts, $wpdb->logs ) === $before, 'atomic failure: ' . $failure );
    $unknown = $failure === 'audit_rollback' || $failure === 'commit' || str_starts_with( $failure, 'disconnect:' );
    check( $result->code === ( $unknown ? 'square_plan_outcome_unknown' : 'square_plan_failed' ) && $session->closed, 'failure classified and connection closed: ' . $failure );
    if ( $unknown ) { check( ! str_contains( $result->message, '取り消しました' ), 'uncertain outcome never claims rollback' ); }
}
reset_store(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
check( change()->code === 'square_plan_outcome_unknown' && $wpdb->meta[1]['meta_value'] === 'standard' && count( $wpdb->logs ) === 1, 'commit applied but response lost remains unknown' );
check( ! isset( $cache[10] ), 'unknown outcome also clears cache' );
check( change( 'standard', 1, $old ) instanceof WP_Error && count( $wpdb->logs ) === 1, 'retry unknown committed operation cannot duplicate approval' );
reset_store();
check( change( 'free', 0 ) instanceof WP_Error && ! $wpdb->logs && ! $wpdb->meta, 'already Free is rejected without creating meta or audit' );
check( ! array_filter( $wpdb->queries, static fn( $query ) => in_array( $query, array( 'meta', 'contract', 'audit' ), true ) ), 'already-Free request performs no writes' );
reset_store(); $cache_failure = true;
$result = change();
check( $result->code === 'square_plan_cache_warning' && $wpdb->meta[1]['meta_value'] === 'standard' && count( $wpdb->logs ) === 1, 'post-commit cache exception reports saved data and no reapproval' );
check( ! str_contains( $result->message, 'private cache error' ), 'cache details suppressed' );

function seed_watches() {
    for ( $i = 1; $i <= 8; ++$i ) { $GLOBALS['wpdb']->watches[$i] = array( 'id' => $i, 'user_id' => 10, 'is_active' => 1 ); }
}
function make_free( $signature = null, $nonce = 'valid:egov_square_free_10', $reason = '支払確認の結果、Freeへ変更' ) {
    $GLOBALS['session'] = new ApprovalSession( $GLOBALS['wpdb'] );
    return egov_law_monitor_square_make_free( 10, $signature ?? token( 0 ), $nonce, $reason, $GLOBALS['session'] );
}
reset_store(); change(); seed_watches();
$contracts = $wpdb->contracts; $first_approval = $wpdb->logs[0]; $free_form = token( 0 );
$result = make_free();
check( is_array( $result ) && ! $result['warning'] && egov_law_monitor_get_plan( 10 ) === 'free', 'user-level Free operation succeeds' );
check( $wpdb->contracts === $contracts && $wpdb->logs[0] === $first_approval, 'Free keeps all contracts confirmation and past approval' );
$audit = $wpdb->logs[1];
check( $audit['action'] === 'plan_changed' && $audit['reason'] === '支払確認の結果、Freeへ変更' && $audit['admin_user_id'] === 99 && $audit['created_at'] === $now, 'Free audit includes action reason admin and timestamp' );
check( json_decode( $audit['old_value'], true )['plan'] === 'standard' && json_decode( $audit['new_value'], true )['plan'] === 'free', 'Free audit accurately records old and new plan' );
check( count( array_filter( $wpdb->watches, static fn( $row ) => $row['is_active'] === 1 ) ) === 5 && $wpdb->watches[1]['is_active'] === 1 && $wpdb->watches[8]['is_active'] === 0, 'actual watch enforcer retains oldest five and stops excess' );
check( egov_law_monitor_get_ai_summary_trial_usage_count( 10 ) === 2 && egov_law_monitor_get_ai_summary_usage_count( 10 ) === 37, 'Free operation preserves AI usage counters' );
check( make_free( $free_form ) instanceof WP_Error && count( $wpdb->logs ) === 2, 'replayed Free form cannot duplicate history' );
check( make_free() instanceof WP_Error && count( $wpdb->logs ) === 2, 'fresh already-Free operation refused' );
check( change() === true && count( $wpdb->logs ) === 3 && $wpdb->watches[8]['is_active'] === 0, 'Standard reapproval adds history without reactivating stopped watches' );
check( make_free( $free_form ) instanceof WP_Error, 'old Free form refused after downgrade and reapproval' );
foreach ( array( '', '  ', '<script></script>' ) as $reason ) {
    reset_store(); change();
    check( make_free( null, 'valid:egov_square_free_10', $reason ) instanceof WP_Error && $wpdb->meta[1]['meta_value'] === 'standard' && count( $wpdb->logs ) === 1, 'Free reason required after sanitization' );
}
reset_store(); change();
check( make_free( null, 'valid:egov_law_monitor_save_user' )->code === 'invalid_nonce', 'old user management nonce refused internally' );
check( make_free( null, 'valid:egov_square_free_20' )->code === 'invalid_nonce', 'Free nonce is user-specific' );
check( change( 'standard', 0 )->code === 'invalid_plan_request', 'contract-less Standard route is disabled' );
$allowed = false;
check( make_free()->code === 'forbidden', 'Free operation checks admin permission' );
foreach ( array( true, 6 ) as $failure ) {
    reset_store(); change(); seed_watches(); $wpdb->watch_fail = $failure;
    $result = make_free();
    check( $result['warning'] && str_contains( $result['message'], '確定済み' ) && ! str_contains( $result['message'], 'private' ), 'watch write failure reported separately from committed plan' );
    check( $wpdb->meta[1]['meta_value'] === 'free' && count( $wpdb->logs ) === 2 && count( array_filter( $wpdb->watches, static fn( $row ) => $row['is_active'] === 1 ) ) > 5, 'partial watch failure never rolls back plan or audit' );
}
reset_store(); change(); seed_watches(); $wpdb->read_fail = 'law_watch_settings';
check( make_free()['warning'] && $wpdb->meta[1]['meta_value'] === 'free', 'watch read failure reports committed Free with warning' );
reset_store(); change(); seed_watches(); $cache_failure = true;
check( make_free()['warning'] && $wpdb->meta[1]['meta_value'] === 'free' && $wpdb->watches[8]['is_active'] === 1, 'cache failure reports committed Free and skips unsafe watch enforcement' );
foreach ( array( 'meta', 'audit', 'audit_rollback', 'commit', 'disconnect:commit' ) as $failure ) {
    reset_store(); change(); seed_watches(); $wpdb->fail = $failure;
    $result = make_free();
    check( $result instanceof WP_Error && $wpdb->meta[1]['meta_value'] === 'standard' && count( $wpdb->logs ) === 1 && $wpdb->watches[8]['is_active'] === 1, 'Free transaction failure never starts watch enforcement: ' . $failure );
}
reset_store(); change(); seed_watches(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
check( make_free()->code === 'square_plan_outcome_unknown' && $wpdb->meta[1]['meta_value'] === 'free' && $wpdb->watches[8]['is_active'] === 1, 'Free COMMIT response loss stays unknown without post-commit watch writes' );
reset_store(); change(); $wpdb->contracts = array();
check( ! make_free()['warning'] && $wpdb->meta[1]['meta_value'] === 'free', 'Free is user-level even without contract rows' );
echo "{$checks} checks passed. No real DB, cache or Square API used.\n";
