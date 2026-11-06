<?php

use PHPUnit\Framework\TestCase;

final class SquareWebhookTest extends TestCase {
	private $config_filter;
	private $service_filter;
	private $repository;
	private $config_filter_called = false;
	private $event_ids = array();
	private $event_scopes = array();
	private $environment = 'sandbox';
	private $merchant_id = 'webhook-test-merchant';
	private $signature_key = 'webhook-test-signature-key';
	private $notification_url = 'https://staging.example.test/wp-json/egov-law-monitor/v1/square-webhook';

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->repository = new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb );
		$this->config_filter = function ( $config ) {
			$this->config_filter_called = true;
			return array(
				'environment'      => $this->environment,
				'signature_key'    => $this->signature_key,
				'notification_url' => $this->notification_url,
				'merchant_id'      => $this->merchant_id,
			);
		};
		add_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		$this->service_filter = static function ( $service ) {
			return $service;
		};
		add_filter( 'egov_law_monitor_square_webhook_service', $this->service_filter, 10, 1 );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		remove_filter( 'egov_law_monitor_square_webhook_service', $this->service_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$table = $wpdb->prefix . 'egov_law_square_events';
			foreach ( $this->event_ids as $event_id ) {
				$scope = isset( $this->event_scopes[ $event_id ] ) ? $this->event_scopes[ $event_id ] : array( 'sandbox', $this->merchant_id );
				$wpdb->delete( $table, array( 'environment' => $scope[0], 'merchant_id' => $scope[1], 'square_event_id' => $event_id ), array( '%s', '%s', '%s' ) );
			}
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_valid_signature_is_accepted_without_login_and_event_is_saved(): void {
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );

		$this->assert_webhook_status( 200, $response );
		$this->assertSame( array( 'received' => true, 'duplicate' => false ), $response->get_data() );
		$row = $this->get_event( end( $this->event_ids ) );
		$this->assertSame( 'sandbox', $row['environment'] );
		$this->assertSame( $this->merchant_id, $row['merchant_id'] );
		$this->assertSame( 'payment.updated', $row['event_type'] );
		$this->assertSame( 'received', $row['processing_status'] );
		$this->assertSame( $body, $row['normalized_payload'] );
	}

	public function test_production_webhook_is_rejected_without_explicit_activation(): void {
		$this->environment = 'production';
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 403, $response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ), 'production' ) );
	}

	/**
	 * Production activation is constant-scoped, so exercise the enabled path in
	 * a separate PHP process without changing the normal test process setting.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_production_webhook_accepts_and_persists_with_strict_true_activation(): void {
		$required_constants = array(
			'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED',
			'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT',
			'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_PRODUCTION',
		);
		foreach ( $required_constants as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$this->markTestSkipped( 'The separate test process inherited a Production configuration constant.' );
			}
		}
		define( 'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED', true );
		define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
		define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION', 'mock-production-token' );
		define( 'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_PRODUCTION', $this->merchant_id );
		define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION', 'mock-production-location' );
		define( 'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_PRODUCTION', 'mock-production-item' );
		define( 'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_PRODUCTION', 'mock-production-plan' );
		$this->assertTrue(
			egov_law_monitor_square_webhook_production_settings_ready(
				array(
					'merchant_id'      => $this->merchant_id,
					'signature_key'    => $this->signature_key,
					'notification_url' => $this->notification_url,
				)
			)
		);
		$this->environment = 'production';
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 200, $response );
		$duplicate_response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 200, $duplicate_response );
		$this->assertSame( array( 'received' => true, 'duplicate' => true ), $duplicate_response->get_data() );
		$row = $this->get_event_by_scope( end( $this->event_ids ), 'production', $this->merchant_id );
		$this->assertSame( 'production', $row['environment'] );
		$this->assertSame( $this->merchant_id, $row['merchant_id'] );
		$this->assertSame( $body, $row['normalized_payload'] );
	}

	public function test_invalid_signature_is_rejected_and_not_saved(): void {
		$body = $this->event_body();
		$response = $this->dispatch( $body, base64_encode( str_repeat( 'x', 32 ) ) );
		$this->assert_webhook_status( 403, $response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_missing_signature_is_rejected(): void {
		$body = $this->event_body();
		$response = $this->dispatch( $body, '' );
		$this->assert_webhook_status( 401, $response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_get_method_is_not_registered_for_webhook_route(): void {
		$request = new WP_REST_Request( 'GET', '/egov-law-monitor/v1/square-webhook' );
		$response = rest_do_request( $request );
		$this->assertNotSame( 200, $response->get_status() );
	}

	public function test_missing_signature_key_fails_closed(): void {
		remove_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		$this->config_filter = function ( $config ) {
			$this->config_filter_called = true;
			return array( 'environment' => 'sandbox', 'notification_url' => 'https://staging.example.test/webhook', 'merchant_id' => 'webhook-test-merchant' );
		};
		add_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 503, $response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_signature_for_a_different_notification_url_is_rejected(): void {
		$body = $this->event_body();
		$wrong_signature = base64_encode( hash_hmac( 'sha256', 'https://wrong.example.test/webhook' . $body, $this->signature_key, true ) );
		$response = $this->dispatch( $body, $wrong_signature );
		$this->assert_webhook_status( 403, $response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_signed_invalid_json_is_rejected(): void {
		$body = '{not-json';
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 400, $response );
	}

	public function test_duplicate_event_is_acknowledged_without_duplicate_row(): void {
		$body = $this->event_body();
		$first = $this->dispatch( $body, $this->sign( $body ) );
		$second = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 200, $first );
		$this->assert_webhook_status( 200, $second );
		$this->assertSame( true, $second->get_data()['duplicate'] );
		$this->assertSame( 1, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_database_failure_returns_server_error_and_does_not_claim_success(): void {
		remove_filter( 'egov_law_monitor_square_webhook_service', $this->service_filter, 10 );
		$this->service_filter = static function ( $service ) {
			$database = new class() {
				public $prefix = 'wptests_';
				public $last_error = '';
				public function query( $query ) {
					$this->last_error = 'private database detail';
					return false;
				}
				public function prepare( $query, ...$arguments ) {
					return $query;
				}
				public function get_var( $query ) {
					$this->last_error = 'private database detail';
					return null;
				}
			};
			return new Egov_Law_Monitor_Square_Webhook_Service( new Egov_Law_Monitor_Square_Webhook_Repository( $database ) );
		};
		add_filter( 'egov_law_monitor_square_webhook_service', $this->service_filter, 10, 1 );
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 500, $response );
		$this->assertSame( 'square_webhook_rejected', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'private database detail', wp_json_encode( $response->get_data() ) );
	}

	public function test_production_webhook_is_disabled_and_cannot_mix_with_sandbox_event(): void {
		$body = $this->event_body();
		$sandbox_response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 200, $sandbox_response );

		remove_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		$this->config_filter = function ( $config ) {
			$this->config_filter_called = true;
			return array(
				'environment'      => 'production',
				'signature_key'    => 'production-key-must-not-be-used-yet',
				'notification_url' => 'https://production.example.test/wp-json/egov-law-monitor/v1/square-webhook',
				'merchant_id'      => 'production-merchant',
			);
		};
		add_filter( 'egov_law_monitor_square_webhook_config', $this->config_filter );
		$production_signature = base64_encode( hash_hmac( 'sha256', 'https://production.example.test/wp-json/egov-law-monitor/v1/square-webhook' . $body, 'production-key-must-not-be-used-yet', true ) );
		$production_response = $this->dispatch( $body, $production_signature );
		$this->assert_webhook_status( 403, $production_response );
		$this->assertSame( 0, $this->count_event( end( $this->event_ids ), 'production', 'production-merchant' ) );
		$this->assertSame( 1, $this->count_event( end( $this->event_ids ) ) );
	}

	public function test_webhook_does_not_change_entitlement_or_create_subscription(): void {
		$user_id = 1;
		update_user_meta( $user_id, 'egov_law_monitor_plan', 'standard' );
		global $wpdb;
		$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subscriptions} WHERE user_id = %d", $user_id ) );
		$body = $this->event_body();
		$response = $this->dispatch( $body, $this->sign( $body ) );
		$this->assert_webhook_status( 200, $response );
		$this->assertSame( 'standard', get_user_meta( $user_id, 'egov_law_monitor_plan', true ) );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subscriptions} WHERE user_id = %d", $user_id ) ) );
	}

	public function test_repository_lists_received_due_retry_and_stale_processing_events_in_receive_order(): void {
		$received_id = $this->insert_repository_event( 'received', array( 'received_at' => '2026-01-01 00:00:00' ) );
		$due_retry_id = $this->insert_repository_event( 'retry', array( 'received_at' => '2026-01-02 00:00:00', 'next_retry_at' => '2026-01-01 00:00:00' ) );
		$this->insert_repository_event( 'retry', array( 'received_at' => '2026-01-03 00:00:00', 'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
		$stale_processing_id = $this->insert_repository_event( 'processing', array( 'received_at' => '2026-01-04 00:00:00', 'processing_started_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ), 'claim_generation' => 2 ) );
		$this->insert_repository_event( 'processing', array( 'received_at' => '2026-01-05 00:00:00', 'processing_started_at' => current_time( 'mysql', true ), 'claim_generation' => 1 ) );

		$rows = $this->repository->get_events_ready_for_processing( 'sandbox', $this->merchant_id, 10, 300 );
		$this->assertIsArray( $rows );
		$this->assertSame( array( $received_id, $due_retry_id, $stale_processing_id ), array_map( 'intval', array_column( $rows, 'id' ) ) );
		$this->assertSame( $received_id, (int) $rows[0]['id'] );
		$this->assertInstanceOf( WP_Error::class, $this->repository->get_events_ready_for_processing( 'sandbox', $this->merchant_id, 0, 300 ) );
	}

	public function test_repository_scope_filters_environment_and_merchant_for_list_and_claim(): void {
		$sandbox_match = $this->insert_repository_event( 'received' );
		$production = $this->insert_repository_event( 'received', array( 'environment' => 'production', 'merchant_id' => 'production-merchant' ) );
		$other_merchant = $this->insert_repository_event( 'received', array( 'merchant_id' => 'other-merchant' ) );

		$rows = $this->repository->get_events_ready_for_processing( 'sandbox', $this->merchant_id, 20, 300 );
		$this->assertSame( array( $sandbox_match ), array_map( 'intval', array_column( $rows, 'id' ) ) );
		$this->assertFalse( $this->repository->claim_event( $production, 'sandbox', $this->merchant_id ) );
		$this->assertFalse( $this->repository->claim_event( $other_merchant, 'sandbox', $this->merchant_id ) );
		$this->assertSame( array(), $this->repository->get_events_ready_for_processing( 'production', 'different-merchant', 20, 300 ) );
		$this->assertSame( 'received', $this->get_repository_event( $production )['processing_status'] );
		$this->assertSame( 'received', $this->get_repository_event( $other_merchant )['processing_status'] );
	}

	public function test_claim_is_exclusive_increments_generation_and_fences_old_worker(): void {
		$event_id = $this->insert_repository_event( 'received' );
		$first = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$this->assertIsArray( $first );
		$this->assertSame( 'processing', $first['processing_status'] );
		$this->assertSame( '1', (string) $first['claim_generation'] );
		$this->assertFalse( $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id ) );

		$old_generation = (int) $first['claim_generation'];
		$table = $this->event_table();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->update( $table, array( 'processing_started_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
		$reclaimed = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$this->assertIsArray( $reclaimed );
		$this->assertSame( '2', (string) $reclaimed['claim_generation'] );
		$this->assertFalse( $this->repository->mark_event_processed( $event_id, $old_generation ) );
		$this->assertTrue( $this->repository->mark_event_processed( $event_id, (int) $reclaimed['claim_generation'] ) );

		$row = $this->get_repository_event( $event_id );
		$this->assertSame( 'processed', $row['processing_status'] );
		$this->assertNotNull( $row['processed_at'] );
		$this->assertSame( $reclaimed['normalized_payload'], $row['normalized_payload'] );
	}

	public function test_claim_lost_does_not_modify_event(): void {
		$event_id = $this->insert_repository_event( 'processed' );
		$before = $this->get_repository_event( $event_id );
		$this->assertFalse( $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id ) );
		$this->assertSame( $before, $this->get_repository_event( $event_id ) );
	}

	public function test_independent_workers_can_claim_an_event_only_once(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is required for the independent-connection claim test.' );
		}

		$event_id = $this->insert_repository_event( 'received' );
		$barrier_id = bin2hex( random_bytes( 8 ) );
		$barrier_dir = sys_get_temp_dir() . '/egov-square-webhook-' . $barrier_id;
		$this->assertTrue( mkdir( $barrier_dir, 0700 ) );
		$worker = __DIR__ . '/square-webhook-claim-worker.php';
		$processes = array();
		$results = array();
		$diagnostics = array();

		for ( $i = 1; $i <= 2; $i++ ) {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open( array( PHP_BINARY, $worker, (string) $event_id, (string) $i, $barrier_id, 'sandbox', $this->merchant_id ), $descriptors, $pipes );
			$this->assertIsResource( $process );
			fclose( $pipes[0] );
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$processes[] = array( 'process' => $process, 'pipes' => $pipes, 'number' => $i );
		}

		$deadline = microtime( true ) + 8;
		while ( microtime( true ) < $deadline && ( ! file_exists( $barrier_dir . '/ready-1' ) || ! file_exists( $barrier_dir . '/ready-2' ) ) ) {
			usleep( 10000 );
		}
		$both_ready = file_exists( $barrier_dir . '/ready-1' ) && file_exists( $barrier_dir . '/ready-2' );
		touch( $barrier_dir . '/go' );

		foreach ( $processes as $index => $worker_process ) {
			$stdout = '';
			$stderr = '';
			$observed_exit_code = null;
			$worker_deadline = microtime( true ) + 8;
			do {
				$stdout .= stream_get_contents( $worker_process['pipes'][1] );
				$stderr .= stream_get_contents( $worker_process['pipes'][2] );
				$process_status = proc_get_status( $worker_process['process'] );
				if ( ! $process_status['running'] ) {
					$observed_exit_code = (int) $process_status['exitcode'];
					break;
				}
				usleep( 10000 );
			} while ( microtime( true ) < $worker_deadline );
			if ( isset( $process_status['running'] ) && $process_status['running'] ) {
				proc_terminate( $worker_process['process'] );
			}
			$stdout .= stream_get_contents( $worker_process['pipes'][1] );
			$stderr .= stream_get_contents( $worker_process['pipes'][2] );
			fclose( $worker_process['pipes'][1] );
			fclose( $worker_process['pipes'][2] );
			$exit_code = proc_close( $worker_process['process'] );
			if ( -1 === $exit_code && null !== $observed_exit_code ) {
				$exit_code = $observed_exit_code;
			}
			$results[] = array( 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exit_code );
			$diagnostics[] = sprintf(
				'worker=%d ready=%s exit=%d stdout=%s stderr=%s',
				$worker_process['number'],
				file_exists( $barrier_dir . '/ready-' . $worker_process['number'] ) ? 'yes' : 'no',
				$exit_code,
				trim( $stdout ),
				trim( $stderr )
			);
		}

		foreach ( glob( $barrier_dir . '/*' ) as $barrier_file ) {
			unlink( $barrier_file );
		}
		rmdir( $barrier_dir );

		$this->assertTrue( $both_ready, "Both independent workers must reach the claim barrier.\n" . implode( "\n", $diagnostics ) );
		$this->assertSame( array( 0, 0 ), array_column( $results, 'exit_code' ), implode( "\n", $diagnostics ) );
		$worker_results = array_map( static function ( $result ) { return json_decode( trim( $result['stdout'] ), true ); }, $results );
		$claimed_count = count( array_filter( $worker_results, static function ( $result ) { return isset( $result['claimed'] ) && true === $result['claimed']; } ) );
		$lost_count = count( array_filter( $worker_results, static function ( $result ) { return isset( $result['claimed'] ) && false === $result['claimed']; } ) );
		$this->assertSame( 1, $claimed_count, implode( "\n", $diagnostics ) );
		$this->assertSame( 1, $lost_count, implode( "\n", $diagnostics ) );
		$this->assertSame( '1', (string) $this->get_repository_event( $event_id )['claim_generation'] );
	}

	public function test_retry_uses_exponential_backoff_and_preserves_payload(): void {
		$payload = wp_json_encode( array( 'id' => 'retry-payload' ) );
		$event_id = $this->insert_repository_event( 'received', array( 'normalized_payload' => $payload ) );
		$claim = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$this->assertIsArray( $claim );
		$result = $this->repository->mark_event_retry( $event_id, (int) $claim['claim_generation'], 'square_api_unavailable' );
		$this->assertSame( 'retry', $result['status'] );
		$this->assertSame( 1, $result['retry_count'] );
		$this->assertGreaterThanOrEqual( 58, strtotime( $result['next_retry_at'] ) - time() );
		$this->assertLessThanOrEqual( 60, strtotime( $result['next_retry_at'] ) - time() );
		$row = $this->get_repository_event( $event_id );
		$this->assertSame( $payload, $row['normalized_payload'] );
		$this->assertSame( 'square_api_unavailable', $row['last_error_code'] );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->update( $this->event_table(), array( 'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
		$second_claim = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$this->assertSame( '2', (string) $second_claim['claim_generation'] );
		$second_result = $this->repository->mark_event_retry( $event_id, (int) $second_claim['claim_generation'], 'temporary_failure' );
		$this->assertSame( 2, $second_result['retry_count'] );
		$this->assertGreaterThanOrEqual( 118, strtotime( $second_result['next_retry_at'] ) - time() );
		$this->assertLessThanOrEqual( 120, strtotime( $second_result['next_retry_at'] ) - time() );

		for ( $retry_count = 3; $retry_count <= 4; $retry_count++ ) {
			$wpdb->update( $this->event_table(), array( 'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
			$next_claim = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
			$next_result = $this->repository->mark_event_retry( $event_id, (int) $next_claim['claim_generation'], 'temporary_failure' );
			$expected_delay = 60 * ( 2 ** ( $retry_count - 1 ) );
			$actual_delay = strtotime( $next_result['next_retry_at'] ) - time();
			$this->assertSame( $retry_count, $next_result['retry_count'] );
			$this->assertGreaterThanOrEqual( $expected_delay - 2, $actual_delay );
			$this->assertLessThanOrEqual( $expected_delay, $actual_delay );
		}
	}

	public function test_retry_limit_and_manual_reconciliation_transition(): void {
		$event_id = $this->insert_repository_event( 'received', array( 'retry_count' => 4 ) );
		$claim = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$last_retry = $this->repository->mark_event_retry( $event_id, (int) $claim['claim_generation'], 'temporary_failure' );
		$this->assertSame( 'retry', $last_retry['status'] );
		$this->assertSame( 5, $last_retry['retry_count'] );
		$this->assertGreaterThanOrEqual( 958, strtotime( $last_retry['next_retry_at'] ) - time() );
		$this->assertLessThanOrEqual( 960, strtotime( $last_retry['next_retry_at'] ) - time() );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->update( $this->event_table(), array( 'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
		$claim_after_limit = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
		$exhausted = $this->repository->mark_event_retry( $event_id, (int) $claim_after_limit['claim_generation'], 'still_failing' );
		$this->assertSame( 'reconciliation_required', $exhausted['status'] );
		$this->assertSame( 5, $exhausted['retry_count'] );
		$this->assertNull( $exhausted['next_retry_at'] );
		$this->assertSame( 'reconciliation_required', $this->get_repository_event( $event_id )['processing_status'] );

		$other_event_id = $this->insert_repository_event( 'received' );
		$other_claim = $this->repository->claim_event( $other_event_id, 'sandbox', $this->merchant_id );
		$this->assertTrue( $this->repository->mark_event_reconciliation_required( $other_event_id, (int) $other_claim['claim_generation'], 'manual_review_needed' ) );
		$this->assertSame( 'reconciliation_required', $this->get_repository_event( $other_event_id )['processing_status'] );
	}

	private function dispatch( $body, $signature ) {
		$request = new WP_REST_Request( 'POST', '/egov-law-monitor/v1/square-webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( '' !== $signature ) {
			$request->set_header( 'x-square-hmacsha256-signature', $signature );
		}
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	private function assert_webhook_status( $expected_status, $response ): void {
		$data = $response->get_data();
		$error_code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '(none)';
		$this->assertSame(
			$expected_status,
			$response->get_status(),
			sprintf( 'REST response code: %s; webhook config filter called: %s', $error_code, $this->config_filter_called ? 'yes' : 'no' )
		);
	}

	private function event_body() {
		$event_id = wp_generate_uuid4();
		$this->event_ids[] = $event_id;
		$this->event_scopes[ $event_id ] = array( $this->environment, $this->merchant_id );
		return wp_json_encode(
			array(
				'event_id'    => $event_id,
				'type'        => 'payment.updated',
				'merchant_id' => $this->merchant_id,
				'created_at'  => '2026-11-05T01:02:03Z',
				'data'        => array( 'id' => 'payment-test-id' ),
			)
		);
	}

	private function sign( $body ) {
		return base64_encode( hash_hmac( 'sha256', $this->notification_url . $body, $this->signature_key, true ) );
	}

	private function get_event( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_square_events';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE environment = 'sandbox' AND merchant_id = %s AND square_event_id = %s", $this->merchant_id, $event_id ), ARRAY_A );
	}

	private function count_event( $event_id, $environment = 'sandbox', $merchant_id = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_square_events';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_event_id = %s", $environment, null === $merchant_id ? $this->merchant_id : $merchant_id, $event_id ) );
	}

	private function insert_repository_event( $status, $overrides = array() ) {
		global $wpdb;
		$event_uuid = wp_generate_uuid4();
		$this->event_ids[] = $event_uuid;
		$environment = isset( $overrides['environment'] ) ? $overrides['environment'] : 'sandbox';
		$merchant_id = isset( $overrides['merchant_id'] ) ? $overrides['merchant_id'] : $this->merchant_id;
		$this->event_scopes[ $event_uuid ] = array( $environment, $merchant_id );
		$now = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'environment' => 'sandbox',
				'merchant_id' => $this->merchant_id,
				'square_event_id' => $event_uuid,
				'event_type' => 'subscription.created',
				'received_at' => $now,
				'processing_status' => $status,
				'retry_count' => 0,
				'next_retry_at' => null,
				'processing_started_at' => null,
				'claim_generation' => 0,
				'processed_at' => null,
				'payload_hash' => hash( 'sha256', $event_uuid ),
				'normalized_payload' => wp_json_encode( array( 'event_id' => $event_uuid, 'type' => 'subscription.created' ) ),
				'last_error_code' => '',
				'last_error_message' => null,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $this->event_table(), $data ) );
		return (int) $wpdb->insert_id;
	}

	private function get_repository_event( $event_row_id ) {
		global $wpdb;
		$table = $this->event_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_row_id ), ARRAY_A );
	}

	private function get_event_by_scope( $event_id, $environment, $merchant_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_square_events';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_event_id = %s", $environment, $merchant_id, $event_id ), ARRAY_A );
	}

	private function event_table() {
		global $wpdb;
		return $wpdb->prefix . 'egov_law_square_events';
	}
}
