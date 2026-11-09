<?php
/** PRG handler tests: owned DB sessions and redirect transport are simulated. */
require __DIR__ . '/square-checkout-cancel-test.php';
$prg_baseline = $checks;
function prepare_cancel_post() {
    cancel_reset(); $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = request_fields() + array( 'egov_checkout_cancel_request' => '1', 'redirect_to' => 'https://evil.example/' );
    $GLOBALS['redirects'] = array();
}
function process_cancel_post() {
    return egov_law_monitor_checkout_cancel_post( static fn() => new CheckoutSession( $GLOBALS['wpdb'] ), static function ( $url, $status ) { $GLOBALS['redirects'][] = array( $url, $status ); return true; } );
}
prepare_cancel_post();
check( process_cancel_post() === true && $redirects === array( array( 'https://wp.example.test/standard-application/', 303 ) ), 'successful cancellation redirects with 303 to fixed safe page, ignoring posted target' );
check( $wpdb->attempts[1]['state'] === 'cancel_requested' && count( $wpdb->logs ) === 1 && ! $http, 'PRG preserves atomic request audit and never calls Square' );
process_cancel_post();
$first = egov_law_monitor_standard_application_shortcode(); $second = egov_law_monitor_standard_application_shortcode();
check( count( $redirects ) === 1 && count( $wpdb->logs ) === 1 && $first === $second, 'duplicate hook or shortcode rendering cannot repeat update audit or redirect' );
check( str_contains( $first, 'キャンセル手続き中' ) && ! str_contains( $first, '対象・申込状態が変わりました' ), 'successful request does not mix a conflict error into pending display' );
unset( $GLOBALS['egov_checkout_cancel_post'] ); $_SERVER['REQUEST_METHOD'] = 'GET';
check( egov_law_monitor_checkout_cancel_post() === null && count( $wpdb->logs ) === 1, 'GET cannot process cancellation even if simulated POST data remains' );
$_POST = array();
check( str_contains( egov_law_monitor_standard_application_shortcode(), 'キャンセル手続き中' ) && ! str_contains( egov_law_monitor_standard_application_shortcode(), '対象・申込状態が変わりました' ), 'redirected GET and refresh show current pending status without stale errors' );
foreach ( array( 'nonce', 'signature', 'owner', 'logged_out', 'state', 'audit', 'mixed' ) as $failure ) {
    prepare_cancel_post();
    if ( $failure === 'nonce' ) { $_POST['checkout_cancel_nonce'] = 'invalid'; }
    if ( $failure === 'signature' ) { $_POST['checkout_cancel_token'] = 'invalid'; }
    if ( $failure === 'owner' ) { $checkout_user = 20; $_POST['checkout_cancel_nonce'] = 'valid:egov_checkout_cancel_request_20_1'; }
    if ( $failure === 'logged_out' ) { $checkout_logged_in = false; }
    if ( $failure === 'state' ) { $wpdb->attempts[1]['state'] = 'cancel_requested'; }
    if ( $failure === 'audit' ) { $wpdb->fail = 'audit'; }
    if ( $failure === 'mixed' ) { $_POST['egov_square_checkout'] = '1'; }
    $state = $wpdb->attempts[1]['state'];
    $result = process_cancel_post();
    check( is_wp_error( $result ) && ! $redirects && ! $wpdb->logs && $wpdb->attempts[1]['state'] === $state && ! $http, 'invalid request cannot mutate or redirect: ' . $failure );
    $html = egov_law_monitor_standard_application_shortcode();
    check( $failure === 'logged_out' ? str_contains( $html, 'ログイン' ) : str_contains( $html, esc_html( $result->get_error_message() ) ), 'real request error remains visible: ' . $failure );
}
prepare_cancel_post();
// Calling the renderer alone no longer dispatches a POST operation.
$html = egov_law_monitor_standard_application_shortcode();
check( $wpdb->attempts[1]['state'] === 'ready' && ! $wpdb->logs && ! $redirects, 'shortcode never performs cancellation POST itself' );
check( ! str_contains( $html, '対象・申込状態が変わりました' ), 'renderer does not manufacture a conflict from an unprocessed POST' );
echo ( $checks - $prg_baseline ) . " PRG checks passed; all DB/redirect operations mocked.\n";
