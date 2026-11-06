<?php

use PHPUnit\Framework\TestCase;

final class SquareSubscriptionSyncTest extends TestCase {
	private $attempt_uuid;
	private $webhook_event_ids = array();
	private $http_filter;
	private $http_calls = array();
	private $subscription;
	private $invoice;
	private $order;
	private $payment;
	private $scope;
	private $payment_response_count = 0;
	private $attempt_mutation_on_second_payment = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->attempt_uuid = wp_generate_uuid4();
		$this->scope = array(
			'environment' => 'sandbox',
			'merchant_id' => 'sync-merchant-' . wp_generate_password( 8, false ),
			'location_id' => 'sync-location-' . wp_generate_password( 8, false ),
			'catalog_item_variation_id' => 'sync-item-variation',
			'subscription_plan_variation_id' => 'sync-plan-variation',
		);
		$this->subscription = array(
			'id' => 'sync-sub-' . wp_generate_uuid4(),
			'location_id' => $this->scope['location_id'],
			'plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'customer_id' => 'sync-customer-' . wp_generate_password( 8, false ),
			'status' => 'ACTIVE',
			'start_date' => '2026-11-06',
			'timezone' => 'Asia/Tokyo',
			'invoice_ids' => array( 'sync-invoice-' . wp_generate_uuid4() ),
			'version' => 2,
		);
		$this->invoice = array(
			'id' => $this->subscription['invoice_ids'][0],
			'subscription_id' => $this->subscription['id'],
			'location_id' => $this->scope['location_id'],
			'order_id' => 'sync-order-' . wp_generate_uuid4(),
			'status' => 'PAID',
			'version' => 4,
			'timezone' => 'Asia/Tokyo',
			'payment_requests' => array(
				array(
					'uid' => 'request-1',
					'request_type' => 'BALANCE',
					'due_date' => '2026-11-06',
					'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
					'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
				),
			),
		);
		$this->order = array(
			'id' => $this->invoice['order_id'],
			'reference_id' => $this->attempt_uuid,
			'location_id' => $this->scope['location_id'],
			'state' => 'COMPLETED',
			'tenders' => array( array( 'payment_id' => 'sync-payment-' . wp_generate_uuid4() ) ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$this->payment = array(
			'id' => $this->order['tenders'][0]['payment_id'],
			'order_id' => $this->order['id'],
			'customer_id' => $this->subscription['customer_id'],
			'location_id' => $this->scope['location_id'],
			'status' => 'COMPLETED',
			'created_at' => '2026-11-06T00:00:00Z',
			'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$this->insert_attempt();

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			if ( false !== strpos( $url, '/v2/subscriptions/' ) ) {
				$body = array( 'subscription' => $this->subscription );
			} elseif ( false !== strpos( $url, '/v2/invoices/' ) ) {
				$body = array( 'invoice' => $this->invoice );
			} elseif ( false !== strpos( $url, '/v2/orders/' ) ) {
				$body = array( 'order' => $this->order );
			} elseif ( false !== strpos( $url, '/v2/payments/' ) ) {
				++$this->payment_response_count;
				if ( 2 === $this->payment_response_count && is_array( $this->attempt_mutation_on_second_payment ) ) {
					global $wpdb;
					$wpdb->update( $wpdb->prefix . 'egov_law_checkout_attempts', $this->attempt_mutation_on_second_payment, array( 'attempt_uuid' => $this->attempt_uuid ) );
					$this->attempt_mutation_on_second_payment = null;
				}
				$body = array( 'payment' => $this->payment );
			} else {
				$body = array();
			}
			return array(
				'headers' => array(),
				'body' => wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'mocked' ),
				'cookies' => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$attempts = $wpdb->prefix . 'egov_law_checkout_attempts';
			$events = $wpdb->prefix . 'egov_law_square_events';
			$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
			$invoices = $wpdb->prefix . 'egov_law_subscription_invoices';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$subscriptions} WHERE checkout_attempt_uuid = %s", $this->attempt_uuid ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$wpdb->delete( $invoices, array( 'subscription_id' => (int) $row['id'] ), array( '%d' ) );
				$wpdb->delete( $subscriptions, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			}
			$wpdb->delete( $invoices, array( 'environment' => $this->scope['environment'], 'merchant_id' => $this->scope['merchant_id'] ), array( '%s', '%s' ) );
			$wpdb->delete( $subscriptions, array( 'environment' => $this->scope['environment'], 'merchant_id' => $this->scope['merchant_id'] ), array( '%s', '%s' ) );
			$wpdb->delete( $attempts, array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			foreach ( $this->webhook_event_ids as $event_id ) {
				$wpdb->delete( $events, array( 'environment' => $this->scope['environment'], 'merchant_id' => $this->scope['merchant_id'], 'square_event_id' => $event_id ), array( '%s', '%s', '%s' ) );
			}
		}
		parent::tearDown();
	}

	public function test_verified_subscription_and_invoice_are_saved_without_granting_standard(): void {
		$result = $this->service()->sync_subscription( $this->subscription['id'] );
		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'verified_snapshot_saved', $result['reason_code'] );
		$subscription = $this->subscription_row();
		$invoice = $this->invoice_row( $subscription['id'] );
		$this->assertSame( 1, (int) $subscription['user_id'] );
		$this->assertSame( $this->attempt_uuid, $subscription['checkout_attempt_uuid'] );
		$this->assertSame( $this->subscription['id'], $subscription['square_subscription_id'] );
		$this->assertSame( $this->payment['id'], $invoice['square_payment_id'] );
		$this->assertSame( 500, (int) $invoice['amount'] );
		$this->assertSame( 500, (int) $invoice['completed_amount'] );
		$this->assertSame( 'JPY', $invoice['currency'] );
		$this->assertSame( 'pending', $subscription['internal_status'] );
		$this->assertSame( 'succeeded', $subscription['initial_payment_status'] );
		$this->assertSame( null, $subscription['current_slot'] );
		$this->assertSame( null, $subscription['current_period_end'] );
		$this->assertSame( 'unknown', $subscription['sync_state'] );
		$this->assertSame( 'unknown', $invoice['payment_status'] );
		$snapshot = ( new Egov_Law_Monitor_Subscription_Repository( $this->wpdb() ) )->get_entitlement_snapshot( 1, 'sandbox' );
		$decision = egov_law_monitor_resolve_entitlement( $snapshot, new DateTimeImmutable( '2026-11-06T12:00:00Z' ) );
		$this->assertSame( 'free', $decision['effective_plan'] );
	}

	public function test_reprocessing_same_subscription_and_invoice_is_idempotent(): void {
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 'unchanged', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscriptions' ) );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscription_invoices' ) );
	}

	public function test_newer_square_versions_update_only_square_snapshot_fields(): void {
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->subscription['version'] = 3;
		$this->invoice['version'] = 5;
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$subscription = $this->subscription_row();
		$invoice = $this->invoice_row( $subscription['id'] );
		$this->assertSame( 3, (int) $subscription['square_version'] );
		$this->assertSame( 5, (int) $invoice['square_version'] );
		$this->assertSame( 'pending', $subscription['internal_status'] );
		$this->assertSame( 'unknown', $subscription['sync_state'] );
		$this->assertSame( null, $subscription['current_slot'] );
		$this->assertSame( 'unknown', $invoice['payment_status'] );
	}

	public function test_older_versions_do_not_overwrite_existing_rows(): void {
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->subscription['version'] = 1;
		$this->invoice['version'] = 3;
		$result = $this->service()->sync_subscription( $this->subscription['id'] );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 2, (int) $this->subscription_row()['square_version'] );
		$this->assertSame( 4, (int) $this->invoice_row( $this->subscription_row()['id'] )['square_version'] );
	}

	public function test_same_version_with_conflicting_content_is_held(): void {
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->subscription['status'] = 'PAUSED';
		$result = $this->service()->sync_subscription( $this->subscription['id'] );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'ACTIVE', $this->subscription_row()['square_status'] );
	}

	public function test_null_versions_allow_new_row_but_do_not_authorize_conflicting_update(): void {
		// Square represents an unavailable optional version by omitting the field;
		// the API client normalizes that omission to NULL for persistence.
		unset( $this->subscription['version'], $this->invoice['version'] );
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertNull( $this->subscription_row()['square_version'] );
		$this->assertNull( $this->invoice_row( $this->subscription_row()['id'] )['square_version'] );
		$this->assertSame( 'unchanged', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->subscription['status'] = 'PAUSED';
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 'ACTIVE', $this->subscription_row()['square_status'] );
	}

	public function test_unknown_square_subscription_status_is_kept_as_raw_non_current_snapshot(): void {
		$this->subscription['status'] = 'FUTURE_STATUS';
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$row = $this->subscription_row();
		$this->assertSame( 'FUTURE_STATUS', $row['square_status'] );
		$this->assertSame( 'pending', $row['internal_status'] );
		$this->assertNull( $row['current_slot'] );
	}

	public function test_attempt_uuid_conflict_is_not_reassigned(): void {
		$this->insert_conflicting_subscription( array( 'checkout_attempt_uuid' => $this->attempt_uuid ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_subscription_id_conflict_with_another_user_is_not_overwritten(): void {
		$this->insert_conflicting_subscription( array( 'square_subscription_id' => $this->subscription['id'], 'user_id' => 999999 ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 999999, (int) $this->subscription_row_by_square_id()['user_id'] );
	}

	public function test_invoice_id_conflict_is_not_reassigned(): void {
		$other_subscription_id = $this->insert_conflicting_subscription();
		$this->insert_invoice( $other_subscription_id, array( 'square_invoice_id' => $this->invoice['id'] ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_payment_id_conflict_is_not_reassigned(): void {
		$other_subscription_id = $this->insert_conflicting_subscription();
		$this->insert_invoice( $other_subscription_id, array( 'square_payment_id' => $this->payment['id'] ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
	}

	public function test_another_current_contract_causes_hold_without_replacement(): void {
		$this->insert_conflicting_subscription( array( 'user_id' => 1, 'current_slot' => 1 ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_production_attempt_does_not_match_sandbox_scope(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'environment' => 'production' ), array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ), array( '%s' ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_missing_user_attempt_is_not_saved(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'user_id' => 999999 ), array( 'attempt_uuid' => $this->attempt_uuid ), array( '%d' ), array( '%s' ) );
		$this->assertSame( 'reconciliation_required', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_missing_money_partial_payment_multiple_payments_and_unknown_status_are_held(): void {
		$cases = array(
			function () { unset( $this->invoice['payment_requests'][0]['computed_amount_money'] ); },
			function () { $this->invoice['payment_requests'][0]['total_completed_amount_money']['amount'] = 250; },
			function () { $this->invoice['payment_requests'][] = $this->invoice['payment_requests'][0]; },
			function () { $this->invoice['status'] = 'FUTURE_STATUS'; },
		);
		foreach ( $cases as $case ) {
			$this->reset_snapshots();
			$case();
			$result = $this->service()->sync_subscription( $this->subscription['id'] );
			$this->assertContains( $result['status'], array( 'pending', 'reconciliation_required' ) );
			$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
		}
	}

	public function test_wrong_amount_or_currency_and_multiple_checkout_tenders_are_not_saved(): void {
		$cases = array(
			function () { $this->invoice['payment_requests'][0]['computed_amount_money']['amount'] = 499; },
			function () { $this->invoice['payment_requests'][0]['computed_amount_money']['currency'] = 'USD'; },
			function () { $this->order['tenders'][] = array( 'payment_id' => 'another-payment-id' ); },
		);
		foreach ( $cases as $case ) {
			$this->reset_snapshots();
			$case();
			$result = $this->service()->sync_subscription( $this->subscription['id'] );
			$this->assertSame( 'reconciliation_required', $result['status'] );
			$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
		}
	}

	public function test_square_api_error_is_retryable_and_does_not_write(): void {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		$this->http_filter = static function () {
			return new WP_Error( 'mock_transport_error', 'private response omitted' );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
		$result = $this->service()->sync_subscription( $this->subscription['id'] );
		$this->assertSame( 'retry', $result['status'] );
		$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
	}

	public function test_database_failure_rolls_back_both_rows(): void {
		global $wpdb;
		$database = new Egov_Law_Monitor_Square_Contract_Test_Failing_Invoice_DB( $wpdb );
		$repository = new Egov_Law_Monitor_Square_Subscription_Contract_Repository( $database );
		$result = $this->service( $repository )->sync_subscription( $this->subscription['id'] );
		$this->assertSame( 'retry', $result['status'] );
		$this->assertTrue( $database->subscription_insert_succeeded, 'Subscription INSERT should occur before the injected Invoice failure.' );
		$this->assertTrue( $database->invoice_insert_failed, 'The test must reach the injected Invoice INSERT failure.' );
		$this->assertTrue( $database->rollback_called, 'The repository should roll back after the Invoice INSERT failure.' );
		$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
		$this->assertSame( 0, $this->count_rows( 'egov_law_subscription_invoices' ) );
	}

	public function test_attempt_and_unrelated_entitlement_fields_remain_unchanged(): void {
		global $wpdb;
		$attempts = $wpdb->prefix . 'egov_law_checkout_attempts';
		$before_attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts} WHERE attempt_uuid = %s", $this->attempt_uuid ), ARRAY_A );
		$this->assertSame( 'saved', $this->service()->sync_subscription( $this->subscription['id'] )['status'] );
		$after_attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts} WHERE attempt_uuid = %s", $this->attempt_uuid ), ARRAY_A );
		$this->assertSame( $before_attempt, $after_attempt );
		$row = $this->subscription_row();
		$this->assertNull( $row['current_period_start'] );
		$this->assertNull( $row['current_period_end'] );
		$this->assertNull( $row['first_payment_failed_at'] );
		$this->assertNull( $row['grace_ends_at'] );
		$this->assertSame( 'unknown', $row['sync_state'] );
	}

	public function test_attempt_identity_is_rechecked_after_matcher_before_persisting(): void {
		global $wpdb;
		foreach ( array(
			array( 'user_id' => 2 ),
			array( 'environment' => 'production' ),
			array( 'merchant_id' => 'different-merchant' ),
			array( 'square_location_id' => 'different-location' ),
			array( 'square_customer_id' => 'different-customer' ),
			array( 'square_order_id' => 'different-order' ),
			array( 'subscription_plan_variation_id' => 'different-plan' ),
		) as $mutation ) {
			$wpdb->update(
				$wpdb->prefix . 'egov_law_checkout_attempts',
				array(
					'user_id' => 1,
					'environment' => $this->scope['environment'],
					'merchant_id' => $this->scope['merchant_id'],
					'square_location_id' => $this->scope['location_id'],
					'square_customer_id' => $this->subscription['customer_id'],
					'square_order_id' => $this->invoice['order_id'],
					'subscription_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
				),
				array( 'attempt_uuid' => $this->attempt_uuid )
			);
			$this->payment_response_count = 0;
			$this->attempt_mutation_on_second_payment = $mutation;
			$result = $this->service()->sync_subscription( $this->subscription['id'] );
			$this->assertSame( 'reconciliation_required', $result['status'] );
			$this->assertSame( 0, $this->count_rows( 'egov_law_subscriptions' ) );
		}
	}

	public function test_webhook_worker_saves_once_for_duplicate_subscription_events_and_keeps_entitlement_free(): void {
		global $wpdb;
		$client = $this->client();
		$checkout_repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $checkout_repository, $client );
		$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $checkout_repository, $client, $payment_matcher );
		$matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $checkout_repository, $client, $confirmation );
		$sync_service = new Egov_Law_Monitor_Square_Subscription_Sync_Service( new Egov_Law_Monitor_Square_Subscription_Contract_Repository( $wpdb ), $client, $matcher );
		$event_repository = new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb );
		$worker = new Egov_Law_Monitor_Square_Webhook_Event_Worker( $event_repository, $client, $sync_service );
		$attempts_table = $wpdb->prefix . 'egov_law_checkout_attempts';
		$attempt_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE attempt_uuid = %s", $this->attempt_uuid ), ARRAY_A );

		foreach ( array( 'subscription.created', 'subscription.updated' ) as $event_type ) {
			$event_id = 'sync-worker-event-' . wp_generate_uuid4();
			$this->webhook_event_ids[] = $event_id;
			$payload = wp_json_encode( array(
				'event_id' => $event_id,
				'type' => $event_type,
				'merchant_id' => $this->scope['merchant_id'],
				'data' => array( 'type' => 'subscription', 'id' => $this->subscription['id'], 'object' => array( 'subscription' => array( 'id' => $this->subscription['id'] ) ) ),
			) );
			$saved_event = $event_repository->insert_event( json_decode( $payload, true ), $this->scope['environment'], $this->scope['merchant_id'], $payload );
			$this->assertFalse( $saved_event['duplicate'] );
		}

		$result = $worker->process_batch( 2 );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscriptions' ) );
		$this->assertSame( 1, $this->count_rows( 'egov_law_subscription_invoices' ) );
		$this->assertSame( $attempt_before, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE attempt_uuid = %s", $this->attempt_uuid ), ARRAY_A ) );
		$snapshot = ( new Egov_Law_Monitor_Subscription_Repository( $wpdb ) )->get_entitlement_snapshot( 1, 'sandbox' );
		$decision = egov_law_monitor_resolve_entitlement( $snapshot, new DateTimeImmutable( '2026-11-06T12:00:00Z' ) );
		$this->assertSame( 'free', $decision['effective_plan'] );
		$this->assertSame( 'pending', $this->subscription_row()['internal_status'] );
		$this->assertNull( $this->subscription_row()['current_slot'] );
		$this->assertSame( 'unknown', $this->subscription_row()['sync_state'] );
	}

	private function service( Egov_Law_Monitor_Square_Subscription_Contract_Repository $repository = null, Egov_Law_Monitor_Square_Api_Client $client = null ) {
		$client = $client ?: $this->client();
		$checkout_repository = new Egov_Law_Monitor_Checkout_Repository( $this->wpdb() );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $checkout_repository, $client );
		$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $checkout_repository, $client, $payment_matcher );
		$matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $checkout_repository, $client, $confirmation );
		return new Egov_Law_Monitor_Square_Subscription_Sync_Service( $repository ?: new Egov_Law_Monitor_Square_Subscription_Contract_Repository( $this->wpdb() ), $client, $matcher );
	}

	private function insert_attempt(): void {
		global $wpdb;
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid,
			'user_id' => 1,
			'environment' => $this->scope['environment'],
			'merchant_id' => $this->scope['merchant_id'],
			'square_location_id' => $this->scope['location_id'],
			'plan_code' => 'standard',
			'catalog_item_variation_id' => $this->scope['catalog_item_variation_id'],
			'subscription_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'status' => 'pending',
			'idempotency_key' => wp_generate_uuid4(),
			'square_order_id' => $this->invoice['order_id'],
			'square_customer_id' => $this->subscription['customer_id'],
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function insert_conflicting_subscription( array $overrides = array() ): int {
		global $wpdb;
		$data = array_merge( array(
			'user_id' => 2,
			'environment' => 'sandbox',
			'merchant_id' => $this->scope['merchant_id'],
			'square_location_id' => $this->scope['location_id'],
			'square_customer_id' => 'other-customer',
			'square_subscription_id' => 'other-sub-' . wp_generate_uuid4(),
			'square_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'checkout_attempt_uuid' => null,
			'square_version' => 1,
			'plan_code' => 'standard',
			'internal_status' => 'pending',
			'square_status' => 'ACTIVE',
			'initial_payment_status' => 'unknown',
			'current_slot' => null,
			'sync_state' => 'unknown',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		), $overrides );
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscriptions', $data ) );
		return (int) $wpdb->insert_id;
	}

	private function insert_invoice( $subscription_id, array $overrides = array() ): void {
		global $wpdb;
		$data = array_merge( array(
			'subscription_id' => $subscription_id,
			'user_id' => 2,
			'environment' => 'sandbox',
			'merchant_id' => $this->scope['merchant_id'],
			'square_invoice_id' => 'other-invoice-' . wp_generate_uuid4(),
			'square_payment_id' => null,
			'square_version' => 1,
			'invoice_status' => 'PAID',
			'square_status' => 'PAID',
			'payment_status' => 'unknown',
			'amount' => 500,
			'completed_amount' => 500,
			'currency' => 'JPY',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		), $overrides );
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscription_invoices', $data ) );
	}

	private function reset_snapshots(): void {
		$this->subscription = array_merge( $this->subscription, array(
			'id' => $this->subscription['id'], 'location_id' => $this->scope['location_id'],
			'plan_variation_id' => $this->scope['subscription_plan_variation_id'], 'customer_id' => $this->subscription['customer_id'],
			'status' => 'ACTIVE', 'version' => 2, 'invoice_ids' => array( $this->invoice['id'] ), 'start_date' => '2026-11-06', 'timezone' => 'Asia/Tokyo',
		) );
		$this->invoice['status'] = 'PAID';
		$this->invoice['payment_requests'] = array( array(
			'uid' => 'request-1', 'request_type' => 'BALANCE', 'due_date' => '2026-11-06',
			'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		) );
	}

	private function subscription_row(): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_subscriptions WHERE environment = %s AND merchant_id = %s AND square_subscription_id = %s", $this->scope['environment'], $this->scope['merchant_id'], $this->subscription['id'] ), ARRAY_A );
	}

	private function subscription_row_by_square_id(): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_subscriptions WHERE environment = %s AND merchant_id = %s AND square_subscription_id = %s", $this->scope['environment'], $this->scope['merchant_id'], $this->subscription['id'] ), ARRAY_A );
	}

	private function invoice_row( $subscription_id ): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_subscription_invoices WHERE environment = %s AND merchant_id = %s AND square_invoice_id = %s", $this->scope['environment'], $this->scope['merchant_id'], $this->invoice['id'] ), ARRAY_A );
	}

	private function count_rows( $suffix ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$suffix} WHERE merchant_id = '" . esc_sql( $this->scope['merchant_id'] ) . "'" );
	}

	private function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	private function client(): Egov_Law_Monitor_Square_Api_Client {
		return new Egov_Law_Monitor_Square_Api_Client( array_merge( $this->scope, array( 'access_token' => 'integration-test-token' ) ) );
	}
}

/** Delegates the test database except for a deterministic invoice insert failure. */
final class Egov_Law_Monitor_Square_Contract_Test_Failing_Invoice_DB {
	private $inner;
	public $fail_invoice_insert = true;
	public $subscription_insert_succeeded = false;
	public $invoice_insert_failed = false;
	public $rollback_called = false;

	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	public function __get( $name ) {
		return $this->inner->$name;
	}

	public function __set( $name, $value ) {
		$this->inner->$name = $value;
	}

	public function __call( $name, $arguments ) {
		return call_user_func_array( array( $this->inner, $name ), $arguments );
	}

	public function query( $query ) {
		if ( 'ROLLBACK' === strtoupper( trim( $query ) ) ) {
			$this->rollback_called = true;
		}
		return $this->inner->query( $query );
	}

	public function insert( $table, $data, $format = null ) {
		if ( $this->fail_invoice_insert && false !== strpos( $table, 'egov_law_subscription_invoices' ) ) {
			$this->invoice_insert_failed = true;
			$this->inner->last_error = 'deterministic test insert failure';
			return false;
		}
		$result = $this->inner->insert( $table, $data, $format );
		if ( 1 === $result && false !== strpos( $table, 'egov_law_subscriptions' ) ) {
			$this->subscription_insert_succeeded = true;
		}
		return $result;
	}
}
