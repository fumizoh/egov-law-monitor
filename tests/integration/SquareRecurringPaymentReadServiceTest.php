<?php

use PHPUnit\Framework\TestCase;

final class SquareRecurringPaymentReadServiceTest extends TestCase {
	private $wpdb;
	private $scope;
	private $contract_id;
	private $subscription;
	private $invoice;
	private $order;
	private $payment;
	private $http_filter;
	private $http_calls = array();
	private $fail_path = null;
	private $missing_path = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->scope = array(
			'environment' => 'sandbox',
			'merchant_id' => 'read-merchant-' . wp_generate_uuid4(),
			'location_id' => 'read-location-' . wp_generate_uuid4(),
			'catalog_item_variation_id' => 'read-catalog-variation',
			'subscription_plan_variation_id' => 'read-plan-variation',
		);
		$this->subscription = array(
			'id' => 'read-subscription-' . wp_generate_uuid4(),
			'location_id' => $this->scope['location_id'],
			'plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'customer_id' => 'read-customer-' . wp_generate_uuid4(),
			'status' => 'ACTIVE',
			'invoice_ids' => array( 'read-invoice-' . wp_generate_uuid4() ),
			'charged_through_date' => '2026-12-05',
			'version' => 4,
		);
		$this->invoice = array(
			'id' => $this->subscription['invoice_ids'][0],
			'subscription_id' => $this->subscription['id'],
			'location_id' => $this->scope['location_id'],
			'order_id' => 'read-order-' . wp_generate_uuid4(),
			'status' => 'PAID',
			'version' => 2,
			'payment_requests' => array( array(
				'uid' => 'request-1', 'request_type' => 'BALANCE', 'due_date' => '2026-12-05',
				'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
				'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			) ),
		);
		$this->order = array(
			'id' => $this->invoice['order_id'], 'location_id' => $this->scope['location_id'], 'state' => 'COMPLETED',
			'tenders' => array( array( 'payment_id' => 'read-payment-' . wp_generate_uuid4() ) ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$this->payment = array(
			'id' => $this->order['tenders'][0]['payment_id'], 'order_id' => $this->order['id'],
			'customer_id' => $this->subscription['customer_id'], 'location_id' => $this->scope['location_id'],
			'status' => 'COMPLETED', 'created_at' => '2026-12-05T00:00:00Z',
			'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'refund_ids' => array(), 'refunded_money' => array( 'amount' => 0, 'currency' => 'JPY' ),
		);
		$this->contract_id = $this->insert_contract();
		$this->insert_local_invoice();
		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			if ( null !== $this->missing_path && false !== strpos( $url, $this->missing_path ) ) {
				return array(
					'headers' => array(), 'body' => wp_json_encode( array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ) ),
					'response' => array( 'code' => 404, 'message' => 'mocked' ), 'cookies' => array(), 'filename' => null,
				);
			}
			if ( null !== $this->fail_path && false !== strpos( $url, $this->fail_path ) ) {
				return array(
					'headers' => array(), 'body' => wp_json_encode( array( 'errors' => array( array( 'code' => 'TEMPORARY_ERROR' ) ) ) ),
					'response' => array( 'code' => 503, 'message' => 'mocked' ), 'cookies' => array(), 'filename' => null,
				);
			}
			$body = false !== strpos( $url, '/v2/subscriptions/' ) ? array( 'subscription' => $this->subscription )
				: ( false !== strpos( $url, '/v2/invoices/' ) ? array( 'invoice' => $this->invoice )
				: ( false !== strpos( $url, '/v2/orders/' ) ? array( 'order' => $this->order ) : array( 'payment' => $this->payment ) ) );
			return array(
				'headers' => array(), 'body' => wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'mocked' ), 'cookies' => array(), 'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' && $this->contract_id ) {
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $this->contract_id ), array( '%d' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $this->contract_id ), array( '%d' ) );
		}
		parent::tearDown();
	}

	public function test_completed_payment_evidence_is_preserved_while_period_requires_review_and_reads_are_read_only(): void {
		$contract_before = $this->contract_row();
		$invoice_before = $this->invoice_row();
		$entitlement_before = egov_law_monitor_entitlement_service()->resolve_for_user( (int) $contract_before['user_id'] );

		$result = $this->service()->inspect_contract( $this->contract_id );

		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'billing_period_provenance_unavailable', $result['reason_code'] );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'none_reported_at_lookup', $result['payment_evidence']['refund_status'] );
		$this->assertSame( 'required', $result['review_status'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertSame( $this->invoice['id'], $result['invoice']['id'] );
		$this->assertSame( $this->payment['id'], $result['payment']['id'] );
		$this->assertSame( 500, $result['payment']['amount'] );
		$this->assertSame( 'JPY', $result['payment']['currency'] );
		$this->assertSame( '2026-12-01 00:00:00', $result['billing_period']['start'] );
		$this->assertSame( '2026-12-31 23:59:59', $result['billing_period']['end'] );
		$this->assertFalse( $result['billing_period']['verified'] );
		$this->assertSame( 'period_provenance_not_recorded', $result['billing_period']['evidence_code'] );
		$this->assertSame( 'latest_invoice_only', $result['invoice_scope']['selection'] );
		$this->assertSame( 1, $result['invoice_scope']['invoice_total_count'] );
		$this->assertSame( 1, $result['invoice_scope']['checked_invoice_count'] );
		$this->assertSame( 0, $result['invoice_scope']['unchecked_invoice_count'] );
		$this->assertFalse( $result['invoice_scope']['contract_wide_payment_status_confirmed'] );
		$this->assertSame( $contract_before, $this->contract_row() );
		$this->assertSame( $invoice_before, $this->invoice_row() );
		$this->assertSame( $entitlement_before, egov_law_monitor_entitlement_service()->resolve_for_user( (int) $contract_before['user_id'] ) );
	}

	public function test_latest_paid_invoice_does_not_claim_older_invoices_were_checked(): void {
		$this->subscription['invoice_ids'][] = 'older-invoice-not-requested';
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertSame( 2, $result['invoice_scope']['invoice_total_count'] );
		$this->assertSame( 1, $result['invoice_scope']['checked_invoice_count'] );
		$this->assertSame( 1, $result['invoice_scope']['unchecked_invoice_count'] );
		$this->assertSame( 'not_independently_verified', $result['invoice_scope']['invoice_list_completeness'] );
		$this->assertFalse( $result['invoice_scope']['contract_wide_payment_status_confirmed'] );
		$this->assertCount( 4, $this->http_calls );
		$this->assertStringNotContainsString( 'older-invoice-not-requested', implode( "\n", $this->http_calls ) );
	}

	public function test_subscription_identity_and_configured_scope_mismatches_are_held(): void {
		$this->subscription['customer_id'] = 'another-customer';
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'subscription_customer_id_mismatch', $result['reason_code'] );

		$this->subscription['customer_id'] = $this->contract_row()['square_customer_id'];
		$this->wpdb->update( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'square_location_id' => 'different-location' ), array( 'id' => $this->contract_id ), array( '%s' ), array( '%d' ) );
		$this->http_calls = array();
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'configured_scope_mismatch', $result['reason_code'] );
		$this->assertSame( array(), $this->http_calls );
	}

	public function test_subscription_id_location_and_plan_variation_mismatches_are_held(): void {
		$this->subscription['id'] = 'different-subscription';
		$this->assertSame( 'reconciliation_required', $this->service()->inspect_contract( $this->contract_id )['status'] );

		$this->subscription['id'] = $this->contract_row()['square_subscription_id'];
		$this->subscription['location_id'] = 'different-location';
		$this->assertSame( 'subscription_location_id_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->subscription['location_id'] = $this->scope['location_id'];
		$this->subscription['plan_variation_id'] = 'different-plan';
		$this->assertSame( 'subscription_plan_variation_id_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );
	}

	public function test_unpaid_and_processing_invoices_are_not_payment_failures(): void {
		foreach ( array( 'UNPAID' => 'payment_pending', 'PAYMENT_PENDING' => 'payment_pending' ) as $square_status => $expected ) {
			$this->invoice['status'] = $square_status;
			$this->invoice['payment_requests'][0]['total_completed_amount_money']['amount'] = 0;
			$result = $this->service()->inspect_contract( $this->contract_id );
			$this->assertSame( $expected, $result['status'] );
			$this->assertSame( 'not_confirmed', $result['payment_evidence']['status'] );
			$this->assertSame( 'awaiting_payment', $result['review_status'] );
			$this->assertSame( 'invoice_' . ( 'UNPAID' === $square_status ? 'unpaid' : 'processing' ), $result['reason_code'] );
		}
	}

	public function test_invoice_failure_without_timestamp_is_distinct_and_held(): void {
		$this->invoice['status'] = 'FAILED';
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_failed', $result['status'] );
		$this->assertSame( 'failed', $result['payment_evidence']['status'] );
		$this->assertSame( 'failure_timestamp_unavailable', $result['reason_code'] );
		$this->assertNull( $result['failure_at'] );
		$this->assertNull( $result['payment'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
	}

	public function test_noncompleted_payment_is_pending(): void {
		$this->payment['status'] = 'APPROVED';
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_pending', $result['status'] );
		$this->assertSame( 'not_confirmed', $result['payment_evidence']['status'] );
		$this->assertSame( 'payment_not_completed', $result['reason_code'] );
	}

	public function test_invoice_order_and_payment_amount_or_currency_mismatches_are_held(): void {
		$this->order['total_money']['amount'] = 499;
		$this->assertSame( 'order_amount_or_currency_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->order['total_money'] = array( 'amount' => 500, 'currency' => 'USD' );
		$this->assertSame( 'order_amount_or_currency_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->order['total_money'] = array( 'amount' => 500, 'currency' => 'JPY' );
		$this->payment['amount_money']['amount'] = 499;
		$this->assertSame( 'payment_amount_or_currency_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->payment['amount_money'] = array( 'amount' => 500, 'currency' => 'USD' );
		$this->assertSame( 'payment_amount_or_currency_mismatch', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );
	}

	public function test_multiple_tenders_partial_payment_and_refund_are_held(): void {
		$this->order['tenders'][] = array( 'payment_id' => 'second-payment' );
		$this->assertSame( 'payment_id_missing_or_multiple', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->order['tenders'] = array( array( 'payment_id' => $this->payment['id'] ) );
		$this->invoice['status'] = 'PARTIALLY_PAID';
		$this->invoice['payment_requests'][0]['total_completed_amount_money']['amount'] = 200;
		$this->assertSame( 'invoice_state_not_confirmable', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->invoice['status'] = 'PAID';
		$this->invoice['payment_requests'][0]['total_completed_amount_money']['amount'] = 500;
		$this->payment['refund_ids'] = array( 'refund-test-id' );
		$refunded = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'reconciliation_required', $refunded['status'] );
		$this->assertSame( 'confirmed', $refunded['payment_evidence']['status'] );
		$this->assertSame( 'refund_detected', $refunded['reason_code'] );
	}

	public function test_absent_refund_metadata_keeps_payment_fact_but_requires_review(): void {
		unset( $this->payment['refund_ids'], $this->payment['refunded_money'] );
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertSame( 'unknown', $result['payment_evidence']['refund_status'] );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'refund_status_unavailable', $result['reason_code'] );
		$this->assertSame( 'required', $result['review_status'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
	}

	public function test_subscription_cancellation_and_unknown_period_are_not_confirmed(): void {
		$this->subscription['status'] = 'CANCELED';
		$this->subscription['canceled_date'] = '2026-12-10';
		$this->assertSame( 'subscription_cancellation_or_status_requires_review', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->subscription['status'] = 'ACTIVE';
		unset( $this->subscription['canceled_date'] );
		$this->wpdb->update( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'billing_period_start' => null, 'billing_period_end' => null ), array( 'subscription_id' => $this->contract_id ), array( '%s', '%s' ), array( '%d' ) );
		$result = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'billing_period_provenance_unavailable', $result['reason_code'] );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertFalse( $result['billing_period']['verified'] );
		$this->assertSame( $this->payment['id'], $result['payment']['id'] );
	}

	public function test_missing_invoice_or_payment_and_api_errors_are_not_treated_as_unpaid(): void {
		$this->subscription['invoice_ids'] = array();
		$no_invoice = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_pending', $no_invoice['status'] );
		$this->assertSame( 0, $no_invoice['invoice_scope']['invoice_total_count'] );
		$this->assertSame( 'not_confirmed', $no_invoice['payment_evidence']['status'] );

		$this->subscription['invoice_ids'] = array( $this->invoice['id'] );
		$this->fail_path = '/v2/invoices/';
		$invoice_error = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'square_api_error', $invoice_error['status'] );
		$this->assertSame( 'not_confirmed', $invoice_error['payment_evidence']['status'] );
		$this->assertSame( $this->invoice['id'], $invoice_error['invoice']['id'] );

		$this->fail_path = '/v2/payments/';
		$payment_error = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'square_api_error', $payment_error['status'] );
		$this->assertSame( 'not_confirmed', $payment_error['payment_evidence']['status'] );
		$this->assertSame( $this->payment['id'], $payment_error['payment']['id'] );

		$this->fail_path = null;
		$this->missing_path = '/v2/invoices/';
		$this->assertSame( 'invoice_not_found', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->missing_path = '/v2/payments/';
		$this->assertSame( 'payment_not_found', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		unset( $this->subscription['invoice_ids'] );
		$invoice_list_unknown = $this->service()->inspect_contract( $this->contract_id );
		$this->assertSame( 'reconciliation_required', $invoice_list_unknown['status'] );
		$this->assertSame( 'invoice_list_unavailable', $invoice_list_unknown['reason_code'] );
		$this->assertNull( $invoice_list_unknown['invoice_scope']['invoice_total_count'] );
	}

	public function test_missing_tender_and_invoice_period_do_not_confirm_payment(): void {
		$this->order['tenders'] = array();
		$this->assertSame( 'payment_id_missing_or_multiple', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );

		$this->order['tenders'] = array( array( 'payment_id' => $this->payment['id'] ) );
		$this->wpdb->update( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'billing_period_start' => null, 'billing_period_end' => null ), array( 'subscription_id' => $this->contract_id ), array( '%s', '%s' ), array( '%d' ) );
		$this->assertSame( 'billing_period_provenance_unavailable', $this->service()->inspect_contract( $this->contract_id )['reason_code'] );
	}

	public function test_invalid_local_id_is_rejected_without_square_requests(): void {
		$result = $this->service()->inspect_contract( 'not-an-id' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, count( $this->http_calls ) );
	}

	private function service(): Egov_Law_Monitor_Square_Recurring_Payment_Read_Service {
		$config = array_merge( $this->scope, array( 'access_token' => 'read-only-test-token' ) );
		return new Egov_Law_Monitor_Square_Recurring_Payment_Read_Service( $this->wpdb, new Egov_Law_Monitor_Square_Api_Client( $config ) );
	}

	private function insert_contract(): int {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => $this->scope['environment'], 'merchant_id' => $this->scope['merchant_id'],
			'square_location_id' => $this->scope['location_id'], 'square_customer_id' => $this->subscription['customer_id'],
			'square_subscription_id' => $this->subscription['id'], 'square_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'plan_code' => 'standard', 'internal_status' => 'pending', 'square_status' => 'ACTIVE', 'initial_payment_status' => 'unknown',
			'current_slot' => null, 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		return (int) $this->wpdb->insert_id;
	}

	private function insert_local_invoice(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscription_invoices', array(
			'subscription_id' => $this->contract_id, 'user_id' => 1, 'environment' => $this->scope['environment'], 'merchant_id' => $this->scope['merchant_id'],
			'square_invoice_id' => $this->invoice['id'], 'square_version' => 2, 'invoice_status' => 'PAID', 'square_status' => 'PAID',
			'payment_status' => 'unknown', 'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY',
			'billing_period_start' => '2026-12-01 00:00:00', 'billing_period_end' => '2026-12-31 23:59:59',
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function contract_row(): array {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_id ), ARRAY_A );
	}

	private function invoice_row(): array {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE subscription_id = %d", $this->contract_id ), ARRAY_A );
	}
}
