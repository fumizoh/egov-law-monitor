<?php

use PHPUnit\Framework\TestCase;

final class SquareInitialPaymentReadServiceTest extends TestCase {
	private $wpdb;
	private $http_filter;
	private $http_calls = array();
	private $attempt_uuid;
	private $attempt_id;
	private $order;
	private $payment;
	private $subscription;
	private $invoice;
	private $response_status = 200;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->attempt_uuid = wp_generate_uuid4();
		$this->payment = array(
			'id' => 'initial-read-payment-' . wp_generate_uuid4(), 'order_id' => 'initial-read-order-' . wp_generate_uuid4(),
			'customer_id' => 'initial-read-customer', 'location_id' => 'initial-read-location', 'status' => 'COMPLETED',
			'created_at' => '2026-11-01T12:00:00Z', 'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'refund_ids' => array(),
			'refunded_money' => array( 'amount' => 0, 'currency' => 'JPY' ),
		);
		$this->order = array(
			'id' => $this->payment['order_id'], 'reference_id' => $this->attempt_uuid, 'location_id' => 'initial-read-location',
			'state' => 'OPEN', 'version' => 1, 'tenders' => array( array( 'payment_id' => $this->payment['id'] ) ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$this->subscription = array(
			'id' => 'initial-read-subscription', 'location_id' => 'initial-read-location', 'customer_id' => 'initial-read-customer',
			'plan_variation_id' => 'initial-read-plan', 'status' => 'ACTIVE', 'invoice_ids' => array( 'initial-read-invoice' ),
		);
		$this->invoice = array(
			'id' => 'initial-read-invoice', 'subscription_id' => $this->subscription['id'], 'location_id' => 'initial-read-location',
			'order_id' => $this->order['id'], 'status' => 'PAID',
		);
		$this->insert_attempt();
		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			if ( 200 !== $this->response_status ) {
				return array( 'headers' => array(), 'body' => wp_json_encode( array( 'errors' => array( array( 'code' => 'INTERNAL_SERVER_ERROR' ) ) ) ), 'response' => array( 'code' => $this->response_status, 'message' => 'mocked' ), 'cookies' => array(), 'filename' => null );
			}
			if ( false !== strpos( $url, '/v2/payments/' ) ) {
				$resource = array( 'payment' => $this->payment );
			} elseif ( false !== strpos( $url, '/v2/subscriptions/' ) ) {
				$resource = array( 'subscription' => $this->subscription );
			} elseif ( false !== strpos( $url, '/v2/invoices/' ) ) {
				$resource = array( 'invoice' => $this->invoice );
			} else {
				$resource = array( 'order' => $this->order );
			}
			return array( 'headers' => array(), 'body' => wp_json_encode( $resource ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'square_invoice_id' => $this->invoice['id'] ), array( '%s' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'checkout_attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_paid_initial_payment_is_kept_separate_from_unlinked_subscription_and_period(): void {
		$before_attempt = $this->attempt_row();
		$before_contracts = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions" );
		$before_invoices = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" );
		$roles_before = get_userdata( 1 )->roles;

		$result = $this->service()->inspect_attempt( $this->attempt_id );

		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'none_reported_at_lookup', $result['payment_evidence']['refund_status'] );
		$this->assertSame( 'unconfirmed', $result['subscription_link']['status'] );
		$this->assertSame( 'initial_checkout_order_only', $result['invoice_scope']['selection'] );
		$this->assertFalse( $result['billing_period']['verified'] );
		$this->assertNull( $result['billing_period']['start'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertSame( $before_attempt, $this->attempt_row() );
		$this->assertSame( $before_contracts, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions" ) );
		$this->assertSame( $before_invoices, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" ) );
		$this->assertSame( $roles_before, get_userdata( 1 )->roles );
	}

	public function test_existing_exact_subscription_link_is_verified_but_period_stays_unverified(): void {
		$this->insert_contract_link();
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'billing_period_provenance_unavailable', $result['reason_code'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'confirmed', $result['subscription_link']['status'] );
		$this->assertSame( $this->subscription['id'], $result['subscription_link']['subscription_id'] );
		$this->assertSame( 'initial-read-invoice', $result['invoice_scope']['invoice_id'] );
		$this->assertFalse( $result['billing_period']['verified'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
	}

	public function test_uncompleted_payment_is_not_confirmed(): void {
		$this->payment['status'] = 'APPROVED';
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'payment_pending', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
	}

	public function test_attempt_order_location_and_amount_mismatches_are_held(): void {
		$this->order['reference_id'] = wp_generate_uuid4();
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );

		$this->order['reference_id'] = $this->attempt_uuid;
		$this->order['total_money']['amount'] = 499;
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );

		$this->order['total_money']['amount'] = 500;
		$this->order['location_id'] = 'other-location';
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
	}

	public function test_payment_amount_and_currency_mismatches_are_held(): void {
		$this->payment['amount_money']['amount'] = 499;
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );

		$this->payment['amount_money'] = array( 'amount' => 500, 'currency' => 'USD' );
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
	}

	public function test_multiple_tenders_are_not_resolved_automatically(): void {
		$this->order['tenders'][] = array( 'payment_id' => $this->payment['id'] );
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
	}

	public function test_refund_suspicion_preserves_completed_payment_fact_but_requires_review(): void {
		$this->payment['refund_ids'] = array( 'refund-evidence' );
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'detected', $result['payment_evidence']['refund_status'] );
	}

	public function test_unknown_refund_state_preserves_payment_evidence_but_blocks_subscription_match(): void {
		unset( $this->payment['refund_ids'], $this->payment['refunded_money'] );
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'unknown', $result['payment_evidence']['refund_status'] );
	}

	public function test_api_error_is_not_reported_as_unpaid_or_successful(): void {
		$this->response_status = 503;
		$result = $this->service()->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'square_api_error', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
	}

	public function test_invalid_local_id_and_non_admin_are_rejected_without_network(): void {
		$this->assertWPError( $this->service()->inspect_attempt( 0 ) );
		wp_set_current_user( 0 );
		$this->assertWPError( $this->service()->inspect_attempt( $this->attempt_id ) );
		$this->assertCount( 0, $this->http_calls );
	}

	private function service(): Egov_Law_Monitor_Square_Initial_Payment_Read_Service {
		$config = array( 'environment' => 'sandbox', 'access_token' => 'mock-only-token', 'merchant_id' => 'initial-read-merchant', 'location_id' => 'initial-read-location', 'catalog_item_variation_id' => 'initial-read-item', 'subscription_plan_variation_id' => 'initial-read-plan' );
		$client = new Egov_Law_Monitor_Square_Api_Client( $config );
		$repository = new Egov_Law_Monitor_Checkout_Repository( $this->wpdb );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
		$payment_confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $payment_matcher );
		$subscription_matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $repository, $client, $payment_confirmation );
		return new Egov_Law_Monitor_Square_Initial_Payment_Read_Service( $this->wpdb, $repository, $client, $payment_confirmation, $subscription_matcher );
	}

	private function insert_attempt(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'initial-read-merchant',
			'square_location_id' => 'initial-read-location', 'plan_code' => 'standard', 'catalog_item_variation_id' => 'initial-read-item',
			'subscription_plan_variation_id' => 'initial-read-plan', 'square_customer_id' => 'initial-read-customer', 'status' => 'pending',
			'idempotency_key' => wp_generate_uuid4(), 'square_order_id' => $this->order['id'], 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->attempt_id = (int) $this->wpdb->insert_id;
	}

	private function insert_contract_link(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'initial-read-merchant', 'square_location_id' => 'initial-read-location',
			'square_customer_id' => 'initial-read-customer', 'square_subscription_id' => $this->subscription['id'], 'square_plan_variation_id' => 'initial-read-plan',
			'checkout_attempt_uuid' => $this->attempt_uuid, 'plan_code' => 'standard', 'internal_status' => 'pending', 'square_status' => 'ACTIVE',
			'initial_payment_status' => 'unknown', 'current_slot' => null, 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function attempt_row(): array {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_id ), ARRAY_A );
	}

	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}
}
