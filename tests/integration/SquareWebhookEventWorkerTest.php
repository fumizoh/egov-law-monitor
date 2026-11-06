<?php

use PHPUnit\Framework\TestCase;

/** Test double for the read-only subscription matcher. */
final class Egov_Law_Monitor_Test_Webhook_Subscription_Matcher extends Egov_Law_Monitor_Square_Subscription_Attempt_Matcher {
	public $calls = array();
	public $callback;

	public function __construct( $callback = null, $environment = 'sandbox', $merchant_id = 'worker-merchant' ) {
		global $wpdb;
		$client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => $environment, 'access_token' => 'mock-token', 'merchant_id' => $merchant_id,
			'location_id' => 'worker-location', 'catalog_item_variation_id' => 'worker-item',
			'subscription_plan_variation_id' => 'worker-plan',
		) );
		$repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
		$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $payment_matcher );
		parent::__construct( $repository, $client, $confirmation );
		$this->callback = $callback;
	}

	public function match_subscription( $subscription_id ) {
		$this->calls[] = $subscription_id;
		return is_callable( $this->callback ) ? call_user_func( $this->callback, $subscription_id ) : array( 'status' => 'subscription_matched' );
	}
}

final class SquareWebhookEventWorkerTest extends TestCase {
	private $repository;
	private $matcher;
	private $client;
	private $worker;
	private $event_ids = array();
	private $event_scopes = array();
	private $http_filter;
	private $http_calls = array();
	private $invoice_response;
	private $http_status = 200;
	private $environment = 'sandbox';
	private $merchant_id = 'worker-merchant';

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->repository = new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb );
		$this->client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => $this->environment, 'access_token' => 'mock-token', 'merchant_id' => $this->merchant_id,
			'location_id' => 'worker-location', 'catalog_item_variation_id' => 'worker-item',
			'subscription_plan_variation_id' => 'worker-plan',
		) );
		$this->matcher = new Egov_Law_Monitor_Test_Webhook_Subscription_Matcher();
		$this->worker = new Egov_Law_Monitor_Square_Webhook_Event_Worker( $this->repository, $this->client, $this->matcher );
		$this->invoice_response = array( 'id' => 'worker-invoice', 'subscription_id' => 'worker-subscription', 'location_id' => 'worker-location', 'order_id' => 'worker-order', 'status' => 'PAID' );
		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			if ( false === strpos( $url, '/v2/invoices/' ) ) {
				return new WP_Error( 'unexpected_mock_request', 'Unexpected request.' );
			}
			$body = $this->http_status >= 200 && $this->http_status < 300
				? array( 'invoice' => $this->invoice_response )
				: array( 'errors' => array( array( 'code' => 'UNAVAILABLE' ) ) );
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $this->http_status, 'message' => 'mock' ), 'cookies' => array(), 'filename' => null );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$table = $wpdb->prefix . 'egov_law_square_events';
			foreach ( $this->event_ids as $event_id ) {
				$scope = isset( $this->event_scopes[ $event_id ] ) ? $this->event_scopes[ $event_id ] : array( 'sandbox', 'worker-merchant' );
				$wpdb->delete( $table, array( 'environment' => $scope[0], 'merchant_id' => $scope[1], 'square_event_id' => $event_id ), array( '%s', '%s', '%s' ) );
			}
		}
		parent::tearDown();
	}

	public function test_subscription_created_and_updated_events_are_matched(): void {
		foreach ( array( 'subscription.created', 'subscription.updated' ) as $type ) {
			$event_id = $this->insert_event( $type, 'subscription', 'worker-subscription' );
			$result = $this->worker->process_batch( 1 );
			$this->assertSame( 1, $result['processed'] );
			$this->assertSame( 'processed', $this->event_row( $event_id )['processing_status'] );
		}
		$this->assertSame( array( 'worker-subscription', 'worker-subscription' ), $this->matcher->calls );
		$this->assertCount( 0, $this->http_calls );
	}

	public function test_invoice_payment_made_is_retrieved_before_matching_subscription(): void {
		$event_id = $this->insert_event( 'invoice.payment_made', 'invoice', 'worker-invoice' );
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'processed', $this->event_row( $event_id )['processing_status'] );
		$this->assertSame( array( 'worker-subscription' ), $this->matcher->calls );
		$this->assertSame( array( 'https://connect.squareupsandbox.com/v2/invoices/worker-invoice' ), $this->http_calls );
	}

	public function test_invoice_without_subscription_id_requires_reconciliation(): void {
		unset( $this->invoice_response['subscription_id'] );
		$event_id = $this->insert_event( 'invoice.payment_made', 'invoice', 'worker-invoice' );
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['reconciliation_required'] );
		$this->assertSame( 'reconciliation_required', $this->event_row( $event_id )['processing_status'] );
		$this->assertCount( 0, $this->matcher->calls );
	}

	public function test_unsupported_event_is_completed_without_square_or_matcher_calls(): void {
		$event_id = $this->insert_event( 'payment.updated', 'payment', 'worker-payment' );
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'processed', $this->event_row( $event_id )['processing_status'] );
		$this->assertCount( 0, $this->matcher->calls );
		$this->assertCount( 0, $this->http_calls );
	}

	public function test_missing_or_invalid_subscription_id_requires_reconciliation(): void {
		$missing_id = $this->insert_event( 'subscription.created', 'subscription', null );
		$invalid_id = $this->insert_event( 'subscription.updated', 'subscription', "bad\nvalue" );
		$result = $this->worker->process_batch( 2 );
		$this->assertSame( 2, $result['reconciliation_required'] );
		$this->assertSame( 'reconciliation_required', $this->event_row( $missing_id )['processing_status'] );
		$this->assertSame( 'reconciliation_required', $this->event_row( $invalid_id )['processing_status'] );
		$this->assertCount( 0, $this->matcher->calls );
	}

	public function test_matcher_pending_api_error_and_reconciliation_results_map_to_repository_states(): void {
		$this->matcher->callback = static function () { return array( 'status' => 'subscription_pending' ); };
		$pending_id = $this->insert_event( 'subscription.created', 'subscription', 'pending-subscription' );
		$pending = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $pending['retry'] );
		$this->assertSame( 'retry', $this->event_row( $pending_id )['processing_status'] );
		$this->assertSame( '1', (string) $this->event_row( $pending_id )['retry_count'] );

		$this->matcher->callback = static function () { return array( 'status' => 'square_api_error' ); };
		$api_error_id = $this->insert_event( 'subscription.updated', 'subscription', 'api-error-subscription' );
		$api_error = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $api_error['retry'] );
		$this->assertSame( 'retry', $this->event_row( $api_error_id )['processing_status'] );
		$this->assertSame( 'square_subscription_api_unavailable', $this->event_row( $api_error_id )['last_error_code'] );

		$this->matcher->callback = static function () { return array( 'status' => 'reconciliation_required' ); };
		$review_id = $this->insert_event( 'subscription.created', 'subscription', 'review-subscription' );
		$review = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $review['reconciliation_required'] );
		$this->assertSame( 'reconciliation_required', $this->event_row( $review_id )['processing_status'] );
	}

	public function test_invoice_api_error_is_retryable_and_does_not_call_matcher(): void {
		$this->http_status = 503;
		$event_id = $this->insert_event( 'invoice.payment_made', 'invoice', 'worker-invoice' );
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['retry'] );
		$this->assertSame( 'retry', $this->event_row( $event_id )['processing_status'] );
		$this->assertSame( 'invoice_api_unavailable', $this->event_row( $event_id )['last_error_code'] );
		$this->assertCount( 0, $this->matcher->calls );
	}

	public function test_sandbox_worker_ignores_production_and_other_merchant_events(): void {
		$production_id = $this->insert_event( 'subscription.created', 'subscription', 'production-subscription', array(), 'production', $this->merchant_id );
		$other_merchant_id = $this->insert_event( 'subscription.created', 'subscription', 'other-merchant-subscription', array(), 'sandbox', 'another-merchant' );
		$sandbox_id = $this->insert_event( 'subscription.created', 'subscription', 'sandbox-subscription' );

		$result = $this->worker->process_batch( 10 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( array( 'sandbox-subscription' ), $this->matcher->calls );
		$this->assertSame( 'received', $this->event_row( $production_id )['processing_status'] );
		$this->assertSame( 'received', $this->event_row( $other_merchant_id )['processing_status'] );
		$this->assertSame( 'processed', $this->event_row( $sandbox_id )['processing_status'] );
		$this->assertFalse( $this->repository->claim_event( $production_id, 'sandbox', $this->merchant_id ) );
		$this->assertFalse( $this->repository->claim_event( $other_merchant_id, 'sandbox', $this->merchant_id ) );
	}

	public function test_production_activation_accepts_only_boolean_true(): void {
		$this->assertTrue( egov_law_monitor_square_webhook_activation_value_enabled( true ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( false ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( 1 ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( 'true' ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( null ) );
	}

	public function test_production_worker_is_disabled_without_exact_true_constant(): void {
		if ( defined( 'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED' ) ) {
			$this->markTestSkipped( 'Production activation constant is defined by this test process.' );
		}
		$this->environment = 'production';
		$this->client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => 'production', 'access_token' => 'mock-token', 'merchant_id' => $this->merchant_id,
			'location_id' => 'worker-location', 'catalog_item_variation_id' => 'worker-item',
			'subscription_plan_variation_id' => 'worker-plan',
		) );
		$this->matcher = new Egov_Law_Monitor_Test_Webhook_Subscription_Matcher( null, 'production', $this->merchant_id );
		$this->worker = new Egov_Law_Monitor_Square_Webhook_Event_Worker( $this->repository, $this->client, $this->matcher );
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'production-subscription', array(), 'production', $this->merchant_id );
		$result = $this->worker->process_batch( 1 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_webhook_worker_environment_disabled', $result->get_error_code() );
		$this->assertSame( 'received', $this->event_row( $event_id )['processing_status'] );
		$this->assertCount( 0, $this->http_calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_production_worker_processes_only_production_events_when_explicitly_enabled(): void {
		$required_constants = array(
			'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED',
			'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED',
			'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT',
			'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_SIGNATURE_KEY_PRODUCTION',
			'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_NOTIFICATION_URL_PRODUCTION',
		);
		foreach ( $required_constants as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$this->markTestSkipped( 'A Production configuration constant is already defined in the isolated process.' );
			}
		}
		define( 'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED', true );
		define( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED', true );
		define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
		define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION', 'mock-production-token' );
		define( 'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_PRODUCTION', $this->merchant_id );
		define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION', 'worker-location' );
		define( 'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_PRODUCTION', 'worker-item' );
		define( 'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_PRODUCTION', 'worker-plan' );
		define( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_SIGNATURE_KEY_PRODUCTION', 'worker-signature' );
		define( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_NOTIFICATION_URL_PRODUCTION', 'https://example.test/wp-json/egov-law-monitor/v1/square-webhook' );
		$this->assertTrue( egov_law_monitor_square_webhook_worker_automation_enabled() );
		$this->environment = 'production';
		$this->client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => 'production', 'access_token' => 'mock-token', 'merchant_id' => $this->merchant_id,
			'location_id' => 'worker-location', 'catalog_item_variation_id' => 'worker-item',
			'subscription_plan_variation_id' => 'worker-plan',
		) );
		$this->matcher = new Egov_Law_Monitor_Test_Webhook_Subscription_Matcher( null, 'production', $this->merchant_id );
		$this->worker = new Egov_Law_Monitor_Square_Webhook_Event_Worker( $this->repository, $this->client, $this->matcher );
		$production_id = $this->insert_event( 'subscription.created', 'subscription', 'production-subscription', array(), 'production', $this->merchant_id );
		$sandbox_id = $this->insert_event( 'subscription.created', 'subscription', 'sandbox-subscription', array(), 'sandbox', $this->merchant_id );
		$result = $this->worker->process_batch( 10 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( array( 'production-subscription' ), $this->matcher->calls );
		$this->assertSame( 'processed', $this->event_row( $production_id )['processing_status'] );
		$this->assertSame( 'received', $this->event_row( $sandbox_id )['processing_status'] );
		$this->assertCount( 0, $this->http_calls );

		$factory_event_id = $this->insert_event( 'payment.updated', 'payment', 'production-payment', array(), 'production', $this->merchant_id );
		$factory_worker = egov_law_monitor_square_webhook_event_worker();
		$this->assertInstanceOf( Egov_Law_Monitor_Square_Webhook_Event_Worker::class, $factory_worker );
		$this->assertSame( 1, $factory_worker->process_batch( 1 )['processed'] );
		$this->assertSame( 'processed', $this->event_row( $factory_event_id )['processing_status'] );
	}

	public function test_unexpected_matcher_exception_becomes_safe_retry_without_exposing_details(): void {
		$this->matcher->callback = static function () {
			throw new RuntimeException( 'sensitive exception detail' );
		};
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'worker-subscription' );
		$result = $this->worker->process_batch( 1 );
		$row = $this->event_row( $event_id );
		$this->assertSame( 1, $result['retry'] );
		$this->assertSame( 'retry', $row['processing_status'] );
		$this->assertSame( 'webhook_worker_unexpected_error', $row['last_error_code'] );
		$this->assertStringNotContainsString( 'sensitive exception detail', wp_json_encode( $row ) );
	}

	public function test_retry_limit_moves_pending_event_to_reconciliation_required(): void {
		$this->matcher->callback = static function () { return array( 'status' => 'subscription_pending' ); };
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'pending-subscription', array( 'processing_status' => 'retry', 'retry_count' => 5, 'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['reconciliation_required'] );
		$row = $this->event_row( $event_id );
		$this->assertSame( 'reconciliation_required', $row['processing_status'] );
		$this->assertSame( '5', (string) $row['retry_count'] );
		$this->assertNull( $row['next_retry_at'] );
	}

	public function test_duplicate_and_multiple_events_are_handled_once_within_batch_limit(): void {
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'worker-subscription' );
		$duplicate = $this->make_payload( 'subscription.created', 'subscription', 'worker-subscription', $this->event_ids[0] );
		$inserted = $this->repository->insert_event( json_decode( $duplicate, true ), 'sandbox', 'worker-merchant', $duplicate );
		$this->assertTrue( $inserted['duplicate'] );
		$second_id = $this->insert_event( 'payment.updated', 'payment', 'payment-second' );
		$result = $this->worker->process_batch( 2 );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 'processed', $this->event_row( $event_id )['processing_status'] );
		$this->assertSame( 'processed', $this->event_row( $second_id )['processing_status'] );
		$this->assertSame( array( 'worker-subscription' ), $this->matcher->calls );
		$this->assertSame( 0, $this->worker->process_batch( 2 )['found'] );
		$this->assertSame( array( 'worker-subscription' ), $this->matcher->calls );
	}

	public function test_old_claim_generation_cannot_overwrite_new_worker_result(): void {
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'worker-subscription' );
		$this->matcher->callback = function () use ( $event_id ) {
			global $wpdb;
			$table = $wpdb->prefix . 'egov_law_square_events';
			$wpdb->update( $table, array( 'processing_started_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
			$new_claim = $this->repository->claim_event( $event_id, 'sandbox', $this->merchant_id );
			$this->assertSame( '2', (string) $new_claim['claim_generation'] );
			return array( 'status' => 'subscription_matched' );
		};
		$result = $this->worker->process_batch( 1 );
		$this->assertSame( 1, $result['lost_claims'] );
		$this->assertSame( 'processing', $this->event_row( $event_id )['processing_status'] );
		$this->assertSame( '2', (string) $this->event_row( $event_id )['claim_generation'] );
	}

	public function test_worker_does_not_change_checkout_or_entitlement_data_and_preserves_payload(): void {
		global $wpdb;
		$attempt_table = $wpdb->prefix . 'egov_law_checkout_attempts';
		$before_attempts = $wpdb->get_results( "SELECT * FROM {$attempt_table} ORDER BY id", ARRAY_A );
		$before_user_plan = get_user_meta( 1, 'egov_law_monitor_plan', true );
		$before_subscriptions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_subscriptions" );
		$before_invoices = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_subscription_invoices" );
		$event_id = $this->insert_event( 'subscription.created', 'subscription', 'worker-subscription' );
		$payload = $this->event_row( $event_id )['normalized_payload'];
		$this->worker->process_batch( 1 );
		$this->assertSame( $payload, $this->event_row( $event_id )['normalized_payload'] );
		$this->assertSame( $before_attempts, $wpdb->get_results( "SELECT * FROM {$attempt_table} ORDER BY id", ARRAY_A ) );
		$this->assertSame( $before_user_plan, get_user_meta( 1, 'egov_law_monitor_plan', true ) );
		$this->assertSame( $before_subscriptions, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_subscriptions" ) );
		$this->assertSame( $before_invoices, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_subscription_invoices" ) );
	}

	private function insert_event( $event_type, $resource_type, $resource_id, array $overrides = array(), $environment = 'sandbox', $merchant_id = 'worker-merchant' ): int {
		$event_id = 'worker-event-' . wp_generate_uuid4();
		$this->event_ids[] = $event_id;
		$this->event_scopes[ $event_id ] = array( $environment, $merchant_id );
		$payload = $this->make_payload( $event_type, $resource_type, $resource_id, $event_id, $merchant_id );
		$event = json_decode( $payload, true );
		$result = $this->repository->insert_event( $event, $environment, $merchant_id, $payload );
		$this->assertFalse( $result['duplicate'] );
		if ( ! empty( $overrides ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'egov_law_square_events', $overrides, array( 'square_event_id' => $event_id ), null, array( '%s' ) );
		}
		$row = $this->event_row_by_square_id( $event_id );
		return (int) $row['id'];
	}

	private function make_payload( $event_type, $resource_type, $resource_id, $event_id, $merchant_id = 'worker-merchant' ): string {
		$data = array( 'type' => $resource_type );
		if ( null !== $resource_id ) {
			$data['id'] = $resource_id;
			$data['object'][ $resource_type ] = array( 'id' => $resource_id );
		}
		return wp_json_encode( array( 'event_id' => $event_id, 'type' => $event_type, 'merchant_id' => $merchant_id, 'data' => $data ) );
	}

	private function event_row( $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_square_events WHERE id = %d", $id ), ARRAY_A );
		$this->assertIsArray( $row );
		return $row;
	}

	private function event_row_by_square_id( $square_event_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_square_events WHERE square_event_id = %s", $square_event_id ), ARRAY_A );
		$this->assertIsArray( $row );
		return $row;
	}
}
