<?php
/** Standalone: all Square HTTP mocked; no database or WordPress runtime. */
define( 'ABSPATH', __DIR__ );
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION', 'test-secret-do-not-display' );
define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION', 'LOCATION' );
define( 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_PRODUCTION', 'VARIATION' );
class WP_Error {
    public function __construct( public $code, public $message ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_userdata( $id ) { return $id === 10 ? (object) array( 'ID' => 10 ) : false; }
function wp_verify_nonce( $v, $action ) { return $v === 'valid:' . $action; }
function current_time( $format ) { return '2026-11-12 12:00:00'; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_nonce_field( $a, $n ) { echo '<input name="' . esc_html( $n ) . '">'; }
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['calls'][] = array( $url, $args );
    if ( ! $GLOBALS['queue'] ) { throw new LogicException( 'unexpected HTTP' ); }
    $v = array_shift( $GLOBALS['queue'] );
    if ( $v instanceof Throwable ) { throw $v; }
    return $v;
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../wordpress-plugin/includes/square-api.php';
require __DIR__ . '/../wordpress-plugin/includes/square-invoices.php';
$checks = 0;
function verify_invoice( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function response_invoice( $data, $status = 200 ) { return array( 'response' => array( 'code' => $status ), 'body' => json_encode( $data ) ); }
function fixtures_invoice() {
    return array( 'id' => 'inv:0-abc', 'invoice_number' => '000004', 'location_id' => 'LOCATION', 'subscription_id' => 'SUB', 'status' => 'PAID',
        'payment_requests' => array( array( 'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ),
        'primary_recipient' => array( 'customer_id' => 'CUSTOMER', 'given_name' => '<script>alert(1)</script>', 'email_address' => 'person@example.test' ), 'card_id' => 'NEVER_DISPLAY_CARD' );
}
function fixtures_subscription() { return array( 'id' => 'SUB', 'location_id' => 'LOCATION', 'customer_id' => 'CUSTOMER', 'plan_variation_id' => 'VARIATION', 'status' => 'ACTIVE', 'card_id' => 'NEVER_DISPLAY_CARD' ); }
function prepare_invoice( $invoice = null, $subscription = null ) {
    $GLOBALS['allowed'] = true; $GLOBALS['calls'] = array();
    $invoice = $invoice ?? fixtures_invoice();
    $GLOBALS['queue'] = array( response_invoice( array( 'invoices' => array( fixtures_invoice() ) ) ), response_invoice( array( 'invoice' => $invoice ) ), response_invoice( array( 'subscription' => $subscription ?? fixtures_subscription() ) ) );
}
function lookup_invoice( $number = '000004', $nonce = 'valid:egov_square_invoice_10', $user = 10 ) { return egov_law_monitor_lookup_square_invoice( $user, $number, $nonce ); }
prepare_invoice(); $r = lookup_invoice();
verify_invoice( ! is_wp_error( $r ) && ! in_array( false, $r['checks'], true ) && $r['fields']['請求書番号'] === '000004', 'normal validation and leading zeroes' );
verify_invoice( count( $calls ) === 3 && str_contains( $calls[1][0], 'inv%3A0-abc' ) && str_contains( $calls[2][0], '/subscriptions/SUB' ), 'list -> encoded GetInvoice -> RetrieveSubscription' );
foreach ( $calls as $call ) { verify_invoice( str_starts_with( $call[0], 'https://connect.squareup.com/v2/' ) && $call[1]['redirection'] === 0 && $call[1]['sslverify'] === true && $call[1]['timeout'] <= 10, 'fixed HTTPS read transport' ); }
ob_start(); egov_law_monitor_render_invoice_lookup( 10, 'https://example.test/admin', $r ); $html = ob_get_clean();
verify_invoice( ! str_contains( $html, 'NEVER_DISPLAY_CARD' ) && ! str_contains( $html, EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ) && ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'allowlist and escaping' );
foreach ( array( 'forbidden', 'nonce', 'user', 'number' ) as $case ) {
    prepare_invoice(); if ( $case === 'forbidden' ) { $allowed = false; }
    $r = lookup_invoice( $case === 'number' ? 4 : '000004', $case === 'nonce' ? 'bad' : 'valid:egov_square_invoice_10', $case === 'user' ? 20 : 10 );
    verify_invoice( is_wp_error( $r ) && ! $calls, 'reject before HTTP: ' . $case );
}
prepare_invoice(); $queue = array( response_invoice( array( 'invoices' => array( fixtures_invoice() ) ) ) );
verify_invoice( lookup_invoice( '4' )->code === 'square_invoice_not_found', 'exact string comparison' );
prepare_invoice(); $duplicate = fixtures_invoice(); $duplicate['id'] = 'inv:0-other'; $queue = array( response_invoice( array( 'invoices' => array( fixtures_invoice(), $duplicate ) ) ) );
verify_invoice( lookup_invoice()->code === 'square_invoice_multiple', 'duplicate number rejected' );
prepare_invoice(); array_unshift( $queue, response_invoice( array( 'invoices' => array(), 'cursor' => 'next' ) ) );
verify_invoice( ! is_wp_error( lookup_invoice() ) && count( $calls ) === 4 && str_contains( $calls[1][0], 'cursor=next' ), 'pagination complete' );
prepare_invoice(); $queue = array(); for ( $n = 1; $n <= 5; ++$n ) { $queue[] = response_invoice( array( 'invoices' => $n === 1 ? array( fixtures_invoice() ) : array(), 'cursor' => 'page' . $n ) ); }
verify_invoice( lookup_invoice()->code === 'square_invoice_incomplete' && count( $calls ) === 5, 'single candidate not accepted on incomplete search' );
prepare_invoice(); $queue = array_fill( 0, 2, response_invoice( array( 'cursor' => 'same' ) ) );
verify_invoice( lookup_invoice()->code === 'square_invoice_incomplete', 'cursor cycle rejected' );
foreach ( array( 401, 403, 404, 429, 500 ) as $status ) {
    prepare_invoice(); $queue = array( response_invoice( array( 'secret' => EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ), $status ) );
    $r = lookup_invoice(); verify_invoice( $r->code === 'square_invoice_http_' . $status && ! str_contains( $r->message, EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ), 'sanitized HTTP ' . $status );
}
foreach ( array( new WP_Error( 'timeout', EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ), new RuntimeException( EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ), array( 'response' => array( 'code' => 200 ), 'body' => '{bad' ), response_invoice( array( 'errors' => array( 'bad' ) ) ), response_invoice( array( 'secret' => EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ) ) ) as $response ) {
    prepare_invoice(); $queue = array( $response ); $r = lookup_invoice(); verify_invoice( is_wp_error( $r ) && ! str_contains( $r->message, EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION ), 'safe transport/response error' );
}
foreach ( array( 'REFUNDED', 'PARTIALLY_REFUNDED', 'UNPAID' ) as $status ) {
    $invoice = fixtures_invoice(); $invoice['status'] = $status; prepare_invoice( $invoice ); $r = lookup_invoice(); verify_invoice( $r['checks']['請求書はPAID'] === false, 'non-paid invoice ' . $status );
}
foreach ( array( 'amount', 'currency', 'missing' ) as $case ) {
    $invoice = fixtures_invoice();
    if ( $case === 'amount' ) { $invoice['payment_requests'][0]['total_completed_amount_money']['amount'] = 499; }
    if ( $case === 'currency' ) { $invoice['payment_requests'][0]['computed_amount_money']['currency'] = 'USD'; }
    if ( $case === 'missing' ) { unset( $invoice['payment_requests'] ); }
    prepare_invoice( $invoice ); $r = lookup_invoice(); verify_invoice( ! $r['checks']['請求額・支払済み額はともに500 JPY'], 'bad money ' . $case );
}
foreach ( array( 'status' => 'CANCELED', 'location_id' => 'OTHER', 'customer_id' => 'OTHER', 'plan_variation_id' => 'OTHER' ) as $field => $value ) {
    $sub = fixtures_subscription(); $sub[$field] = $value; prepare_invoice( null, $sub ); $r = lookup_invoice(); verify_invoice( in_array( false, $r['checks'], true ), 'subscription mismatch ' . $field );
}
prepare_invoice(); $queue = array(); verify_invoice( egov_law_monitor_invoice_get( array( 'environment' => 'production' ), '/v2/invoices', microtime( true ) - 1 )->code === 'square_invoice_incomplete' && ! $calls, 'deadline refuses HTTP' );
foreach ( array( 'id', 'invoice_number', 'subscription_id' ) as $field ) {
    $invoice = fixtures_invoice(); unset( $invoice[$field] ); prepare_invoice( $invoice );
    verify_invoice( lookup_invoice()->code === 'square_invoice_changed' && count( $calls ) === 2, 'GetInvoice identity missing: ' . $field );
}
$invoice = fixtures_invoice(); $invoice['location_id'] = 'OTHER'; prepare_invoice( $invoice );
verify_invoice( ! lookup_invoice()['checks']['Location一致'], 'GetInvoice Location mismatch shown' );
$invoice = fixtures_invoice(); unset( $invoice['primary_recipient'] ); prepare_invoice( $invoice );
verify_invoice( ! lookup_invoice()['checks']['Customer ID一致'], 'missing recipient never passes identity check' );
prepare_invoice(); $queue = array( response_invoice( array( 'invoices' => null ) ) );
verify_invoice( lookup_invoice()->code === 'square_invoice_response', 'malformed null list not treated as no match' );
$invoice = fixtures_invoice(); $request = $invoice['payment_requests'][0];
$request['computed_amount_money']['amount'] = 250; $request['total_completed_amount_money']['amount'] = 250;
$invoice['payment_requests'] = array( $request, $request ); prepare_invoice( $invoice );
verify_invoice( lookup_invoice()['checks']['請求額・支払済み額はともに500 JPY'], 'all payment requests summed' );
echo "{$checks} invoice checks passed; all HTTP mocked, no DB writes.\n";
