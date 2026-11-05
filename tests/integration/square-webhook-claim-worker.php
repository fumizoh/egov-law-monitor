<?php

/* Independent WordPress database connection used to race webhook claims. */
$approved_environment = array(
	'EGOV_ALLOW_TEST_DATABASE' => '1',
	'WP_TEST_DB_HOST' => 'wp-test-db',
	'WP_TEST_DB_NAME' => 'egov_law_monitor_test',
	'WP_TEST_DB_USER' => 'egov_test',
);
$worker_stage = 'environment validation';
register_shutdown_function( static function () use ( &$worker_stage ) {
	$error = error_get_last();
	if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
		fwrite( STDERR, sprintf( "Webhook claim worker stopped during %s: %s in %s:%d\n", $worker_stage, $error['message'], $error['file'], $error['line'] ) );
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

$worker_stage = 'webhook repository initialization';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/square-webhook.php';
$event_row_id = isset( $argv[1] ) ? $argv[1] : '';
$worker_number = isset( $argv[2] ) ? $argv[2] : '';
$barrier_id = isset( $argv[3] ) ? $argv[3] : '';
if ( ! preg_match( '/\A[1-9][0-9]*\z/', $event_row_id ) || ! in_array( $worker_number, array( '1', '2' ), true ) || ! preg_match( '/\A[a-f0-9]{16}\z/', $barrier_id ) ) {
	fwrite( STDERR, "Refusing worker: invalid synchronization identifier.\n" );
	exit( 1 );
}

$barrier_dir = sys_get_temp_dir() . '/egov-square-webhook-' . $barrier_id;
if ( ! is_dir( $barrier_dir ) || ! is_writable( $barrier_dir ) || false === file_put_contents( $barrier_dir . '/ready-' . $worker_number, 'ready', LOCK_EX ) ) {
	fwrite( STDERR, "Webhook claim worker could not reach the synchronization barrier.\n" );
	exit( 1 );
}
$deadline = microtime( true ) + 10;
while ( ! file_exists( $barrier_dir . '/go' ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
if ( ! file_exists( $barrier_dir . '/go' ) ) {
	fwrite( STDERR, "Webhook claim worker timed out at the synchronization barrier.\n" );
	exit( 1 );
}

$worker_stage = 'event claim';
$result = ( new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb ) )->claim_event( (int) $event_row_id );
if ( is_wp_error( $result ) ) {
	fwrite( STDOUT, wp_json_encode( array( 'error' => $result->get_error_code() ) ) . "\n" );
	exit( 2 );
}
fwrite(
	STDOUT,
	wp_json_encode(
		array(
			'claimed' => is_array( $result ),
			'generation' => is_array( $result ) ? (int) $result['claim_generation'] : null,
		)
	) . "\n"
);
exit( 0 );
