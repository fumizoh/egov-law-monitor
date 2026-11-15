<?php
/** Real best-effort guards and business functions; DB/WordPress boundaries only are fake. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public function __construct( public $code, public $message, public $data = array() ) {} public function get_error_message() { return $this->message; } }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_REST_Request { public function __construct( public $keyword ) {} public function get_param( $key ) { return $this->keyword; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $name, $callback, ...$args ) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][$name] = $callback; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; $GLOBALS['autoload'] = $autoload; }
function current_user_can( $cap ) { return $GLOBALS['admin']; }
function get_current_user_id() { return $GLOBALS['actor']; }
function get_userdata( $id ) { return $GLOBALS['person']; }
function user_can( $user, $cap ) { return in_array( $cap, $user->caps ?? array(), true ); }
function is_multisite() { return $GLOBALS['multisite']; }
function add_settings_error( ...$args ) {}
function current_time( $format ) { return '2026-11-15 09:00:00'; }
function current_datetime() { return new DateTimeImmutable( '2026-11-15T09:00:00+09:00' ); }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['metadata'][$id][$key] ?? ''; }
function update_user_meta( $id, $key, $value ) {
    $GLOBALS['meta_calls'][] = array( $id, $key, $value );
    if ( $GLOBALS['meta_failure'] ) { return false; }
    if ( ( $GLOBALS['metadata'][$id][$key] ?? null ) === $value ) { return false; }
    $GLOBALS['metadata'][$id][$key] = $value;
    if ( $GLOBALS['meta_hook'] ) { ( $GLOBALS['meta_hook'] )(); }
    return true;
}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][$route] = $args; }
function egov_law_monitor_search_laws( $keyword ) {
    test( ! isset( $GLOBALS['egov_withdrawal_writers'] ), 'no normal-write context during external lookup' );
    if ( $GLOBALS['external_hook'] ) { ( $GLOBALS['external_hook'] )(); }
    return array( 'law' );
}
function dbDelta( $sql ) { $GLOBALS['ddl'][] = $sql; $GLOBALS['wpdb']->exists = true; }
require __DIR__ . '/withdrawal-test-bootstrap.php';
require __DIR__ . '/../wordpress-plugin/includes/user-settings.php';
require __DIR__ . '/../wordpress-plugin/includes/database.php';
require __DIR__ . '/../wordpress-plugin/includes/watch-api.php';
class BusinessStore {
        public $count_failure = '', $prefix = 'wp_', $users = 'wp_users', $last_error = '', $exists = true, $watches = array(), $history = array();
    public function prepare( $sql, ...$params ) { return array( $sql, $params ); }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_results( $query, $mode ) {
        list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() ); $this->last_error = '';
        if ( str_contains( $sql, 'information_schema' ) || str_starts_with( $sql, 'SHOW' ) ) {
            if ( ! $this->exists && str_contains( $sql, 'information_schema' ) ) { return array(); }
            return ( new WithdrawalReadStore() )->rows( $sql, $p );
        }
        if ( str_contains( $sql, 'egov_user_withdrawals' ) ) { return ( new WithdrawalReadStore() )->rows( $sql, $p ); }
        if ( str_contains( $sql, 'law_watch_settings' ) ) { return array_values( array_filter( $this->watches, static fn( $r ) => $r['user_id'] === $p[0] && ( ! str_contains( $sql, 'is_active = 1' ) || $r['is_active'] === 1 ) && ( ! str_contains( $sql, 'keyword = ' ) || $r['keyword'] === $p[1] ) ) ); }
        throw new LogicException( 'unexpected business SELECT' );
    }
    public function get_row( $query, $mode ) { return $this->get_results( $query, $mode )[0] ?? null; }
    public function get_var( $query ) {
        list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() ); $this->last_error = '';
        if ( str_contains( $sql, 'law_watch_settings' ) && str_contains( $sql, 'COUNT' ) && $this->count_failure !== '' ) { if ( $this->count_failure === 'sql' ) { $this->last_error = 'count failed'; return null; } if ( $this->count_failure === 'null' ) { return null; } return $this->count_failure; }
        if ( str_contains( $sql, 'law_watch_settings' ) ) { $rows = $this->get_results( $query, ARRAY_A ); return str_contains( $sql, 'COUNT' ) ? count( $rows ) : ( $rows[0]['id'] ?? null ); }
        if ( str_contains( $sql, 'egov_law_ai_summary_history' ) ) { return count( array_filter( $this->history, static fn( $r ) => $r['user_id'] === $p[0] && ( count( $p ) !== 5 || ( $r['law_id'] === $p[1] && $r['effective_date'] === $p[2] && $r['law_data_id'] === $p[3] && $r['sub_revision'] === $p[4] ) ) ) ); }
        throw new LogicException();
    }
    public function insert( $table, $data, $formats ) { $data['id'] = count( $this->watches ) + 1; $this->watches[$data['id']] = $data; return 1; }
    public function update( $table, $data, $where, ...$formats ) { $this->watches[$where['id']] = array_replace( $this->watches[$where['id']], $data ); return 1; }
    public function delete( $table, $where, ...$formats ) { foreach( $this->watches as $id => $row ) { if ( $row['user_id'] === $where['user_id'] && $row['keyword'] === $where['keyword'] ) { unset( $this->watches[$id] ); return 1; } } return 0; }
    public function replace( $table, $data, $formats ) { $this->history[] = $data; return 1; }
}
$checks = 0; $actor = 10; $admin = true; $multisite = false; $options = array(); $metadata = array(); $meta_calls = array(); $meta_failure = false; $meta_hook = null; $external_hook = null; $ddl = array();
$person = (object) array( 'ID' => 20, 'roles' => array( 'subscriber' ), 'user_login' => 'member', 'caps' => array() );
$wpdb = new BusinessStore();
function test( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function reset_flow() { reset_withdrawal_reads(); $GLOBALS['meta_failure'] = false; $GLOBALS['meta_hook'] = null; $GLOBALS['external_hook'] = null; $GLOBALS['wpdb']->last_error = ''; $GLOBALS['wpdb']->count_failure = ''; }
function seed_process( $phase = 'started', $uid = 10 ) { $GLOBALS['withdrawal_records'][$uid] = array( 'user_id' => $uid, 'process_uuid' => '20000000-0000-4000-8000-000000000001', 'phase' => $phase, 'revision' => 1, 'claim_token' => str_repeat( 'a', 32 ) ); }
$wpdb->exists = false; egov_law_monitor_install_withdrawal_table();
test( count( $ddl ) === 1 && str_contains( $ddl[0], 'UNIQUE KEY user_id' ) && str_contains( $ddl[0], 'ENGINE=InnoDB' ) && $autoload === false, 'schema installed once' );
egov_law_monitor_install_withdrawal_table(); test( count( $ddl ) === 1, 'no repeated DDL' );
reset_flow(); test( egov_law_monitor_withdrawal_status( egov_law_monitor_withdrawal_state( 10 ) ) === 'not_started', 'absent row means not started' );
test( egov_law_monitor_set_notifications( 10, false )['notifications'] === false && $metadata[10]['egov_law_monitor_notifications'] === '0', 'normal OFF standard API save' );
test( egov_law_monitor_set_notifications( 10, false )['notifications'] === false && ! $withdrawal_records, 'same-value save without persistent normal-write data' );
$meta_hook = static function () { $GLOBALS['wpdb']->last_error = 'handled optional hook error'; };
test( egov_law_monitor_set_notifications( 10, true )['notifications'] && $metadata[10]['egov_law_monitor_notifications'] === '1' && ! $withdrawal_records, 'successful standard metadata save is not poisoned by handled hook error' );
foreach ( array( 'started', 'cleaned', 'deleting', 'completed', 'invalid' ) as $phase ) {
    reset_flow(); seed_process( $phase ); $before = count( $meta_calls );
    test( is_wp_error( egov_law_monitor_set_notifications( 10, false ) ) && count( $meta_calls ) === $before, 'state refuses metadata save: ' . $phase );
    if ( $phase !== 'invalid' ) { test( egov_law_monitor_withdrawal_status( egov_law_monitor_withdrawal_state( 10 ) ) === ( $phase === 'completed' ? 'completed' : 'in_progress' ), 'state classification: ' . $phase ); }
}
foreach ( array( 'read', 'index', 'column', 'engine' ) as $failure ) { reset_flow(); $withdrawal_read_failure = $failure; $before = count( $meta_calls ); test( is_wp_error( egov_law_monitor_set_notifications( 10, false ) ) && count( $meta_calls ) === $before, 'failed verification refuses save: ' . $failure ); }
reset_flow(); egov_law_monitor_withdrawal_check_save( 10 ); seed_process();
test( is_wp_error( egov_law_monitor_withdrawal_check_save( 10 ) ), 'only schema is cached; user state is reread' );
reset_flow(); $meta_failure = true; test( is_wp_error( egov_law_monitor_set_notifications( 10, false ) ) && ! $withdrawal_records, 'failed save leaves no normal-write state' );
reset_flow(); $metadata[10]['egov_law_monitor_ai_summary_trial_usage'] = 0;
$args = array( 'law', 'name', '2026-11-15', 'data', '1', 'hash', 'title', 'body' );
test( egov_law_monitor_record_ai_summary_usage( 10, $args ) === true && $metadata[10]['egov_law_monitor_ai_summary_trial_usage'] === 1 && count( $wpdb->history ) === 1, 'real Free history and count saves' );
test( egov_law_monitor_record_ai_summary_usage( 10, $args ) === true && $metadata[10]['egov_law_monitor_ai_summary_trial_usage'] === 1, 'same history not consumed again' );
reset_flow(); seed_process(); $before = count( $wpdb->history ); test( is_wp_error( egov_law_monitor_record_ai_summary_usage( 10, array_replace( $args, array( 0 => 'other' ) ) ) ) && count( $wpdb->history ) === $before, 'AI save after external work refused when withdrawal started' );
reset_flow(); $metadata[10]['egov_law_monitor_ai_summary_trial_usage'] = 3; test( is_wp_error( egov_law_monitor_record_ai_summary_usage( 10, array_replace( $args, array( 0 => 'third' ) ) ) ), 'Free quota unchanged' );
reset_flow(); $metadata[10]['egov_law_monitor_plan'] = 'standard'; test( egov_law_monitor_record_ai_summary_usage( 10, array_replace( $args, array( 0 => 'standard-law' ) ) ) === true && $metadata[10]['egov_law_monitor_ai_summary_trial_usage'] === 3, 'Standard not charged against Free count' );
foreach ( $actions['rest_api_init'] as $callback ) { $callback(); }
$metadata[10]['egov_law_monitor_plan'] = 'free'; reset_flow(); $create = $routes['/watches'][1]['callback'];
test( is_array( $create( new WP_REST_Request( '民法' ) ) ) && count( $wpdb->watches ) === 1, 'normal watch registration' );
reset_flow(); $external_hook = static function () { seed_process(); }; test( is_wp_error( $create( new WP_REST_Request( '商法' ) ) ) && count( $wpdb->watches ) === 1, 'state rechecked after external search' );
reset_flow(); $wpdb->watches[1]['is_active'] = 0; $resume = $routes['/watches/(?P<keyword>[^/]+)/resume']['callback'];
test( is_array( $resume( new WP_REST_Request( '民法' ) ) ) && $wpdb->watches[1]['is_active'] === 1, 'normal resume' );
for( $i = 2; $i <= 6; $i++ ) { $wpdb->watches[$i] = array( 'id' => $i, 'user_id' => 10, 'keyword' => 'law' . $i, 'is_active' => $i <= 5 ? 1 : 0, 'created_at' => '2026-11-15 09:00:00', 'updated_at' => '2026-11-15 09:00:00' ); }
foreach ( array( 'sql', 'null', 'NaN', '-1', '1.5', '999999999999999999999999' ) as $failure ) {
    reset_flow(); $wpdb->count_failure = $failure; $before = $wpdb->watches;
    test( is_wp_error( $resume( new WP_REST_Request( 'law6' ) ) ) && $wpdb->watches === $before, 'resume refuses invalid/failed count: ' . $failure );
    test( is_wp_error( $create( new WP_REST_Request( 'new' ) ) ) && $wpdb->watches === $before, 'register refuses invalid/failed count: ' . $failure );
}
reset_flow(); test( is_wp_error( $resume( new WP_REST_Request( 'law6' ) ) ) && $wpdb->watches[6]['is_active'] === 0, 'cannot resume above limit' );
test( is_wp_error( $create( new WP_REST_Request( 'new' ) ) ) && count( $wpdb->watches ) === 6, 'cannot insert above limit' );
$wpdb->watches[6]['is_active'] = 1; test( egov_law_monitor_enforce_watch_limit( 10 ) === true && $wpdb->watches[6]['is_active'] === 0, 'normal auto-adjustment still works' );
reset_flow(); seed_process(); $wpdb->watches[6]['is_active'] = 1; test( is_wp_error( egov_law_monitor_enforce_watch_limit( 10 ) ) && $wpdb->watches[6]['is_active'] === 1, 'auto-adjustment refused after withdrawal' );
reset_flow(); $delete = $routes['/watches/(?P<keyword>[^/]+)']['callback']; test( is_array( $delete( new WP_REST_Request( '民法' ) ) ) && count( $wpdb->watches ) === 5, 'normal delete' );
// Explicitly permitted residual race: a save already past its check can finish.
reset_flow(); $result = egov_law_monitor_withdrawal_checked_save( 10, static function () { seed_process(); return true; } );
test( $result === true && is_wp_error( egov_law_monitor_withdrawal_check_save( 10 ) ), 'in-flight race accepted but subsequent saves denied' );
$admin = true; test( egov_law_monitor_withdrawal_check_save( 10, null, 'make_free' ) === true && egov_law_monitor_withdrawal_check_save( 10, null, 'cancellation_admin' ) === true, 'necessary manual admin operations remain available' );
$admin = false; test( is_wp_error( egov_law_monitor_withdrawal_check_save( 10, null, 'make_free' ) ), 'nonadmin cannot use operation exception' );
$admin = true; seed_process( 'completed' ); test( is_wp_error( egov_law_monitor_withdrawal_check_save( 10, null, 'make_free' ) ), 'completed process refuses even operation exception' );
$actor = 99; reset_flow(); test( egov_law_monitor_withdrawal_target( $person ), 'Subscriber eligible' ); $filter = $filters['map_meta_cap'];
foreach ( array( 'upload_files', 'edit_pages', 'create_users', 'manage_options', 'law_watch_internal_api' ) as $cap ) { $person->caps = array( $cap ); test( ! egov_law_monitor_withdrawal_target( $person ) && $filter( array( 'delete_users' ), 'delete_user', 99, array( 20 ) ) === array( 'delete_users' ), 'shared protected classification: ' . $cap ); }
$person->caps = array(); test( $filter( array( 'delete_users' ), 'delete_user', 99, array( 20 ) ) === array( 'do_not_allow' ), 'ordinary capability-checked deletion remains refused' );
test( ! $withdrawal_records && ! isset( $GLOBALS['egov_withdrawal_writers'] ), 'normal operations never create process records or write reservations' );
foreach ( $withdrawal_read_events as $event ) { test( str_starts_with( $event[0], 'SELECT' ) || str_starts_with( $event[0], 'SHOW' ), 'state verification performs reads only' ); }
echo "$checks best-effort withdrawal checks passed; no live DB, Square or deletion.\n";
