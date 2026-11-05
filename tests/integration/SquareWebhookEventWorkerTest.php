<?php

use PHPUnit\Framework\TestCase;

/** Test double for the read-only subscription matcher. */
final class Egov_Law_Monitor_Test_Webhook_Subscription_Matcher extends Egov_Law_Monitor_Square_Subscription_Attempt_Matcher {
	public $calls = array();
	public $callback;

	public function __construct( $callback = null ) {
		global $wpdb;
		$client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => 'sandbox', 'access_token' => 'mock-token', 'merchant_id' => 'worker-merchant',
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
	private $http_filter;
	private $http_calls = array();
	private $invoice_response;
	private $http_status = 200;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->repository = new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb );
		$this->client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => 'sandbox', 'access_token' => 'mock-token', 'merchant_id' => 'worker-merchant',
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
				$wpdb->delete( $table, array( 'environment' => 'sandbox', 'merchant_id' => 'worker-merchant', 'square_event_id' => $event_id ), array( '%s', '%s', '%s' ) );
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
			$new_claim = $this->repository->claim_event( $event_id );
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

	private function insert_event( $event_type, $resource_type, $resource_id, array $overrides = array() ): int {
		$event_id = 'worker-event-' . wp_generate_uuid4();
		$this->event_ids[] = $event_id;
		$payload = $this->make_payload( $event_type, $resource_type, $resource_id, $event_id );
		$event = json_decode( $payload, true );
		$result = $this->repository->insert_event( $event, 'sandbox', 'worker-merchant', $payload );
		$this->assertFalse( $result['duplicate'] );
		if ( ! empty( $overrides ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'egov_law_square_events', $overrides, array( 'square_event_id' => $event_id ), null, array( '%s' ) );
		}
		$row = $this->event_row_by_square_id( $event_id );
		return (int) $row['id'];
	}

	private function make_payload( $event_type, $resource_type, $resource_id, $event_id ): string {
		$data = array( 'type' => $resource_type );
		if ( null !== $resource_id ) {
			$data['id'] = $resource_id;
			$data['object'][ $resource_type ] = array( 'id' => $resource_id );
		}
		return wp_json_encode( array( 'event_id' => $event_id, 'type' => $event_type, 'merchant_id' => 'worker-merchant', 'data' => $data ) );
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
