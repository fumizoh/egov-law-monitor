<?php
/** Run isolated constant configurations against the fixed-link pages. */
$case = $argv[1] ?? null;
if ( $case !== null ) {
    if ( $case !== 'undefined' ) { define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', $case === 'public' ); }
    if ( $case === 'allowlist' ) { define( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS', array( 10 ) ); }
    if ( $case === 'string_id' ) { define( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS', array( '10' ) ); }
    define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', $case === 'sandbox' ? 'sandbox' : 'production' );
    require __DIR__ . '/standard-plan-test-bootstrap.php';
    $page_admin = $case === 'admin'; if ( $case === 'anonymous' ) { $page_logged_in = false; }
    if ( $case === 'sandbox' ) { $page_admin = true; }
    $allowed = in_array( $case, array( 'admin', 'public', 'allowlist', 'sandbox' ), true );
    if ( egov_law_monitor_standard_application_allowed() !== $allowed ) { throw new RuntimeException( 'gate mismatch' ); }
    $_POST = array( 'egov_square_checkout' => '1', 'egov_checkout_cancel_request' => '1' );
    $application = egov_law_monitor_standard_application_shortcode(); $panel = egov_law_monitor_plan_status_shortcode();
    if ( str_contains( $application, 'https://square.link/u/30s16R3f' ) !== ( $allowed && $case !== 'sandbox' ) ) { throw new RuntimeException( 'payment link gate mismatch' ); }
    if ( ! $allowed && ( str_contains( $panel, '/standard-application/' ) || $wpdb->queries ) ) { throw new RuntimeException( 'restricted display read/leak' ); }
    if ( $page_plan !== 'free' || $wpdb->contracts || $wpdb->legacy ) { throw new RuntimeException( 'unexpected write' ); }
    echo "access case passed\n"; return;
}
$count = 0;
foreach ( array( 'undefined', 'private', 'admin', 'public', 'allowlist', 'string_id', 'anonymous', 'sandbox' ) as $case ) {
    $process = proc_open( array( PHP_BINARY, __FILE__, $case ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'subprocess failed' ); }
    $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
    if ( proc_close( $process ) !== 0 || ! str_contains( $out, 'access case passed' ) || $err !== '' ) { throw new RuntimeException( 'failed: ' . $case . ' ' . $err ); }
    ++$count;
}
echo "{$count} fixed-link access configurations passed.\n";
