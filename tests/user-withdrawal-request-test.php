<?php
/** Actual receipt, policy, state guard, UI and POST functions. WP/DB boundaries are offline. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
$options = array(); $logged = true; $uid = 10; $admin = false; $plan = 'free'; $roles = array( 'subscriber' );
$caps = array(); $login = 'member'; $multisite = false; $add_calls = 0; $mode = ''; $read_fail = false; $user_exists = true; $meta = array();
class WP_Error { function __construct( public $code, public $message ) {} function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_current_user_id() { return $GLOBALS['uid']; }
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can( $cap ) { return $GLOBALS['admin']; }
function is_multisite() { return $GLOBALS['multisite']; }
function user_can( $user, $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_userdata( $id ) { return $GLOBALS['user_exists'] && $id === 10 ? (object) array( 'ID' => 10, 'roles' => $GLOBALS['roles'], 'user_login' => $GLOBALS['login'] ) : false; }
function egov_law_monitor_get_plan( $id ) { return $GLOBALS['plan']; }
function get_option( $key, $default = false ) { if ( $GLOBALS['read_fail'] && str_starts_with( $key, 'egov_law_monitor_withdrawal_request_' ) ) { $GLOBALS['wpdb']->last_error = 'private read error'; return $default; } return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, $unused, $autoload ) {
    ++$GLOBALS['add_calls']; check_request( $autoload === false && $unused === '', 'receipt not autoloaded' );
    if ( $GLOBALS['mode'] === 'save_fail' ) { return false; }
    if ( $GLOBALS['mode'] === 'concurrent' ) { $GLOBALS['options'][$key] = array( 'user_id' => 10, 'requested_at' => '2026-11-01T00:00:00Z', 'status' => 'received' ); return false; }
    if ( isset( $GLOBALS['options'][$key] ) ) { return false; }
    $GLOBALS['options'][$key] = $value;
    if ( $GLOBALS['mode'] === 'after_read_fail' ) { $GLOBALS['read_fail'] = true; }
    return true;
}
function update_user_meta( $id, $key, $value ) { $GLOBALS['meta'][$key] = $value; return 1; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
function wp_nonce_field( $action, $name ) { echo '<input name="' . $name . '" value="valid:' . $action . '">'; }
function wp_unslash( $v ) { return $v; }
function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; }
function add_shortcode( ...$args ) {}
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_parse_url( $v ) { return parse_url( $v ); }
function home_url( $path ) { return 'https://example.test' . $path; }
function nocache_headers() {}
function wp_timezone() { return new DateTimeZone( 'Asia/Tokyo' ); }
function request_url_query( $url, $set = array(), $remove = array() ) {
    $p = parse_url( $url ); parse_str( $p['query'] ?? '', $q ); foreach ( $remove as $key ) { unset( $q[$key] ); }
    $q = array_merge( $q, $set ); return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( $p['path'] ?? '/' ) . ( $q ? '?' . http_build_query( $q ) : '' ) . ( isset( $p['fragment'] ) ? '#' . $p['fragment'] : '' );
}
function remove_query_arg( $keys, $url ) { return request_url_query( $url, array(), $keys ); }
function add_query_arg( $key, $value, $url ) { return request_url_query( $url, array( $key => $value ) ); }
class RequestStore {
    public $prefix = 'wp_', $last_error = '';
    function prepare( $sql, ...$p ) { return array( $sql, $p ); }
    function get_results( $query, $mode ) { list( $sql, $p ) = is_array( $query ) ? $query : array( $query, array() ); $this->last_error = ''; return ( new WithdrawalReadStore() )->rows( $sql, $p ); }
}
require __DIR__ . '/withdrawal-test-bootstrap.php';
require __DIR__ . '/../wordpress-plugin/includes/user-withdrawal-request.php';
require __DIR__ . '/../wordpress-plugin/includes/standard-plan-page.php';
$checks = 0;
function check_request( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
function request_fixture() {
    $GLOBALS['options'] = array(); $GLOBALS['uid'] = 10; $GLOBALS['logged'] = true; $GLOBALS['admin'] = false; $GLOBALS['plan'] = 'free';
    $GLOBALS['roles'] = array( 'subscriber' ); $GLOBALS['caps'] = array(); $GLOBALS['login'] = 'member'; $GLOBALS['multisite'] = false; $GLOBALS['user_exists'] = true;
    $GLOBALS['add_calls'] = 0; $GLOBALS['mode'] = ''; $GLOBALS['read_fail'] = false; $GLOBALS['wpdb'] = new RequestStore(); $GLOBALS['meta'] = array();
    reset_withdrawal_reads(); unset( $GLOBALS['egov_withdrawal_request_result'] );
    $_GET = array(); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/account/?tab=plan';
    return array( 'withdrawal_request_nonce' => 'valid:egov_withdrawal_request_10', 'withdrawal_request_user_id' => '10', 'confirm_withdrawal_request' => '1' );
}
function check_withdrawal_plan_urls( $html, $label ) {
    preg_match_all( '/(?:href|action)="([^"]+)"/', $html, $matches );
    check_request( count( $matches[1] ) > 0, 'navigation is present: ' . $label );
    foreach ( $matches[1] as $encoded ) {
        $url = html_entity_decode( $encoded, ENT_QUOTES, 'UTF-8' ); $parts = parse_url( $url );
        check_request( ( $parts['fragment'] ?? '' ) === 'plan-status' && substr_count( $url, '#' ) === 1, 'plan tab fragment is last and unique: ' . $label );
    }
}
// Standard uses the real cancellation path in withdrawal-cancellation-integration-test.php.
foreach ( array( 'free' ) as $test_plan ) {
    $fields = request_fixture(); $plan = $test_plan;
    $html = egov_law_monitor_plan_status_shortcode();
    check_request( str_contains( $html, '退会を希望される方は、以下のボタンからお申し込みください。お申し込み後、管理者が退会手続きを行います。' ), 'Free guidance matches requested text' );
    check_request( str_contains( $html, '退会を申請する' ) && ! str_contains( $html, '確定して退会' ) && $add_calls === 0, 'plan tab button for ' . $plan );
    check_withdrawal_plan_urls( egov_law_monitor_withdrawal_request_panel( 10 ), 'Free confirmation link' );
    $_GET['egov_withdrawal_confirm'] = '1'; $html = egov_law_monitor_plan_status_shortcode();
    check_request( str_contains( $html, '退会申請の確認' ) && str_contains( $html, '申請後、管理者が退会手続きを行います。' ) && str_contains( $html, '退会完了後はアカウントが削除され、法令ウォッチやAI要約の個人履歴が利用できなくなります。' ) && ! str_contains( $html, 'Square' ) && str_contains( $html, 'withdrawal_request_nonce' ) && str_contains( $html, '確定して退会を申請する' ) && ! str_contains( $html, ' checked' ) && $add_calls === 0, 'Free confirmation explains consequences without Standard-only conditions or saves' );
    check_withdrawal_plan_urls( egov_law_monitor_withdrawal_request_panel( 10 ), 'Free form and back link' );
    $before = $withdrawal_records; $saved = egov_law_monitor_submit_withdrawal_request( $fields );
    check_request( ! is_wp_error( $saved ) && $saved['status'] === 'received' && $saved['user_id'] === 10 && preg_match( '/Z\z/', $saved['requested_at'] ) && $withdrawal_records === $before, 'only receipt option persisted, not withdrawal phase' );
    check_request( egov_law_monitor_withdrawal_check_save( 10 ) === true && egov_law_monitor_withdrawal_checked_save( 10, fn() => update_user_meta( 10, 'normal-setting', 'kept' ) ) === 1, 'receipt does not stop normal save' );
    check_request( egov_law_monitor_submit_withdrawal_request( $fields ) === $saved && $add_calls === 1, 'duplicate preserves original timestamp without another insert' );
    $html = egov_law_monitor_plan_status_shortcode();
    check_request( str_contains( $html, '退会申請を受け付けました' ) && ! str_contains( $html, '確定して退会を申請する' ) && ! str_contains( $html, '>退会を申請する</a>' ), 'receipt replaces button and confirmation' );
    $admin = true; ob_start(); egov_law_monitor_render_withdrawal_request_receipt( 10 ); $html = ob_get_clean();
    check_request( str_contains( $html, '受付済み' ) && str_contains( $html, '管理者が明示的に' ) && ! str_contains( $html, 'checked' ), 'admin sees receipt; confirmation checkbox not auto-checked' );
    $user_exists = false; ob_start(); egov_law_monitor_render_withdrawal_request_receipt( 10 ); $html = ob_get_clean();
    check_request( str_contains( $html, '受付済み' ) && get_option( egov_law_monitor_withdrawal_request_key( 10 ) ) === $saved, 'receipt remains readable after user removal; no automatic option deletion' );
}
foreach ( array( 'logout', 'nonce', 'confirm', 'other_id', 'array_id', 'role', 'multiple_roles', 'protected', 'service', 'multisite', 'missing_user', 'unknown_plan', 'read', 'corrupt', 'state_read', 'started', 'save_fail' ) as $fault ) {
    $fields = request_fixture();
    if ( $fault === 'logout' ) { $logged = false; }
    if ( $fault === 'nonce' ) { $fields['withdrawal_request_nonce'] = 'bad'; }
    if ( $fault === 'confirm' ) { unset( $fields['confirm_withdrawal_request'] ); }
    if ( $fault === 'other_id' ) { $fields['withdrawal_request_user_id'] = '20'; }
    if ( $fault === 'array_id' ) { $fields['withdrawal_request_user_id'] = array( '10' ); }
    if ( $fault === 'role' ) { $roles = array( 'administrator' ); }
    if ( $fault === 'multiple_roles' ) { $roles[] = 'editor'; }
    if ( $fault === 'protected' ) { $options['egov_law_monitor_withdrawal_protected_user_ids'] = array( 10 ); }
    if ( $fault === 'service' ) { $login = 'law_watch_api'; }
    if ( $fault === 'multisite' ) { $multisite = true; }
    if ( $fault === 'missing_user' ) { $user_exists = false; }
    if ( $fault === 'unknown_plan' ) { $plan = 'unknown'; }
    if ( $fault === 'read' ) { $read_fail = true; }
    if ( $fault === 'corrupt' ) { $options[egov_law_monitor_withdrawal_request_key( 10 )] = array( 'status' => 'received' ); }
    if ( $fault === 'state_read' ) { $withdrawal_read_failure = 'read'; }
    if ( $fault === 'started' ) { $withdrawal_records[10] = array( 'process_uuid' => '10000000-0000-4000-8000-000000000000', 'user_id' => 10, 'phase' => 'started', 'revision' => 1 ); }
    if ( $fault === 'save_fail' ) { $mode = 'save_fail'; }
    $before = $options; $states = $withdrawal_records;
    check_request( is_wp_error( egov_law_monitor_submit_withdrawal_request( $fields ) ) && $options === $before && $withdrawal_records === $states, 'invalid request cannot persist: ' . $fault );
}
$fields = request_fixture(); $mode = 'concurrent'; $saved = egov_law_monitor_submit_withdrawal_request( $fields );
check_request( ! is_wp_error( $saved ) && $saved['requested_at'] === '2026-11-01T00:00:00Z' && count( $options ) === 1, 'concurrent add failure resolves to valid first receipt, without overwrite or retry' );
$fields = request_fixture(); $mode = 'after_read_fail';
check_request( is_wp_error( egov_law_monitor_submit_withdrawal_request( $fields ) ) && $add_calls === 1, 'read failure after save is not reported as accepted' );
$fields = request_fixture(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/subdir/account/?tab=plan&egov_withdrawal_confirm=1'; $_POST = $fields + array( 'egov_withdrawal_request' => '1', 'redirect_to' => 'https://attacker.test/' );
$redirect = egov_law_monitor_withdrawal_request_post( fn( $url, $code ) => array( $url, $code ) );
check_request( $redirect === array( 'https://example.test/subdir/account/?tab=plan#plan-status', 303 ) && $add_calls === 1, 'success 303 explicitly selects plan tab, preserves query/subdirectory and ignores posted URL' );
egov_law_monitor_withdrawal_request_post( fn() => throw new LogicException( 'must not repeat redirect' ) );
check_request( $add_calls === 1, 'same request processed once' );
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); unset( $GLOBALS['egov_withdrawal_request_result'] );
check_request( str_contains( egov_law_monitor_withdrawal_request_panel( 10 ), '退会申請を受け付けました' ) && $add_calls === 1, 'redirected GET receipt comes from storage, never replays POST' );
$fields = request_fixture(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $fields + array( 'egov_withdrawal_request' => '1' ); $mode = 'save_fail';
check_request( is_wp_error( egov_law_monitor_withdrawal_request_post( fn() => throw new LogicException( 'failure redirected' ) ) ) && str_contains( egov_law_monitor_withdrawal_request_panel( 10 ), '保存を確認できません' ), 'failure stays on confirmation with error, not success' );
check_withdrawal_plan_urls( egov_law_monitor_withdrawal_request_panel( 10 ), 'failed submission form and back link' );
foreach ( array( 'free', 'standard' ) as $test_plan ) {
    foreach ( array( '', '#plan-status', '#law-watch', '#plan-status#plan-status' ) as $fragment ) {
        request_fixture(); $plan = $test_plan;
        $_SERVER['REQUEST_URI'] = '/subdir/account/?tab=plan&keep=a%26b&egov_withdrawal_confirm=1&_wpnonce=discard' . $fragment;
        $url = egov_law_monitor_withdrawal_request_url(); $p = parse_url( $url ); parse_str( $p['query'], $query );
        check_request( $url === 'https://example.test/subdir/account/?tab=plan&keep=a%26b#plan-status' && $query === array( 'tab' => 'plan', 'keep' => 'a&b' ) && $p['fragment'] === 'plan-status', 'query preserved and fragment explicitly selected for ' . $test_plan . ' from ' . $fragment );
    }
}
foreach ( array( '//attacker.test/', '/bad path', '/bad\\path', '' ) as $uri ) { request_fixture(); $_SERVER['REQUEST_URI'] = $uri; check_request( egov_law_monitor_withdrawal_request_url() === '', 'unsafe current URI rejected' ); }
request_fixture(); $admin = false; ob_start(); egov_law_monitor_render_withdrawal_request_receipt( 10 ); $html = ob_get_clean(); check_request( $html === '', 'receipt admin display protected' );
$admin = true; ob_start(); egov_law_monitor_render_withdrawal_request_receipt( 10 ); $html = ob_get_clean(); check_request( str_contains( $html, '記録なし' ), 'missing receipt keeps contact-based manual intake available' );
$options[egov_law_monitor_withdrawal_request_key( 10 )] = array( 'user_id' => 10, 'requested_at' => '2026-02-30T00:00:00Z', 'status' => 'received' );
check_request( is_wp_error( egov_law_monitor_get_withdrawal_request( 10 ) ), 'nonexistent receipt timestamp rejected' );
$source = file_get_contents( __DIR__ . '/../wordpress-plugin/includes/admin/admin-user-withdrawal-check.php' );
check_request( substr_count( $source, 'egov_law_monitor_render_withdrawal_request_receipt(' ) === 2, 'receipt integrated before and after WP deletion' );
check_request( str_contains( file_get_contents( __DIR__ . '/../wordpress-plugin/egov-law-monitor.php' ), "includes/user-withdrawal-request.php" ), 'plugin bootstrap loads module' );
echo "$checks withdrawal request checks passed; Options/WP/DB boundaries simulated, no live DB/Square/mail.\n";
