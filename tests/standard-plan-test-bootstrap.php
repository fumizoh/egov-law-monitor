<?php
/** Offline doubles: only consent metadata writes are permitted; HTTP is forbidden. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
function get_current_user_id() { return $GLOBALS['page_user']; }
function is_user_logged_in() { return $GLOBALS['page_logged_in']; }
function current_user_can( $cap ) { return $GLOBALS['page_admin']; }
function egov_law_monitor_get_plan( $id ) { return $GLOBALS['page_plan']; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_parse_url( $v ) { return parse_url( $v ); }
function home_url( $path ) { return $GLOBALS['page_home'] . $path; }
function nocache_headers() { ++$GLOBALS['page_no_cache']; }
function add_shortcode( $name, $callback ) { $GLOBALS['page_shortcodes'][$name] = $callback; }
function add_action( ...$args ) { $GLOBALS['page_hooks'][] = $args; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="valid">'; }
function wp_verify_nonce( $nonce, $action ) { $GLOBALS['page_nonce_action'] = $action; return $nonce === 'valid'; }
function wp_unslash( $v ) { return $v; }
class WP_Error { public function __construct( public $code, public $message ) {} public function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['page_meta'][$key] ?? ''; }
function wp_safe_remote_get( ...$args ) { throw new LogicException( 'Square API not permitted' ); }
function wp_safe_remote_post( ...$args ) { throw new LogicException( 'Square API not permitted' ); }
function update_user_meta( $id, $key, $value ) { if ( $key !== 'egov_law_monitor_standard_consent' ) { throw new LogicException( 'Plan write not permitted' ); } ++$GLOBALS['page_writes']; if ( $GLOBALS['page_save_fail'] ) { return false; } if ( ( $GLOBALS['page_meta'][$key] ?? null ) === $value ) { return false; } $GLOBALS['page_meta'][$key] = $value; return 1; }
class StandardPageStore {
    public $prefix = 'wp_'; public $last_error = ''; public $contracts = array(); public $legacy = array(); public $legacy_table = true; public $fail = ''; public $queries = array();
    public function prepare( $sql, ...$p ) { return array( $sql, $p ); }
    public function get_results( $query, $mode ) {
        list( $sql, $p ) = $query; $this->queries[] = $sql; $this->last_error = '';
        if ( $this->fail && str_contains( $sql, $this->fail ) ) { $this->last_error = 'private error'; return null; }
        if ( str_contains( $sql, 'information_schema' ) ) { return $this->legacy_table ? array( array( 'TABLE_NAME' => $this->prefix . 'egov_square_checkout_attempts' ) ) : array(); }
        if ( str_contains( $sql, 'egov_square_subscriptions' ) ) { return in_array( $p[0], $this->contracts, true ) ? array( array( 'id' => 1 ) ) : array(); }
        if ( str_contains( $sql, 'egov_square_checkout_attempts' ) ) { return array_values( array_filter( $this->legacy, static fn( $r ) => $r['user_id'] === $p[0] && $r['state'] !== $p[1] ) ); }
        throw new LogicException( 'Unexpected read' );
    }
    public function __call( $name, $args ) { throw new LogicException( 'Unexpected DB operation: ' . $name ); }
}
function reset_standard_page() {
    unset( $GLOBALS['egov_standard_consent_result'] ); $GLOBALS['page_meta'] = array(); $GLOBALS['page_save_fail'] = false; $GLOBALS['page_writes'] = 0; $_SERVER['REQUEST_METHOD'] = 'GET'; $GLOBALS['wpdb'] = new StandardPageStore(); $GLOBALS['page_user'] = 10; $GLOBALS['page_logged_in'] = true; $GLOBALS['page_admin'] = true; $GLOBALS['page_plan'] = 'free'; $GLOBALS['page_home'] = 'https://example.test'; $GLOBALS['page_no_cache'] = 0; $_POST = array(); $_GET = array();
}
reset_standard_page();
require __DIR__ . '/../wordpress-plugin/includes/standard-plan-page.php';
