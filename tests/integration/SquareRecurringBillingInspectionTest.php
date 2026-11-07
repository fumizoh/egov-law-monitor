<?php

use PHPUnit\Framework\TestCase;

final class SquareRecurringBillingInspectionTest extends TestCase {
	private $wpdb;
	private $scope;
	private $contract_id;
	private $review_id;
	private $contract;
	private $subscription;
	private $variation;
	private $invoices = array();
	private $orders = array();
	private $payments = array();
	private $refunds = array();
	private $filter;
	private $http_calls = array();
	private $service;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->scope = array(
			'environment' => 'sandbox', 'merchant_id' => 'inspect-merchant-' . wp_generate_uuid4(),
			'location_id' => 'inspect-location-' . wp_generate_uuid4(), 'catalog_item_variation_id' => 'inspect-item-variation',
			'subscription_plan_variation_id' => 'inspect-plan-variation', 'access_token' => 'inspection-test-token',
		);
		$base_invoice = 'inspect-baseline-' . wp_generate_uuid4();
		$new_invoice = 'inspect-new-' . wp_generate_uuid4();
		$this->contract = array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => $this->scope['merchant_id'],
			'square_location_id' => $this->scope['location_id'], 'square_customer_id' => 'inspect-customer-' . wp_generate_uuid4(),
			'square_subscription_id' => 'inspect-subscription-' . wp_generate_uuid4(), 'square_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'plan_code' => 'standard', 'internal_status' => 'pending', 'square_status' => 'ACTIVE', 'initial_payment_status' => 'unknown',
			'current_slot' => null, 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		);
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscriptions', $this->contract ) );
		$this->contract_id = (int) $wpdb->insert_id;
		$this->contract['id'] = $this->contract_id;
		$this->subscription = array(
			'id' => $this->contract['square_subscription_id'], 'location_id' => $this->scope['location_id'],
			'plan_variation_id' => $this->scope['subscription_plan_variation_id'], 'customer_id' => $this->contract['square_customer_id'],
			'status' => 'ACTIVE', 'start_date' => '2025-10-01', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1,
			'invoice_ids' => array( $new_invoice, $base_invoice ), 'actions' => array(),
		);
		$this->variation = array(
			'id' => $this->scope['subscription_plan_variation_id'], 'type' => 'SUBSCRIPTION_PLAN_VARIATION',
			'subscription_plan_variation' => array( 'phases' => array( array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ),
		);
		$this->invoices[ $new_invoice ] = $this->invoice_fixture( $new_invoice, 'inspect-order-' . wp_generate_uuid4(), 'inspect-payment-' . wp_generate_uuid4() );
		$this->invoices[ $base_invoice ] = $this->invoice_fixture( $base_invoice, 'baseline-order', 'baseline-payment' );
		$this->make_order_and_payment( $this->invoices[ $new_invoice ] );
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscription_invoices', array(
			'subscription_id' => $this->contract_id, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => $this->scope['merchant_id'],
			'square_invoice_id' => $base_invoice, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->add_approval( $base_invoice );
		$this->filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( preg_match( '#/v2/subscriptions/([^/]+)/events$#', $path, $m ) ) {
				$body = array( 'subscription_events' => array( array( 'id' => 'start-event', 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => $this->subscription['start_date'] ) ) );
			} elseif ( preg_match( '#/v2/subscriptions/([^/]+)$#', $path, $m ) ) {
				$body = array( 'subscription' => $this->subscription );
			} elseif ( preg_match( '#/v2/catalog/object/([^/]+)$#', $path, $m ) ) {
				$body = array( 'catalog_object' => $this->variation );
			} elseif ( preg_match( '#/v2/invoices/([^/]+)$#', $path, $m ) ) {
				$body = isset( $this->invoices[ rawurldecode( $m[1] ) ] ) ? array( 'invoice' => $this->invoices[ rawurldecode( $m[1] ) ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
				if ( ! isset( $this->invoices[ rawurldecode( $m[1] ) ] ) ) {
					return $this->response( $body, 404 );
				}
			} elseif ( preg_match( '#/v2/orders/([^/]+)$#', $path, $m ) ) {
				$body = isset( $this->orders[ rawurldecode( $m[1] ) ] ) ? array( 'order' => $this->orders[ rawurldecode( $m[1] ) ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} elseif ( preg_match( '#/v2/payments/([^/]+)$#', $path, $m ) ) {
				$body = isset( $this->payments[ rawurldecode( $m[1] ) ] ) ? array( 'payment' => $this->payments[ rawurldecode( $m[1] ) ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} elseif ( preg_match( '#/v2/refunds/([^/]+)$#', $path, $m ) ) {
				$body = isset( $this->refunds[ rawurldecode( $m[1] ) ] ) ? array( 'refund' => $this->refunds[ rawurldecode( $m[1] ) ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} else {
				$this->fail( 'Unexpected mocked Square endpoint.' );
			}
			return $this->response( $body );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->scope );
		$this->service = new Egov_Law_Monitor_Square_Recurring_Billing_Inspection_Service( $wpdb, $client );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_square_admin_reviews', array( 'id' => $this->review_id ), array( '%d' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $this->contract_id ), array( '%d' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $this->contract_id ), array( '%d' ) );
		}
		parent::tearDown();
	}

	public function test_single_monthly_invoice_is_checked_against_last_approved_invoice_and_candidate_is_not_authorization(): void {
		$before_contract = $this->contract_row();
		$before_invoice = $this->invoice_rows();
		$before_entitlement = egov_law_monitor_entitlement_service()->resolve_for_user( 1 );
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_confirmed', $result['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 'required', $result['review_status'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertSame( 1, $result['invoice_scope']['requested_invoice_count'] );
		$this->assertSame( 1, $result['invoice_scope']['checked_invoice_count'] );
		$this->assertSame( 0, $result['invoice_scope']['unchecked_invoice_count'] );
		$this->assertSame( 'square_subscription_invoice_ids_field_newest_first', $result['invoice_scope']['invoice_list_completeness'] );
		$this->assertSame( 'review_required', $result['period_candidate']['status'] );
		$this->assertSame( 'approved_period_schedule_provenance_missing', $result['period_candidate']['reason_code'] );
		$this->assertNull( $result['period_candidate']['billing_period'] );
		$this->assertSame( $before_contract, $this->contract_row() );
		$this->assertSame( $before_invoice, $this->invoice_rows() );
		$this->assertSame( $before_entitlement, egov_law_monitor_entitlement_service()->resolve_for_user( 1 ) );
	}

	public function test_all_invoices_after_approval_are_checked_and_multiple_remain_for_review(): void {
		$older = 'inspect-older-' . wp_generate_uuid4();
		$this->subscription['invoice_ids'] = array( $this->subscription['invoice_ids'][0], $older, $this->subscription['invoice_ids'][1] );
		$this->invoices[ $older ] = $this->invoice_fixture( $older, 'older-order', 'older-payment' );
		$this->make_order_and_payment( $this->invoices[ $older ] );
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['payment_evidence']['confirmed'] );
		$this->assertSame( 2, $result['invoice_scope']['requested_invoice_count'] );
		$this->assertSame( 2, $result['invoice_scope']['checked_invoice_count'] );
		$this->assertSame( 'required', $result['review_status'] );
		$this->assertSame( 'candidate_not_calculated', $result['period_candidate']['reason_code'] );
	}

	public function test_missing_approved_invoice_baseline_is_held_without_square_calls(): void {
		$this->subscription['invoice_ids'] = array( 'not-the-approved-invoice' );
		$this->http_calls = array();
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertSame( 'approved_invoice_not_in_subscription_list', $result['reason_code'] );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '/v2/subscriptions/', $this->http_calls[0] );
	}

	public function test_subscription_scope_variation_phase_and_schedule_history_mismatches_are_held(): void {
		$baseline_invoice_id = $this->subscription['invoice_ids'][1];
		$this->subscription['customer_id'] = 'other-customer';
		$this->assertSame( 'subscription_customer_id_mismatch', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->subscription['customer_id'] = $this->contract['square_customer_id'];
		$this->subscription['location_id'] = 'other-location';
		$this->assertSame( 'subscription_location_id_mismatch', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->subscription['location_id'] = $this->scope['location_id'];
		$this->subscription['plan_variation_id'] = 'other-plan';
		$this->assertSame( 'subscription_plan_variation_id_mismatch', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->subscription['plan_variation_id'] = $this->scope['subscription_plan_variation_id'];
		$this->variation['subscription_plan_variation']['phases'][] = array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) );
		$this->assertSame( 'variation_phase_or_price_unconfirmed', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->variation['subscription_plan_variation']['phases'] = array( array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) );
		$this->variation['subscription_plan_variation']['phases'][0]['cadence'] = 'UNKNOWN_CADENCE';
		$this->assertSame( 'variation_phase_or_price_unconfirmed', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->variation['subscription_plan_variation']['phases'][0]['cadence'] = 'MONTHLY';
		$this->subscription['actions'] = array( array( 'id' => 'action-1', 'type' => 'CANCEL', 'effective_date' => '2027-01-01' ) );
		$this->assertSame( 'subscription_actions_pending', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->subscription['actions'] = array();
		$this->subscription['invoice_ids'] = array( $this->subscription['invoice_ids'][0], $baseline_invoice_id );
		$this->subscription['actions'] = array();
		// The all-pages flag alone does not erase a known plan-change event.
		remove_filter( 'pre_http_request', $this->filter, 10 );
		$old_filter = $this->filter;
		remove_filter( 'pre_http_request', $old_filter, 10 );
		$this->filter = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/events' ) ) {
				return $this->response( array( 'subscription_events' => array(
					array( 'id' => 'start', 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => '2025-10-01' ),
					array( 'id' => 'plan-change', 'subscription_event_type' => 'PLAN_CHANGE', 'effective_date' => '2026-01-01', 'plan_variation_id' => 'new-plan' ),
				) ) );
			}
			return $this->route_default( $url );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		$this->assertSame( 'subscription_history_contains_change', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
	}

	public function test_incomplete_subscription_event_pages_require_review(): void {
		$old_filter = $this->filter;
		remove_filter( 'pre_http_request', $old_filter, 10 );
		$page = 0;
		$this->filter = function ( $preempt, $args, $url ) use ( &$page ) {
			$this->http_calls[] = $url;
			if ( false !== strpos( $url, '/events' ) ) {
				++$page;
				$events = array();
				for ( $i = 0; $i < 100; ++$i ) {
					$events[] = array( 'id' => 'event-' . $page . '-' . $i, 'subscription_event_type' => 0 === $i && 1 === $page ? 'START_SUBSCRIPTION' : 'UNKNOWN_EVENT', 'effective_date' => '2025-10-01' );
				}
				return $this->response( array( 'subscription_events' => $events, 'cursor' => 'cursor-' . $page ) );
			}
			return $this->route_default( $url );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertSame( 'subscription_events_incomplete', $result['reason_code'] );
		$this->assertSame( 5, $page );
		$this->assertFalse( $result['automatic_approval_candidate'] );
	}

	public function test_unpaid_failed_partial_mismatch_and_refund_are_not_misclassified_as_api_errors(): void {
		$invoice_id = $this->subscription['invoice_ids'][0];
		$this->invoices[ $invoice_id ]['status'] = 'UNPAID';
		$this->invoices[ $invoice_id ]['payment_requests'][0]['total_completed_amount_money']['amount'] = 0;
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_pending', $result['status'] );
		$this->assertSame( 'pending', $result['payment_evidence']['status'] );
		$this->invoices[ $invoice_id ]['status'] = 'FAILED';
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'payment_failed', $result['status'] );
		$this->assertSame( 'failed', $result['payment_evidence']['status'] );
		$this->assertSame( 'square_invoice_failed', $result['reason_code'] );
		$this->invoices[ $invoice_id ]['status'] = 'PAID';
		$this->invoices[ $invoice_id ]['payment_requests'][0]['total_completed_amount_money']['amount'] = 500;
		$payment_id = $this->payments[ array_key_first( $this->payments ) ]['id'];
		$this->payments[ $payment_id ]['amount_money']['amount'] = 499;
		$this->assertSame( 'payment_amount_or_currency_mismatch', $this->service->inspect_contract( $this->contract_id )['reason_code'] );
		$this->payments[ $payment_id ]['amount_money']['amount'] = 500;
		$this->payments[ $payment_id ]['refund_ids'] = array( 'refund-id' );
		$this->payments[ $payment_id ]['refunded_money'] = array( 'amount' => 500, 'currency' => 'JPY' );
		$this->refunds['refund-id'] = array( 'id' => 'refund-id', 'payment_id' => $payment_id, 'status' => 'PENDING', 'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ) );
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertSame( 'refund_detected', $result['reason_code'] );
		$this->assertSame( 'PENDING', $result['payment_evidence']['invoice_results'][0]['refunds'][0]['status'] );
	}

	public function test_square_api_error_is_separate_from_payment_failure_and_does_not_write(): void {
		$before_contract = $this->contract_row();
		$before_invoices = $this->invoice_rows();
		$old_filter = $this->filter;
		remove_filter( 'pre_http_request', $old_filter, 10 );
		$this->filter = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/v2/invoices/' ) ) {
				return $this->response( array( 'errors' => array( array( 'code' => 'TEMPORARY_ERROR' ) ) ), 503 );
			}
			return $this->route_default( $url );
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
		$result = $this->service->inspect_contract( $this->contract_id );
		$this->assertSame( 'square_api_error', $result['status'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
		$this->assertSame( $before_contract, $this->contract_row() );
		$this->assertSame( $before_invoices, $this->invoice_rows() );
	}

	private function add_approval( $invoice_id ): void {
		$repository = new Egov_Law_Monitor_Square_Admin_Review_Repository( $this->wpdb );
		$result = $repository->append_review( array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => $this->scope['merchant_id'], 'contract_id' => $this->contract_id,
			'invoice_row_id' => null, 'checkout_attempt_id' => null, 'checkout_attempt_uuid' => null,
			'square_invoice_id' => $invoice_id, 'square_payment_id' => 'approved-payment-' . wp_generate_uuid4(),
			'action_type' => 'approve', 'candidate_period_start' => '2026-11-01 00:00:00', 'candidate_period_end' => '2026-12-01 00:00:00',
			'approved_period_start' => '2026-11-01 00:00:00', 'approved_period_end' => '2026-12-01 00:00:00',
			'evidence_code' => 'payment_and_period_checked', 'reviewer_user_id' => 1, 'supersedes_review_id' => null,
		) );
		$this->assertSame( 'saved', $result['status'] );
		$this->review_id = $result['review_id'];
	}

	private function invoice_fixture( $invoice_id, $order_id, $payment_id ): array {
		return array(
			'id' => $invoice_id, 'subscription_id' => $this->contract['square_subscription_id'], 'location_id' => $this->scope['location_id'], 'order_id' => $order_id,
			'status' => 'PAID', 'version' => 2, 'payment_requests' => array( array( 'uid' => 'request-' . substr( hash( 'sha256', $invoice_id ), 0, 12 ), 'request_type' => 'BALANCE', 'due_date' => '2026-12-01', 'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ),
		);
	}

	private function make_order_and_payment( array $invoice ): void {
		$payment_id = $invoice['payment_requests'][0]['uid'] . '-payment';
		// Keep the Payment ID in the order's tender and the response map.
		$this->orders[ $invoice['order_id'] ] = array( 'id' => $invoice['order_id'], 'location_id' => $this->scope['location_id'], 'state' => 'COMPLETED', 'tenders' => array( array( 'payment_id' => $payment_id ) ), 'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ) );
		$this->payments[ $payment_id ] = array( 'id' => $payment_id, 'order_id' => $invoice['order_id'], 'customer_id' => $this->contract['square_customer_id'], 'location_id' => $this->scope['location_id'], 'status' => 'COMPLETED', 'created_at' => '2026-12-01T00:00:00Z', 'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'refund_ids' => array(), 'refunded_money' => array( 'amount' => 0, 'currency' => 'JPY' ) );
	}

	private function route_default( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( false !== strpos( $path, '/events' ) ) {
			return $this->response( array( 'subscription_events' => array( array( 'id' => 'start-event', 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => $this->subscription['start_date'] ) ) ) );
		}
		if ( false !== strpos( $path, '/subscriptions/' ) ) return $this->response( array( 'subscription' => $this->subscription ) );
		if ( false !== strpos( $path, '/catalog/object/' ) ) return $this->response( array( 'catalog_object' => $this->variation ) );
		foreach ( array( 'invoices' => 'invoice', 'orders' => 'order', 'payments' => 'payment', 'refunds' => 'refund' ) as $resource => $key ) {
			if ( preg_match( '#/v2/' . $resource . '/([^/]+)$#', $path, $m ) ) {
				$map = 'invoice' === $key ? $this->invoices : ( 'order' === $key ? $this->orders : ( 'payment' === $key ? $this->payments : $this->refunds ) );
				$id = rawurldecode( $m[1] );
				return isset( $map[ $id ] ) ? $this->response( array( $key => $map[ $id ] ) ) : $this->response( array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), 404 );
			}
		}
		return $this->response( array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), 404 );
	}

	private function response( array $body, $status = 200 ): array {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'mock' ), 'cookies' => array(), 'filename' => null );
	}

	private function contract_row(): array {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_id ), ARRAY_A );
	}

	private function invoice_rows(): array {
		return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE subscription_id = %d ORDER BY id", $this->contract_id ), ARRAY_A );
	}

}
