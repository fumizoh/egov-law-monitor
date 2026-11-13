<?php
/** php tests/ai-summary-month-test.php — real aggregation/display, mocked DB only. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
function current_datetime() { return ( new DateTimeImmutable( $GLOBALS['clock'], new DateTimeZone( $GLOBALS['zone'] ) ) )->setTimezone( new DateTimeZone( $GLOBALS['zone'] ) ); }
function current_time( $format ) { if ( $format === 'timestamp' ) { throw new LogicException( 'Offset timestamp forbidden' ); } return current_datetime()->format( $format === 'mysql' ? 'Y-m-d H:i:s' : $format ); }
function get_user_meta( $id, $key, $single ) { return $key === 'egov_law_monitor_plan' ? $GLOBALS['plan'] : $GLOBALS['trial']; }
function update_user_meta( $id, $key, $value ) { if ( $key !== 'egov_law_monitor_ai_summary_trial_usage' ) { throw new LogicException(); } $GLOBALS['trial'] = $value; return true; }
function add_action( ...$args ) {} function register_activation_hook( ...$args ) {} function add_shortcode( ...$args ) {}
function is_user_logged_in() { return true; } function get_current_user_id() { return 10; }
function get_pages( $args ) { return array(); } function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
class MonthStore {
    public $prefix = 'test_', $rows = array(), $query, $saved;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_var( $query ) {
        $this->query = $query;
        return count( array_filter( $this->rows, static fn( $r ) => $r['user_id'] === $query[1][0] && ( count( $query[1] ) === 1 || ( $r['used_at'] >= $query[1][1] && $r['used_at'] < $query[1][2] ) ) ) );
    }
    public function get_results( $query, $format ) { return array(); }
    public function replace( $table, $data, $formats ) { $this->saved = $data; return 1; }
}
require __DIR__ . '/../wordpress-plugin/includes/database.php';
require __DIR__ . '/../wordpress-plugin/includes/user-settings.php';
require __DIR__ . '/../wordpress-plugin/includes/ai-summary-management.php';
$wpdb = new MonthStore(); $plan = 'standard'; $trial = 2; $checks = 0;
function month_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
foreach ( array( 'Asia/Tokyo', 'UTC', 'America/Los_Angeles', 'Europe/Berlin' ) as $zone ) {
    foreach ( array( '2026-01-31' => array( '2026-01-01', '2026-02-01' ), '2026-02-01' => array( '2026-02-01', '2026-03-01' ), '2026-02-28' => array( '2026-02-01', '2026-03-01' ), '2026-03-01' => array( '2026-03-01', '2026-04-01' ), '2026-11-30' => array( '2026-11-01', '2026-12-01' ), '2026-12-01' => array( '2026-12-01', '2027-01-01' ), '2026-12-31' => array( '2026-12-01', '2027-01-01' ), '2027-01-01' => array( '2027-01-01', '2027-02-01' ), '2028-02-29' => array( '2028-02-01', '2028-03-01' ) ) as $day => $bounds ) {
        foreach ( array( '00:00:00', '23:59:59' ) as $hour ) {
            $clock = $day . 'T' . $hour; $wpdb->rows = array();
            egov_law_monitor_get_ai_summary_usage_count( 10 );
            month_check( $wpdb->query[1] === array( 10, $bounds[0] . ' 00:00:00', $bounds[1] . ' 00:00:00' ), 'calendar range ' . $zone . '/' . $clock );
        }
    }
}
$zone = 'Asia/Tokyo';
foreach ( array( '2026-11-30T14:59:59Z' => '2026-11-01', '2026-11-30T15:00:00Z' => '2026-12-01' ) as $clock => $start ) {
    egov_law_monitor_get_ai_summary_usage_count( 10 ); month_check( $wpdb->query[1][1] === $start . ' 00:00:00', 'Tokyo month changes exactly at local midnight' );
}
$clock = '2026-03-31T23:59:59+09:00';
$wpdb->rows = array( array( 'user_id' => 10, 'used_at' => '2026-02-28 23:59:59' ), array( 'user_id' => 10, 'used_at' => '2026-03-01 00:00:00' ), array( 'user_id' => 10, 'used_at' => '2026-03-31 23:59:59' ), array( 'user_id' => 10, 'used_at' => '2026-04-01 00:00:00' ), array( 'user_id' => 20, 'used_at' => '2026-03-10 12:00:00' ) );
$before = $wpdb->rows;
month_check( egov_law_monitor_get_ai_summary_usage_count( 10 ) === 2 && str_contains( $wpdb->query[0], 'used_at >= %s' ) && str_contains( $wpdb->query[0], 'used_at < %s' ), 'user scoped inclusive start and exclusive end retained' );
$clock = '2026-04-01T00:00:00+09:00'; month_check( egov_law_monitor_get_ai_summary_usage_count( 10 ) === 1 && $wpdb->rows === $before, 'month switch without clearing history' );
foreach ( array( 37 => 63, 100 => 0, 101 => 0 ) as $usage => $remaining ) {
    $wpdb->rows = array_fill( 0, $usage, array( 'user_id' => 10, 'used_at' => '2026-04-01 00:00:00' ) );
    $html = egov_law_monitor_ai_summary_management_shortcode();
    month_check( egov_law_monitor_get_ai_summary_limit( 10 ) === 100 && preg_match( '/残り\s+' . $remaining . '\s*\/\s*100 回/u', $html ), 'Standard remaining display and 100 limit: ' . $usage );
}
$plan = 'free'; $trial = 2; $html = egov_law_monitor_ai_summary_management_shortcode();
month_check( egov_law_monitor_get_ai_summary_limit( 10 ) === 3 && preg_match( '/残り\s+1\s*\/\s*3 回/u', $html ), 'Free uses lifetime count instead of monthly history' );
egov_law_monitor_increment_ai_summary_trial_usage_count( 10 ); $clock = '2027-01-01T00:00:00+09:00';
month_check( egov_law_monitor_get_ai_summary_trial_usage_count( 10 ) === 3 && preg_match( '/残り\s+0\s*\/\s*3 回/u', egov_law_monitor_ai_summary_management_shortcode() ), 'Free lifetime usage does not reset with month' );
egov_law_monitor_save_ai_summary_history( 10, 'law', '法令', '2026-01-01', '1', '1', 'hash', 'title', 'body' );
month_check( $wpdb->saved['used_at'] === current_time( 'mysql' ), 'existing local used_at storage unchanged' );
echo "$checks AI monthly checks passed; no real DB/API used.\n";
