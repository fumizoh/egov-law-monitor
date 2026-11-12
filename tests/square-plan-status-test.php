<?php
/** Read-only fixed-link page regressions. */
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
require __DIR__ . '/standard-plan-test-bootstrap.php';
$checks = 0;
function check_page( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
$link = 'https://square.link/u/30s16R3f';
$html = egov_law_monitor_standard_application_shortcode();
check_page( str_contains( $html, $link ) && str_contains( $html, 'Squareでお支払いへ進む' ) && ! str_contains( $html, '<form' ), 'fixed production link, no application form' );
check_page( str_contains( $html, '再度決済せず' ) && str_contains( $html, '請求書番号' ), 'duplicate-payment warning and invoice guidance' );
$html = egov_law_monitor_plan_status_shortcode();
check_page( str_contains( $html, '<h2>💳 ご利用プラン</h2>' ) && str_contains( $html, 'egov-plan-status-badge--free' ) && str_contains( $html, 'https://example.test/standard-application/' ) && ! str_contains( $html, $link ), 'mypage badge and same-site application link retained' );
check_page( str_contains( $html, '<a class="egov-law-button" href="https://example.test/standard-application/">Standardプランに申し込む</a>' ), 'Free application link reuses cancellation button style without changing destination or text' );
check_page( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && $page_no_cache >= 2, 'personal panels request no-cache' );
check_page( isset( $page_shortcodes['egov_plan_status'], $page_shortcodes['egov_standard_application'] ), 'existing shortcode names retained' );
foreach ( array( 'ready', 'creating', 'unknown', 'cancel_requested', 'closing', 'cancel_unknown', 'canceling', 'unexpected', 'CLOSED' ) as $state ) {
    reset_standard_page(); $wpdb->legacy = array( array( 'id' => 1, 'user_id' => 10, 'state' => $state ) );
    check_page( ! str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'legacy unresolved history blocks repeat payment: ' . $state );
}
reset_standard_page(); $wpdb->legacy = array_fill( 0, 3, array( 'user_id' => 10, 'state' => 'closed' ) );
check_page( str_contains( egov_law_monitor_standard_application_shortcode(), $link ), 'closed historical rows do not block' );
reset_standard_page(); $wpdb->legacy = array( array( 'user_id' => 20, 'state' => 'ready' ) ); $wpdb->contracts = array( 20 );
check_page( str_contains( egov_law_monitor_standard_application_shortcode(), $link ), 'other users do not affect current user' );
reset_standard_page(); $wpdb->legacy_table = false;
check_page( str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && ! array_filter( $wpdb->queries, static fn( $q ) => str_contains( $q, 'FROM `wp_egov_square_checkout_attempts`' ) ), 'fresh sites need no legacy table' );
foreach ( array( 'information_schema', 'egov_square_subscriptions', 'egov_square_checkout_attempts' ) as $failure ) {
    reset_standard_page(); $wpdb->fail = $failure;
    $html = egov_law_monitor_standard_application_shortcode();
    check_page( ! str_contains( $html, $link ) && str_contains( $html, '確認できません' ) && ! str_contains( $html, 'private error' ), 'read failure blocks payments: ' . $failure );
}
reset_standard_page(); $wpdb->contracts = array( 10 );
check_page( ! str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && str_contains( egov_law_monitor_plan_status_shortcode(), '登録済み' ), 'Free with registered contract never offered another payment' );
reset_standard_page(); $page_plan = 'standard';
check_page( ! str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && str_contains( egov_law_monitor_plan_status_shortcode(), 'egov-plan-status-badge--standard' ) && ! $wpdb->queries, 'Standard entitlement display retained without legacy reads' );
reset_standard_page(); $page_plan = 'other';
check_page( ! str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && str_contains( egov_law_monitor_plan_status_shortcode(), '確認が必要' ), 'unknown plan fails closed' );
reset_standard_page(); $page_logged_in = false;
check_page( str_contains( egov_law_monitor_standard_application_shortcode(), 'ログイン' ) && ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ) && ! $wpdb->queries, 'anonymous panels do not read private data' );
reset_standard_page();
foreach ( array( 'egov_square_checkout', 'egov_checkout_cancel_request' ) as $retired ) {
    $_POST = array( $retired => '1', 'checkout_url' => 'https://evil.test', 'plan' => 'standard' );
    check_page( str_contains( egov_law_monitor_standard_application_shortcode(), $link ) && $page_plan === 'free' && ! $wpdb->contracts && ! $wpdb->legacy, 'retired front-end POST cannot execute mutations: ' . $retired );
}
reset_standard_page(); $page_home = 'http://example.test';
check_page( egov_law_monitor_standard_application_page_url() === '', 'unsafe local destination rejected' );
echo "{$checks} fixed-link display checks passed; no DB writes or API used.\n";
