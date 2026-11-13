<?php
/** php tests/watch-status-test.php — no WordPress/DB/network writes. */
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { public function __construct( public $code, public $message, public $data ) {} }
class WP_REST_Server { const CREATABLE = 'POST'; const READABLE = 'GET'; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $cap ) { return $GLOBALS['cap'] && $cap === 'law_watch_internal_api'; }
function add_action( $hook, $cb ) { $cb(); }
function register_rest_route( $namespace, $path, $args ) { $GLOBALS['routes'][$path] = $args; }
function add_option( $key, $value, $deprecated, $autoload ) {
    $db = $GLOBALS['wpdb'];
    if ( $db->insert_race ) { $db->value = $db->insert_race; $db->insert_race = null; return false; }
    if ( $db->fail || $db->value !== null ) { return false; }
    $db->value = $value; $db->autoload = $autoload; return true;
}
function get_option( $key, $default = false ) { return $GLOBALS['wpdb']->value ?? $default; }
function wp_cache_delete( $key, $group ) { $GLOBALS['evicted'][] = array( $key, $group ); }
function wp_timezone() { return new DateTimeZone( $GLOBALS['timezone'] ); }
function wp_date( $format, $timestamp, $timezone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); }
class OptionStore {
    public $options = 'wp_options', $last_error = '', $value = null, $fail = false, $race = null, $insert_race = null, $autoload = false, $read_fail = false;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_row( $query, $mode ) { $this->last_error = $this->read_fail ? 'private error' : ''; return $this->value === null ? null : array( 'option_value' => $this->value ); }
    public function query( $query ) {
        if ( $this->fail ) { return false; }
        if ( $this->race ) { $this->value = $this->race; $this->race = null; return 0; }
        if ( $this->value !== $query[1][3] ) { return 0; }
        $this->value = $query[1][0]; $this->autoload = $query[1][1]; return 1;
    }
}
class StatusRequest { public function __construct( private $value ) {} public function get_param( $key ) { return $this->value; } }
function send_status( $value ) { return egov_law_monitor_save_checked_at( new StatusRequest( $value ) ); }
function reset_status() { $GLOBALS['wpdb'] = new OptionStore(); $GLOBALS['evicted'] = array(); $GLOBALS['logged_in'] = true; $GLOBALS['cap'] = true; $GLOBALS['timezone'] = 'Asia/Tokyo'; }
$checks = 0;
function status_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
reset_status(); require __DIR__ . '/../wordpress-plugin/includes/internal-api.php';
status_check( $routes['/internal/watch-status']['permission_callback'] === 'egov_law_monitor_internal_api_permission' && $routes['/internal/watch-status']['methods'] === 'POST' && $routes['/internal/watch-settings']['methods'] === 'GET', 'new protected POST and unchanged GET' );
$logged_in = false; status_check( ! egov_law_monitor_internal_api_permission(), 'anonymous refused' );
$logged_in = true; $cap = false; status_check( ! egov_law_monitor_internal_api_permission(), 'missing internal capability refused' ); $cap = true;
status_check( egov_law_monitor_internal_api_permission(), 'internal API account allowed' );
foreach ( array( null, array(), '', '2026-01-01', '2026-01-01T00:00:00', '2026-02-30T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:00+14:30', '2026-01-01T00:00:60Z', '<script>', '9999-01-01T00:00:00Z' ) as $bad ) { status_check( send_status( $bad ) instanceof WP_Error && $wpdb->value === null, 'invalid timestamp cannot write' ); }
$r = send_status( '2025-01-01T09:00:00.123456+09:00' );
status_check( $r['last_checked'] === '2025-01-01T00:00:00.123456Z' && $wpdb->autoload === false, 'UTC canonical insert, no autoload' );
status_check( send_status( '2025-01-01T00:00:00.123456Z' ) === $r, 'identical retry succeeds' );
status_check( send_status( '2024-01-01T00:00:00Z' ) === $r && $wpdb->value === $r['last_checked'], 'old observation ignored' );
send_status( '2025-01-02T00:00:00Z' ); status_check( $wpdb->value === '2025-01-02T00:00:00.000000Z' && $wpdb->autoload === 'off' && count( $evicted ) === 2, 'CAS update and option cache eviction' );
$wpdb->race = '2025-01-04T00:00:00.000000Z'; $r = send_status( '2025-01-03T00:00:00Z' ); status_check( $r['last_checked'] === $wpdb->value && str_starts_with( $wpdb->value, '2025-01-04' ), 'newer concurrent update wins' );
$wpdb->race = '2025-01-05T00:00:00.000000Z'; send_status( '2025-01-06T00:00:00Z' ); status_check( str_starts_with( $wpdb->value, '2025-01-06' ), 'CAS loser re-reads and advances' );
$wpdb->fail = true; status_check( send_status( '2025-01-07T00:00:00Z' ) instanceof WP_Error, 'update failure not success' );
reset_status(); $wpdb->fail = true; status_check( send_status( '2025-01-01T00:00:00Z' ) instanceof WP_Error, 'insert failure not success' );
reset_status(); $wpdb->insert_race = '2025-01-03T00:00:00.000000Z'; status_check( send_status( '2025-01-02T00:00:00Z' )['last_checked'] === $wpdb->value, 'concurrent first insert cannot roll back' );
reset_status(); $wpdb->read_fail = true; status_check( send_status( '2025-01-01T00:00:00Z' ) instanceof WP_Error, 'read failure refused' );
reset_status(); status_check( egov_law_monitor_last_checked_label() === '未確認', 'unregistered label' );
$wpdb->value = '<script>'; status_check( egov_law_monitor_last_checked_label() === '確認できません', 'corrupt stored value label' );
$wpdb->value = '2025-01-01T18:05:00.000000Z'; status_check( egov_law_monitor_last_checked_label() === '2025/1/2 03:05', 'WordPress Tokyo date boundary' );
$timezone = 'America/Los_Angeles'; status_check( egov_law_monitor_last_checked_label() === '2025/1/1 10:05', 'WordPress timezone respected' );
$js = file_get_contents( __DIR__ . '/../wordpress-plugin/assets/js/law-watch.js' );
status_check( ! str_contains( $js, 'watch_status.json' ) && ! str_contains( $js, 'dataUrl' ) && str_contains( $js, 'egov-law-watch-form' ) && str_contains( $js, 'egov-law-resume-button' ), 'Pages fetch removed, watch controls retained' );
echo "$checks watch status checks passed; no real DB/API used.\n";
