<?php

/*
 * Minimal, independent WordPress DB connection for the concurrency test.
 * The PHPUnit bootstrap is intentionally not loaded in child processes.
 */
$approved_environment = array(
	'EGOV_ALLOW_TEST_DATABASE' => '1',
	'WP_TEST_DB_HOST' => 'wp-test-db',
	'WP_TEST_DB_NAME' => 'egov_law_monitor_test',
	'WP_TEST_DB_USER' => 'egov_test',
);
$worker_stage = 'environment validation';
register_shutdown_function( function () use ( &$worker_stage ) {
	$error = error_get_last();
	if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
		fwrite( STDERR, sprintf( "Worker stopped during %s: %s in %s:%d\n", $worker_stage, $error['message'], $error['file'], $error['line'] ) );
	}
} );
foreach ( $approved_environment as $name => $expected ) {
	if ( getenv( $name ) !== $expected ) {
		fwrite( STDERR, "Refusing worker DB connection: {$name} is not the approved test value.\n" );
		exit( 1 );
	}
}

$worker_stage = 'test database configuration load';
require_once __DIR__ . '/wp-tests-config.php';
if ( DB_HOST !== 'wp-test-db' || DB_NAME !== 'egov_law_monitor_test' || DB_USER !== 'egov_test' ) {
	fwrite( STDERR, "Refusing worker DB connection: test configuration is not the approved target.\n" );
	exit( 1 );
}

$worker_stage = 'WordPress runtime initialization';
require_once ABSPATH . 'wp-settings.php';
global $wpdb;
if ( DB_HOST !== 'wp-test-db' || DB_NAME !== 'egov_law_monitor_test' || DB_USER !== 'egov_test' || 'egov_law_monitor_test' !== $wpdb->get_var( 'SELECT DATABASE()' ) ) {
	fwrite( STDERR, "Refusing worker DB connection: active database is not the approved test target.\n" );
	exit( 1 );
}

$worker_stage = 'usage service initialization';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/ai-summary-usage.php';
$user_id = isset( $argv[1] ) ? absint( $argv[1] ) : 0;
$request_key = isset( $argv[2] ) ? $argv[2] : '';
$worker_number = isset( $argv[3] ) ? $argv[3] : '';
$barrier_id = isset( $argv[4] ) ? $argv[4] : '';
if ( ! in_array( $worker_number, array( '1', '2' ), true ) || ! preg_match( '/^[a-f0-9]{16}$/', $barrier_id ) ) {
	fwrite( STDERR, "Refusing worker: invalid synchronization identifier.\n" );
	exit( 1 );
}
$barrier_dir = sys_get_temp_dir() . '/egov-ai-usage-' . $barrier_id;
$service = new Egov_Law_Monitor_AI_Summary_Usage_Service( $wpdb, 'sandbox' );
if ( ! is_dir( $barrier_dir ) || ! is_writable( $barrier_dir ) || false === file_put_contents( $barrier_dir . '/ready-' . $worker_number, 'ready', LOCK_EX ) ) {
	fwrite( STDERR, sprintf( "Worker %s could not write readiness marker to %s (temp=%s, db=%s).\n", $worker_number, $barrier_dir, sys_get_temp_dir(), DB_NAME . '@' . DB_HOST ) );
	exit( 1 );
}
while ( ! file_exists( $barrier_dir . '/go' ) ) {
	usleep( 10000 );
}
$worker_stage = 'quota reservation';
$result = $service->reserve( $user_id, 'free', $request_key );
$output = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'reserved' => true );
fwrite( STDOUT, json_encode( $output ) . "\n" );
exit( 0 );
