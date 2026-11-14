<?php
/** Offline settings/API/internal-export regressions; no WordPress DB or HTTP. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public function __construct( public $code, public $message, public $data = array() ) {} }
class WP_REST_Server { const CREATABLE = 'POST'; const READABLE = 'GET'; const DELETABLE = 'DELETE'; }
class Request {
    public function __construct( public $params = array(), public $nonce = 'valid' ) {}
    public function get_param( $key ) { return $this->params[$key] ?? null; }
    public function has_param( $key ) { return array_key_exists( $key, $this->params ); }
    public function get_header( $key ) { return $this->nonce; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function is_user_logged_in() { return $GLOBALS['logged']; }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid' && $action === 'wp_rest'; }
function current_user_can( $cap ) { return $cap === 'law_watch_internal_api'; }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_user_meta( $id, $key, $value ) {
    if ( $key !== EGOV_LAW_MONITOR_META_NOTIFICATIONS || ! in_array( $value, array( '1', '0' ), true ) ) { throw new LogicException( 'unexpected write' ); }
    ++$GLOBALS['writes'];
    if ( $GLOBALS['save_fail'] || get_user_meta( $id, $key, true ) === $value ) { return false; }
    $GLOBALS['meta'][$id][$key] = $value; return 1;
}
function add_action( $name, $callback ) { $GLOBALS['hooks'][$name][] = $callback; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][$route] = $args; }
class Store {
    public $prefix = 'wp_'; public $users = 'wp_users';
    public $watches = array( array( 'user_id' => 10, 'user_email' => 'user@example.test', 'keyword' => '民法', 'is_active' => 1 ), array( 'user_id' => 10, 'user_email' => 'user@example.test', 'keyword' => '商法', 'is_active' => 0 ) );
    public function get_results( $sql, $mode ) {
        if ( ! str_contains( $sql, 'WHERE w.is_active = 1' ) ) { throw new LogicException( 'unexpected query' ); }
        return array_values( array_filter( $this->watches, static fn( $row ) => $row['is_active'] === 1 ) );
    }
    public function __call( $name, $args ) { throw new LogicException( 'watch mutation forbidden' ); }
}
require __DIR__ . '/../wordpress-plugin/includes/user-settings.php';
require __DIR__ . '/../wordpress-plugin/includes/watch-api.php';
require __DIR__ . '/../wordpress-plugin/includes/internal-api.php';
$checks = 0; $user = 10; $logged = true; $meta = array(); $save_fail = false; $writes = 0; $wpdb = new Store();
function check_notify( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
foreach ( $hooks['rest_api_init'] as $callback ) { $callback(); }
check_notify( $routes['/watch-notifications']['callback'] === 'egov_law_monitor_save_watch_notifications' && $routes['/watch-notifications']['permission_callback'] === 'egov_law_monitor_watch_notifications_permission', 'protected POST route registered' );
check_notify( egov_law_monitor_get_notifications( 10 ) === true && $writes === 0, 'unset defaults ON without writes' );
$meta[20][EGOV_LAW_MONITOR_META_NOTIFICATIONS] = '0';
check_notify( egov_law_monitor_get_notifications( 20 ) === false, 'existing OFF preserved' );
$original = $wpdb->watches;
foreach ( array( 'free', 'standard' ) as $plan ) {
    $meta[10][EGOV_LAW_MONITOR_META_PLAN] = $plan;
    foreach ( array( false, true ) as $enabled ) {
        $result = egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => $enabled ) ) );
        check_notify( $result === array( 'notifications' => $enabled ) && $meta[10][EGOV_LAW_MONITOR_META_NOTIFICATIONS] === ( $enabled ? '1' : '0' ), 'save bool as explicit string: ' . $plan );
        check_notify( $wpdb->watches === $original && egov_law_monitor_get_plan( 10 ) === $plan && egov_law_monitor_get_notifications( 20 ) === false, 'watches plan and other user untouched' );
        $export = egov_law_monitor_get_all_watch_settings()['users'][0];
        check_notify( $export['notifications'] === $enabled && count( $export['watches'] ) === 1 && $export['watches'][0]['keyword'] === '民法', 'internal API exports preference and active watches only' );
    }
}
check_notify( ! is_wp_error( egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => true ) ) ) ), 'identical value succeeds' );
$before = $writes;
foreach ( array( null, '0', '1', 0, 1, 'false', array(), new stdClass() ) as $bad ) {
    check_notify( is_wp_error( egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => $bad ) ) ) ), 'reject non-boolean' );
}
check_notify( is_wp_error( egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => false, 'user_id' => 20 ) ) ) ), 'reject supplied target user' );
check_notify( is_wp_error( egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => false ), 'bad' ) ) ), 'invalid nonce rejected' );
$logged = false;
check_notify( is_wp_error( egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => false ) ) ) ), 'anonymous rejected' );
$logged = true;
check_notify( $before === $writes, 'invalid requests never save' );
$save_fail = true;
$result = egov_law_monitor_save_watch_notifications( new Request( array( 'notifications' => false ) ) );
check_notify( is_wp_error( $result ) && $result->data['status'] === 500 && egov_law_monitor_get_notifications( 10 ) === true, 'DB failure returns error and retains state' );
check_notify( $wpdb->watches === $original, 'no individual status changed including stopped watch' );
echo "{$checks} notification preference checks passed; no real DB or HTTP.\n";
