<?php
/** Offline: real discovery, normalization, matcher, HTTP adapter and route callbacks. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public function __construct( public $code, public $message, public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
class DiscoveryRequest {
    public function __construct( public $params = array(), public $nonce = 'valid' ) {}
    public function get_param( $key ) { return $this->params[$key] ?? null; }
    public function get_header( $key ) { return $this->nonce; }
    public function get_route() { return '/egov-law-monitor/v1/ondemand/laws'; }
}
class DiscoveryResponse { public $headers = array(); public function header( $key, $value ) { $this->headers[$key] = $value; } }
$GLOBALS['hooks'] = array(); $GLOBALS['filters'] = array(); $GLOBALS['options'] = array(); $GLOBALS['cache'] = array(); $GLOBALS['calls'] = array(); $GLOBALS['admin'] = true; $GLOBALS['logged'] = true; $GLOBALS['responses'] = array(); $GLOBALS['routes'] = array(); $GLOBALS['page'] = null; $GLOBALS['page_slug'] = false;
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can( $cap ) { return $cap === 'manage_options' && $GLOBALS['admin']; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function get_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { check( str_starts_with( $key, 'egov_ondemand_v1_' ) && $ttl === 300, 'isolated short cache' ); $GLOBALS['cache'][$key] = $value; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_remote_get( $url, $options ) { return transport( 'GET', $url, $options ); }
function wp_remote_post( $url, $options ) { return transport( 'POST', $url, $options ); }
function transport( $method, $url, $options ) {
    check( str_starts_with( $url, 'https://laws.e-gov.go.jp/' ), 'e-Gov only' );
    check( $options['redirection'] === 0 && $options['timeout'] === 15 && $options['limit_response_size'] === 2097153, 'bounded request' );
    $GLOBALS['calls'][] = array( $method, $url, $options );
    if ( ! $GLOBALS['responses'] ) { throw new RuntimeException( 'Unexpected HTTP call' ); }
    return array_shift( $GLOBALS['responses'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function add_action( $name, $callback, ...$rest ) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter( $name, $callback, ...$rest ) { $GLOBALS['filters'][$name][] = $callback; }
function do_action( $name, ...$args ) { $GLOBALS['actions'][] = $name; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function wp_verify_nonce( $v, $action ) { return $v === 'valid' && $action === 'wp_rest'; }
function register_rest_route( $ns, $route, $spec ) { $GLOBALS['routes'][$route] = $spec; }
function rest_ensure_response( $r ) { return $r; }
function register_setting( $group, $key, $spec ) { $GLOBALS['setting'] = array( $group, $key, $spec ); }
function add_settings_error( ...$args ) {}
function settings_errors( ...$args ) {}
function settings_fields( $group ) { echo '<input name="nonce" value="' . $group . '">'; }
function checked( $v, $expected, $echo ) { return $v === $expected ? 'checked' : ''; }
function submit_button( $text ) { echo $text; }
function add_shortcode( $name, $callback ) { $GLOBALS['shortcodes'][$name] = $callback; }
function is_singular() { return $GLOBALS['page'] !== null; }
function get_queried_object() { return $GLOBALS['page']; }
function is_page( $slug ) { return $GLOBALS['page_slug']; }
function has_shortcode( $content, $name ) { return str_contains( $content, '[' . $name . ']' ); }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_url( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function wp_die( $message, $title, $options ) { check( $options['response'] === 403, 'pre-output 403' ); throw new RuntimeException( 'DENIED' ); }
function get_page_by_path( $path ) { return (object) array( 'ID' => 42 ); }
function get_post_status( $page ) { return 'publish'; }
function get_permalink( $page ) { return 'https://example.test/ondemand-summary/'; }
function update_user_meta( ...$args ) { throw new RuntimeException( 'ordinary metadata write forbidden' ); }
function egov_law_monitor_generate_ai_summary( ...$args ) { throw new RuntimeException( 'ordinary AI forbidden' ); }
function egov_law_monitor_record_ai_summary_usage( ...$args ) { throw new RuntimeException( 'ordinary usage forbidden' ); }
function check( $v, $name ) { if ( ! $v ) { throw new RuntimeException( 'FAIL: ' . $name ); } $GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1; }
function response( $v, $code = 200 ) { return array( 'code' => $code, 'body' => json_encode( $v ) ); }
function reset_io() { $GLOBALS['cache'] = array(); $GLOBALS['calls'] = array(); $GLOBALS['responses'] = array(); }
$root = dirname( __DIR__ ) . '/wordpress-plugin/includes/';
foreach ( array( 'ondemand-service.php', 'ondemand-api-client.php', 'ondemand-rest.php', 'ondemand-page.php' ) as $file ) { require $root . $file; }
$law = '322AC0000000049'; $rid = $law . '_20270401_508AC0000000060';
$row = array( 'law_revision_id' => $rid, 'law_title' => '<b>test</b>', 'amendment_law_id' => '508AC0000000060', 'amendment_law_title' => '改正法', 'amendment_law_num' => '令和八年法律第六十号', 'amendment_enforcement_date' => '2027-04-01', 'amendment_scheduled_enforcement_date' => null, 'amendment_enforcement_comment' => null, 'current_revision_status' => 'UnEnforced' );
$history = array( 'law_info' => array( 'law_id' => $law, 'law_num' => '昭和二十二年法律第四十九号' ), 'revisions' => array( $row ) );
$internal = array( 'LawDataId' => 123, 'SubRevision' => '3', 'AmendmentId' => '508AC0000000060', 'EnforcementDate' => '2027-04-01', 'ScheduledEnforcementDate' => null );
check( ! egov_ondemand_enabled(), 'OFF default' );
check( is_wp_error( egov_ondemand_search( 'title', '法', 0 ) ) && ! $GLOBALS['calls'], 'OFF denies before HTTP' );
$GLOBALS['options']['egov_law_monitor_ondemand_enabled'] = '1';
foreach ( array( false, true ) as $logged ) {
    $GLOBALS['logged'] = $logged; $GLOBALS['admin'] = false;
    foreach ( array( 'search', 'revisions', 'match' ) as $op ) { check( is_wp_error( egov_ondemand_dispatch( new DiscoveryRequest(), $op ) ), 'nonadmin every path' ); }
    check( egov_ondemand_link_shortcode() === '', 'nonadmin card absent' );
}
$GLOBALS['logged'] = true; $GLOBALS['admin'] = true;
check( is_wp_error( egov_ondemand_dispatch( new DiscoveryRequest( array(), 'bad' ), 'search' ) ), 'nonce rejects' );
check( egov_ondemand_sanitize_enabled( array( '1' ) ) === '1', 'invalid setting preserves previous' );
check( egov_ondemand_sanitize_enabled( '0' ) === '0' && egov_ondemand_sanitize_enabled( '1' ) === '1', 'valid settings' );
foreach ( array( array( 'title', array(), 0 ), array( 'bogus', 'a', 0 ), array( 'title', ' ', 0 ), array( 'title', 'a', -1 ), array( 'title', str_repeat( 'a', 601 ), 0 ) ) as $args ) { check( is_wp_error( egov_ondemand_search( ...$args ) ), 'bad input' ); }
check( is_wp_error( egov_ondemand_revisions( '../../host' ) ), 'path injection rejected' );
foreach ( array( 'title', 'number' ) as $mode ) {
    reset_io(); $GLOBALS['responses'][] = response( array( 'laws' => array( array( 'law_info' => $history['law_info'], 'revision_info' => array( 'law_title' => '労働基準法' ) ) ), 'total_count' => 21 ) );
    $found = egov_ondemand_search( $mode, '労働', 0 );
    check( $found['items'][0]['id'] === $law && $found['next_offset'] === 1, 'search normalizes and pages' );
    egov_ondemand_search( $mode, '労働', 0 ); check( count( $GLOBALS['calls'] ) === 1, 'search cache' );
    check( str_contains( $GLOBALS['calls'][0][1], $mode === 'title' ? 'law_title=' : 'law_num=' ), 'search mode' );
}
reset_io(); $GLOBALS['responses'][] = response( array( 'laws' => array(), 'total_count' => 0 ) ); check( egov_ondemand_search( 'title', 'なし', 0 )['items'] === array(), 'empty is not error' );
foreach ( array( response( array(), 404 ), new WP_Error( 'timeout', 'private raw error' ), array( 'code' => 200, 'body' => '<html>busy</html>' ), array( 'code' => 200, 'body' => str_repeat( 'x', 2097153 ) ), response( array( 'laws' => 'invalid' ) ) ) as $bad ) {
    reset_io(); $GLOBALS['responses'][] = $bad; $error = egov_ondemand_search( 'title', 'test', 0 );
    check( is_wp_error( $error ) && ! $GLOBALS['cache'], 'HTTP/size/schema failure not empty or cached' );
    check( ! str_contains( $error->message, 'private' ), 'raw details suppressed' );
}
reset_io();
$h = $history; $pseudo = $row; $pseudo['law_revision_id'] .= '_pseudo'; $pseudo['amendment_scheduled_enforcement_date'] = '2027-04-01';
$unknown = $row; $unknown['law_revision_id'] .= '_unknown'; $unknown['amendment_enforcement_date'] = null;
$h['revisions'] = array( $row, $pseudo, $unknown ); $GLOBALS['responses'][] = response( $h );
$revs = egov_ondemand_revisions( $law );
check( count( $revs['items'] ) === 3, 'same date distinct revisions retained' );
check( $revs['items'][1]['enforcement']['kind'] === 'pseudo' && $revs['items'][2]['enforcement']['kind'] === 'unknown', 'pseudo and unknown distinguished' );
check( $revs['items'][0]['revision_status'] === 'UnEnforced', 'unEnforced preserved' );
foreach ( array( array(), array( $internal ), array( $internal, $internal ) ) as $n => $rows ) {
    reset_io(); $GLOBALS['responses'] = array( response( $history ), response( array( 'result' => array( 'success' => true, 'Amendment_History' => $rows ) ) ) );
    $r = egov_ondemand_match( $law, $rid ); check( $r['internal']['status'] === array( 'unmatched', 'matched', 'ambiguous' )[$n], 'match cardinality' );
    check( $r['internal']['meaning'] === 'metadata_only' && $r['internal']['generation_revalidation_required'], 'not proof of text equality' );
    egov_ondemand_match( $law, $rid ); check( count( $GLOBALS['calls'] ) === 2, 'both histories reused' );
}
reset_io(); $GLOBALS['responses'] = array( response( $history ), new WP_Error( 'timeout', 'raw secret' ) );
check( egov_ondemand_match( $law, $rid )['internal']['status'] === 'unavailable', 'internal failure not unmatched' );
reset_io(); $GLOBALS['responses'][] = response( $history ); check( is_wp_error( egov_ondemand_match( $law, 'other_revision' ) ) && count( $GLOBALS['calls'] ) === 1, 'foreign revision refused before internal lookup' );
reset_io(); $h = $history; $h['revisions'][] = $row; $GLOBALS['responses'][] = response( $h ); check( is_wp_error( egov_ondemand_revisions( $law ) ), 'duplicate official IDs refused' );
reset_io(); $h = $history; $h['revisions'][0]['amendment_enforcement_date'] = '2027-02-30'; $GLOBALS['responses'][] = response( $h ); check( is_wp_error( egov_ondemand_revisions( $law ) ), 'invalid real date refused' );
foreach ( $GLOBALS['hooks']['rest_api_init'] as $hook ) { $hook(); }
check( count( $GLOBALS['routes'] ) === 3, 'discovery routes only' );
foreach ( $GLOBALS['routes'] as $route ) { check( $route['permission_callback'] === 'egov_ondemand_permission', 'no public route' ); }
foreach ( $GLOBALS['routes'] as $route ) {
    check( is_wp_error( $route['callback']( new DiscoveryRequest( array(), 'bad' ) ) ), 'each callback rechecks nonce' );
    $GLOBALS['options']['egov_law_monitor_ondemand_enabled'] = '0';
    check( is_wp_error( $route['callback']( new DiscoveryRequest() ) ), 'each callback rechecks switch' );
    $GLOBALS['options']['egov_law_monitor_ondemand_enabled'] = '1';
}
reset_io(); $GLOBALS['responses'][] = response( $history ); egov_ondemand_revisions( $law );
$GLOBALS['admin'] = false; check( is_wp_error( egov_ondemand_revisions( $law ) ) && count( $GLOBALS['calls'] ) === 1, 'cached data denied to nonadmin' );
check( egov_ondemand_sanitize_enabled( '0' ) === '1', 'settings require capability' ); $GLOBALS['admin'] = true;
reset_io(); $h = $history; $h['revisions'] = array(); $GLOBALS['responses'][] = response( $h ); check( egov_ondemand_revisions( $law )['items'] === array(), 'empty history succeeds' );
reset_io(); $h = $history; $h['law_info']['law_id'] = '129AC0000000089'; $GLOBALS['responses'][] = response( $h ); check( is_wp_error( egov_ondemand_revisions( $law ) ) && ! $GLOBALS['cache'], 'wrong law response rejected' );
reset_io(); $h = $history; $h['revisions'][0]['amendment_enforcement_date'] = null; $GLOBALS['responses'][] = response( $h );
check( egov_ondemand_match( $law, $rid )['internal']['status'] === 'unavailable' && count( $GLOBALS['calls'] ) === 1, 'undated revision avoids pointless internal request' );
reset_io(); $bad_internal = $internal; $bad_internal['SubRevision'] = array();
$GLOBALS['responses'] = array( response( $history ), response( array( 'result' => array( 'success' => true, 'Amendment_History' => array( $bad_internal ) ) ) ) );
check( egov_ondemand_match( $law, $rid )['internal']['status'] === 'unavailable' && count( $GLOBALS['cache'] ) === 1, 'malformed internal data not cached' );
reset_io(); $h = $history; $h['revisions'] = array( $pseudo ); $pseudo_internal = $internal; $pseudo_internal['EnforcementDate'] = null; $pseudo_internal['ScheduledEnforcementDate'] = '2027-04-01';
$GLOBALS['responses'] = array( response( $h ), response( array( 'result' => array( 'success' => true, 'Amendment_History' => array( $pseudo_internal ) ) ) ) );
check( egov_ondemand_match( $law, $pseudo['law_revision_id'] )['internal']['status'] === 'matched', 'pseudo date metadata matches without claiming actual date' );
$response = $GLOBALS['filters']['rest_post_dispatch'][0]( new DiscoveryResponse(), null, new DiscoveryRequest() );
check( str_contains( $response->headers['Cache-Control'], 'no-store' ) && $response->headers['Vary'] === 'Cookie', 'REST private even errors' );
$GLOBALS['page'] = (object) array( 'post_content' => '[egov_ondemand_summary]' ); $GLOBALS['options']['egov_law_monitor_ondemand_enabled'] = '0';
try { $GLOBALS['hooks']['template_redirect'][0](); check( false, 'must deny' ); } catch ( RuntimeException $e ) { check( $e->getMessage() === 'DENIED', 'page denied before rendering' ); }
check( defined( 'DONOTCACHEPAGE' ) && in_array( 'litespeed_control_set_nocache', $GLOBALS['actions'], true ), 'page cache signals' );
$GLOBALS['options']['egov_law_monitor_ondemand_enabled'] = '1';
check( str_contains( egov_ondemand_link_shortcode(), '/ondemand-summary/' ), 'card to dedicated page' );
$html = egov_ondemand_page_shortcode(); check( str_contains( $html, 'data-search' ) && ! str_contains( $html, 'AI要約を生成' ), 'stage A page no generate action' );
ob_start(); egov_ondemand_render_settings(); $html = ob_get_clean(); check( str_contains( $html, 'egov_ondemand_settings' ) && str_contains( $html, 'options.php' ), 'independent Settings API form' );
echo 'PASS: ' . $GLOBALS['assertions'] . " assertions; offline discovery only\n";
