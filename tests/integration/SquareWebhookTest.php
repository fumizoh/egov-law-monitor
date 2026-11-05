<?php

use PHPUnit\Framework\TestCase;

final class SquareWebhookTest extends TestCase {
	private $config_filter;
	private $service_filter;
	private $config_filter_called = false;
	private $event_ids = array();
	private $merchant_id = 'webhook-test-merchant';
	private $signature_key = 'webhook-test-signature-key';
	private $notification_url = 'https://staging.example.test/wp-json/egov-law-monitor/v1/square-webhook';

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->config_filter = function ( $config ) {
			$this->config_filter_called = true;
			return array(
				'environment'      => 'sandbox',
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
				$wpdb->delete( $table, array( 'environment' => 'sandbox', 'merchant_id' => $this->merchant_id, 'square_event_id' => $event_id ), array( '%s', '%s', '%s' ) );
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
				public function insert( $table, $data, $formats ) {
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
}
