<?php

/*
 * Fail closed before loading the official WordPress test bootstrap, which
 * creates and resets tables in the configured test database.
 */
$expected_environment = array(
	'EGOV_ALLOW_TEST_DATABASE' => '1',
	'WP_TEST_DB_HOST'           => 'wp-test-db',
	'WP_TEST_DB_NAME'           => 'egov_law_monitor_test',
	'WP_TEST_DB_USER'           => 'egov_test',
	'WP_TESTS_DIR'              => '/opt/wordpress-tests',
	'WP_CORE_DIR'               => '/opt/wordpress',
	'WP_TEST_TOOLS_DIR'         => '/opt/wp-test-tools',
);

foreach ( $expected_environment as $name => $expected_value ) {
	if ( getenv( $name ) !== $expected_value ) {
		fwrite( STDERR, "Refusing to run WordPress DB tests: {$name} is not the approved test value.\n" );
		exit( 1 );
	}
}

foreach ( array( 'WP_TESTS_DIR', 'WP_CORE_DIR', 'WP_TEST_TOOLS_DIR' ) as $directory_variable ) {
	if ( ! is_dir( getenv( $directory_variable ) ) ) {
		fwrite( STDERR, "WordPress DB test dependency is missing: {$directory_variable}. Build the test image first.\n" );
		exit( 1 );
	}
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', getenv( 'WP_TEST_TOOLS_DIR' ) . '/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

require_once WP_TESTS_CONFIG_FILE_PATH;

if ( DB_HOST !== 'wp-test-db' || DB_NAME !== 'egov_law_monitor_test' || DB_USER !== 'egov_test' ) {
	fwrite( STDERR, "Refusing to start WordPress DB tests: test configuration does not target the approved test database.\n" );
	exit( 1 );
}

require_once getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';

global $wpdb;

if ( DB_HOST !== 'wp-test-db' || DB_NAME !== 'egov_law_monitor_test' || DB_USER !== 'egov_test' ) {
	fwrite( STDERR, "Refusing to run WordPress DB tests: loaded database configuration is not the approved test target.\n" );
	exit( 1 );
}

if ( $wpdb->get_var( 'SELECT DATABASE()' ) !== 'egov_law_monitor_test' ) {
	fwrite( STDERR, "Refusing to run WordPress DB tests: active database does not match the approved test database.\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/database.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/ai-summary-usage.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/entitlement-policy.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/entitlement-service.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/user-settings.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/ai-summary-usage-api.php';

/* Keep network-dependent plugin boundaries deterministic in the DB suite. */
if ( ! function_exists( 'egov_law_monitor_search_laws' ) ) {
	function egov_law_monitor_search_laws( $search_text ) {
		return array( array( 'law_id' => 'test-law' ) );
	}
}

if ( ! function_exists( 'egov_law_monitor_generate_ai_summary' ) ) {
	function egov_law_monitor_generate_ai_summary( $law_id, $effective_date, $law_data_id, $sub_revision ) {
		$GLOBALS['egov_law_monitor_test_summary_generator_calls'] = ( $GLOBALS['egov_law_monitor_test_summary_generator_calls'] ?? 0 ) + 1;
		return array(
			'law_id'         => $law_id,
			'law_name'       => 'テスト法令',
			'effective_date' => $effective_date,
			'law_data_id'    => $law_data_id,
			'sub_revision'   => $sub_revision,
			'revision_hash'  => hash( 'sha256', implode( '|', array( $law_id, $effective_date, $law_data_id, $sub_revision ) ) ),
			'summary'        => array( 'title' => 'テスト要約', 'body' => 'テスト本文' ),
		);
	}
}
