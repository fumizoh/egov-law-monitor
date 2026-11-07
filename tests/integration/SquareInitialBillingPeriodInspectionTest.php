<?php

use PHPUnit\Framework\TestCase;

final class SquareInitialBillingPeriodInspectionTest extends TestCase {
	private $wpdb;
	private $attempt_id;
	private $attempt_uuid;
	private $scope;
	private $payment_id;
	private $order_id;
	private $customer_id;
	private $subscription_id;
	private $invoice_id;
	private $payment;
	private $orders = array();
	private $subscriptions = array();
	private $invoices = array();
	private $search_candidates = array();
	private $search_cursor = null;
	private $http_filter;
	private $http_calls = array();
	private $http_error_path = null;
	private $service;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->scope = array(
			'environment' => 'sandbox', 'access_token' => 'mock-initial-period-token', 'merchant_id' => 'initial-period-merchant',
			'location_id' => 'initial-period-location', 'catalog_item_variation_id' => 'initial-period-item', 'subscription_plan_variation_id' => 'initial-period-plan',
		);
		$this->attempt_uuid = wp_generate_uuid4();
		$this->order_id = 'initial-period-order-' . wp_generate_uuid4();
		$this->payment_id = 'initial-period-payment-' . wp_generate_uuid4();
		$this->customer_id = 'initial-period-customer-' . wp_generate_uuid4();
		$this->subscription_id = 'initial-period-subscription-' . wp_generate_uuid4();
		$this->invoice_id = 'initial-period-invoice-' . wp_generate_uuid4();
		$this->payment = array(
			'id' => $this->payment_id, 'order_id' => $this->order_id, 'customer_id' => $this->customer_id,
			'location_id' => $this->scope['location_id'], 'status' => 'COMPLETED', 'created_at' => '2026-11-01T00:00:00Z',
			'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'refund_ids' => array(), 'refunded_money' => array( 'amount' => 0, 'currency' => 'JPY' ),
		);
		$this->orders[ $this->order_id ] = $this->order_fixture( $this->order_id, $this->attempt_uuid, $this->payment_id );
		$this->subscriptions[ $this->subscription_id ] = $this->subscription_fixture( $this->subscription_id, array( $this->invoice_id ) );
		$this->invoices[ $this->invoice_id ] = $this->invoice_fixture( $this->invoice_id, $this->subscription_id, $this->order_id );
		$this->search_candidates = array( array( 'id' => $this->subscription_id, 'location_id' => $this->scope['location_id'], 'customer_id' => $this->customer_id, 'plan_variation_id' => $this->scope['subscription_plan_variation_id'] ) );
		$this->insert_attempt();
		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = $url;
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $this->http_error_path && false !== strpos( $path, $this->http_error_path ) ) {
				return $this->response( array( 'errors' => array( array( 'code' => 'INTERNAL_SERVER_ERROR' ) ) ), 500 );
			}
			if ( false !== strpos( $path, '/subscriptions/search' ) ) {
				$body = array( 'subscriptions' => $this->search_candidates );
				if ( null !== $this->search_cursor ) {
					$body['cursor'] = $this->search_cursor;
				}
			} elseif ( preg_match( '#/subscriptions/([^/]+)/events$#', $path, $match ) ) {
				$subscription = $this->subscriptions[ rawurldecode( $match[1] ) ] ?? null;
				$body = array( 'subscription_events' => $subscription['events'] ?? array() );
			} elseif ( preg_match( '#/subscriptions/([^/]+)$#', $path, $match ) ) {
				$body = isset( $this->subscriptions[ rawurldecode( $match[1] ) ] ) ? array( 'subscription' => $this->subscriptions[ rawurldecode( $match[1] ) ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} elseif ( preg_match( '#/catalog/object/([^/]+)$#', $path, $match ) ) {
				$body = array( 'catalog_object' => array(
					'id' => rawurldecode( $match[1] ), 'type' => 'SUBSCRIPTION_PLAN_VARIATION',
					'subscription_plan_variation' => array( 'phases' => array( array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ),
				) );
			} elseif ( preg_match( '#/invoices/([^/]+)$#', $path, $match ) ) {
				$id = rawurldecode( $match[1] );
				$body = isset( $this->invoices[ $id ] ) ? array( 'invoice' => $this->invoices[ $id ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} elseif ( preg_match( '#/orders/([^/]+)$#', $path, $match ) ) {
				$id = rawurldecode( $match[1] );
				$body = isset( $this->orders[ $id ] ) ? array( 'order' => $this->orders[ $id ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} elseif ( preg_match( '#/payments/([^/]+)$#', $path, $match ) ) {
				$id = rawurldecode( $match[1] );
				$body = $id === $this->payment['id'] ? array( 'payment' => $this->payment ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) );
			} else {
				$this->fail( 'Unexpected mocked Square endpoint: ' . $path );
			}
			return $this->response( $body );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
		$this->service = $this->build_service();
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' && $this->attempt_id ) {
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_square_admin_reviews', array( 'checkout_attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'square_invoice_id' => $this->invoice_id ), array( '%s' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'checkout_attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'id' => $this->attempt_id ), array( '%d' ) );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_initial_payment_and_exact_invoice_order_chain_produce_candidate_but_never_approval(): void {
		$this->insert_contract();
		$before_attempt = $this->attempt_row();
		$before_contracts = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions" );
		$before_invoices = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" );
		$roles = get_userdata( 1 )->roles;
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'confirmed', $result['subscription_link']['status'] );
		$this->assertSame( 'local_contract_and_attempt_reference_chain', $result['subscription_link']['method'] );
		$this->assertTrue( $result['invoice_payment']['verified'] );
		$this->assertSame( 'candidate', $result['period_candidate']['status'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertSame( $before_attempt, $this->attempt_row() );
		$this->assertSame( $before_contracts, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions" ) );
		$this->assertSame( $before_invoices, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" ) );
		$this->assertSame( $roles, get_userdata( 1 )->roles );
	}

	public function test_search_candidates_require_exact_attempt_reference_and_multiple_matches_are_held(): void {
		$first = $this->subscription_id;
		$second = 'initial-period-subscription-second-' . wp_generate_uuid4();
		$second_invoice = 'initial-period-invoice-second-' . wp_generate_uuid4();
		$this->subscriptions[ $second ] = $this->subscription_fixture( $second, array( $second_invoice ) );
		$this->invoices[ $second_invoice ] = $this->invoice_fixture( $second_invoice, $second, $this->order_id );
		$this->search_candidates[] = array( 'id' => $second, 'location_id' => $this->scope['location_id'], 'customer_id' => $this->customer_id, 'plan_variation_id' => $this->scope['subscription_plan_variation_id'] );
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'multiple_subscription_candidates_match_attempt', $result['reason_code'] );
		$this->assertSame( 2, $result['subscription_link']['candidate_count'] );
	}

	public function test_no_local_contract_and_no_exact_square_candidate_remains_unlinked(): void {
		$this->search_candidates = array();
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'checkout_attempt_subscription_identifier_chain_unproven', $result['reason_code'] );
		$this->assertSame( 'unconfirmed', $result['subscription_link']['status'] );
	}

	public function test_incomplete_subscription_search_is_held_without_inspecting_partial_candidates(): void {
		$this->search_cursor = 'next-page-cursor';
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'subscription_search_incomplete', $result['reason_code'] );
		$this->assertSame( 'unconfirmed', $result['subscription_link']['status'] );
		$subscription_reads = array_filter( $this->http_calls, static function ( $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return preg_match( '#/subscriptions/[^/]+$#', $path ) && ! preg_match( '#/subscriptions/search$#', $path );
		} );
		$this->assertCount( 0, $subscription_reads );
	}

	public function test_invoice_order_id_may_differ_but_does_not_prove_initial_payment_relationship(): void {
		$invoice_order_id = 'initial-period-recurring-order-' . wp_generate_uuid4();
		$this->orders[ $invoice_order_id ] = $this->order_fixture( $invoice_order_id, $this->attempt_uuid, 'different-invoice-payment' );
		$this->invoices[ $this->invoice_id ] = $this->invoice_fixture( $this->invoice_id, $this->subscription_id, $invoice_order_id );
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'confirmed', $result['subscription_link']['status'] );
		$this->assertSame( 'attempt_reference_chain', $result['subscription_link']['method'] );
		$this->assertFalse( $result['invoice_payment']['verified'] );
		$this->assertSame( 'initial_invoice_payment_link_unproven', $result['invoice_payment']['reason_code'] );
	}

	public function test_multiple_subscription_invoices_and_multiple_tenders_are_never_approved(): void {
		$second_invoice = 'initial-period-invoice-second-' . wp_generate_uuid4();
		$this->subscriptions[ $this->subscription_id ]['invoice_ids'][] = $second_invoice;
		$other_order_id = 'initial-period-order-second-' . wp_generate_uuid4();
		$this->orders[ $other_order_id ] = $this->order_fixture( $other_order_id, wp_generate_uuid4(), 'another-payment' );
		$this->invoices[ $second_invoice ] = $this->invoice_fixture( $second_invoice, $this->subscription_id, $other_order_id );
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'multiple_subscription_invoices_require_review', $result['reason_code'] );

		$this->orders[ $this->order_id ]['tenders'][] = array( 'payment_id' => 'second-tender-payment' );
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertFalse( $result['initial_payment']['confirmed'] );
	}

	public function test_unknown_cadence_or_incomplete_events_require_review(): void {
		$this->insert_contract();
		$this->filter_plan_cadence( 'UNKNOWN_CADENCE' );
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'subscription_plan_phase_or_price_unconfirmed', $result['reason_code'] );

		$this->filter_plan_cadence( 'MONTHLY' );
		$this->subscriptions[ $this->subscription_id ]['events'] = array();
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertSame( 'subscription_start_event_unconfirmed', $result['reason_code'] );
	}

	public function test_api_error_is_not_reported_as_unpaid(): void {
		$this->http_error_path = '/payments/';
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'square_api_error', $result['status'] );
		$this->assertFalse( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'payment_api_unavailable', $result['reason_code'] );
	}

	public function test_attempt_and_payment_mismatches_are_held_without_database_or_entitlement_changes(): void {
		$this->insert_contract();
		$this->assertSame( 1, $this->wpdb->update(
			$this->wpdb->prefix . 'egov_law_subscriptions',
			array( 'square_customer_id' => 'different-customer' ),
			array( 'checkout_attempt_uuid' => $this->attempt_uuid ),
			array( '%s' ),
			array( '%s' )
		) );
		$this->search_candidates = array();
		$before_attempt = $this->attempt_row();
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'attempt_contract_identity_mismatch', $result['reason_code'] );
		$this->assertSame( $before_attempt, $this->attempt_row() );
		$this->assertSame( 'free', egov_law_monitor_entitlement_service()->resolve_for_user( 1 )['effective_plan'] );
	}

	public function test_subscription_customer_and_location_mismatches_are_held_after_payment_evidence_is_preserved(): void {
		$this->insert_contract();
		$before_attempt = $this->attempt_row();
		$this->subscriptions[ $this->subscription_id ]['customer_id'] = 'different-square-customer';
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'subscription_customer_id_mismatch', $result['reason_code'] );

		$this->subscriptions[ $this->subscription_id ]['customer_id'] = $this->customer_id;
		$this->subscriptions[ $this->subscription_id ]['location_id'] = 'different-square-location';
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'subscription_location_id_mismatch', $result['reason_code'] );
		$this->assertSame( $before_attempt, $this->attempt_row() );
	}

	public function test_refund_evidence_keeps_payment_confirmed_but_requires_review(): void {
		$this->payment['refund_ids'] = array( 'refund-' . wp_generate_uuid4() );
		$this->payment['refunded_money'] = array( 'amount' => 500, 'currency' => 'JPY' );
		$before_attempt = $this->attempt_row();
		$result = $this->service->inspect_attempt( $this->attempt_id );
		$this->assertSame( 'review_required', $result['status'] );
		$this->assertTrue( $result['initial_payment']['confirmed'] );
		$this->assertSame( 'detected', $result['initial_payment']['refund_status'] );
		$this->assertSame( 'initial_payment_refund_requires_review', $result['reason_code'] );
		$this->assertSame( $before_attempt, $this->attempt_row() );
	}

	private function build_service(): Egov_Law_Monitor_Square_Initial_Billing_Period_Inspection_Service {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->scope );
		$repository = new Egov_Law_Monitor_Checkout_Repository( $this->wpdb );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
		$payment_confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $payment_matcher );
		$subscription_matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $repository, $client, $payment_confirmation );
		$initial_reader = new Egov_Law_Monitor_Square_Initial_Payment_Read_Service( $this->wpdb, $repository, $client, $payment_confirmation, $subscription_matcher );
		return new Egov_Law_Monitor_Square_Initial_Billing_Period_Inspection_Service( $this->wpdb, $repository, $client, $initial_reader );
	}

	private function insert_attempt(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => $this->scope['merchant_id'],
			'square_location_id' => $this->scope['location_id'], 'plan_code' => 'standard', 'catalog_item_variation_id' => $this->scope['catalog_item_variation_id'],
			'subscription_plan_variation_id' => $this->scope['subscription_plan_variation_id'], 'square_customer_id' => $this->customer_id, 'status' => 'pending',
			'idempotency_key' => wp_generate_uuid4(), 'square_order_id' => $this->order_id, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->attempt_id = (int) $this->wpdb->insert_id;
	}

	private function insert_contract(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => $this->scope['merchant_id'], 'square_location_id' => $this->scope['location_id'],
			'square_customer_id' => $this->customer_id, 'square_subscription_id' => $this->subscription_id, 'square_plan_variation_id' => $this->scope['subscription_plan_variation_id'],
			'checkout_attempt_uuid' => $this->attempt_uuid, 'plan_code' => 'standard', 'internal_status' => 'pending', 'current_slot' => null,
			'square_status' => 'ACTIVE', 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function attempt_row(): array {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_id ), ARRAY_A );
	}

	private function subscription_fixture( $id, array $invoice_ids ): array {
		return array(
			'id' => $id, 'location_id' => $this->scope['location_id'], 'customer_id' => $this->customer_id,
			'plan_variation_id' => $this->scope['subscription_plan_variation_id'], 'status' => 'ACTIVE', 'start_date' => '2026-11-01',
			'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1, 'invoice_ids' => $invoice_ids, 'version' => 1,
			'actions' => array(), 'events' => array( array( 'id' => 'start-' . $id, 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => '2026-11-01' ) ),
		);
	}

	private function invoice_fixture( $id, $subscription_id, $order_id ): array {
		return array(
			'id' => $id, 'subscription_id' => $subscription_id, 'location_id' => $this->scope['location_id'], 'order_id' => $order_id,
			'status' => 'PAID', 'version' => 1, 'payment_requests' => array( array(
				'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
				'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			) ),
		);
	}

	private function order_fixture( $id, $reference_id, $payment_id ): array {
		return array(
			'id' => $id, 'reference_id' => $reference_id, 'location_id' => $this->scope['location_id'], 'state' => 'OPEN', 'version' => 1,
			'tenders' => array( array( 'payment_id' => $payment_id ) ), 'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
	}

	private function filter_plan_cadence( $cadence ): void {
		$old = $this->http_filter;
		remove_filter( 'pre_http_request', $old, 10 );
		$this->http_filter = function ( $preempt, $args, $url ) use ( $cadence ) {
			if ( false !== strpos( wp_parse_url( $url, PHP_URL_PATH ), '/catalog/object/' ) ) {
				$this->http_calls[] = $url;
				$path = wp_parse_url( $url, PHP_URL_PATH );
				preg_match( '#/catalog/object/([^/]+)$#', $path, $match );
				return $this->response( array( 'catalog_object' => array( 'id' => rawurldecode( $match[1] ), 'type' => 'SUBSCRIPTION_PLAN_VARIATION', 'subscription_plan_variation' => array( 'phases' => array( array( 'cadence' => $cadence, 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ) ) ) );
			}
			return $this->route( $url );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	private function route( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/subscriptions/([^/]+)/events$#', $path, $match ) ) {
			$subscription = $this->subscriptions[ rawurldecode( $match[1] ) ] ?? null;
			return $this->response( array( 'subscription_events' => $subscription['events'] ?? array() ) );
		}
		if ( preg_match( '#/subscriptions/([^/]+)$#', $path, $match ) ) {
			$id = rawurldecode( $match[1] );
			return $this->response( isset( $this->subscriptions[ $id ] ) ? array( 'subscription' => $this->subscriptions[ $id ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), isset( $this->subscriptions[ $id ] ) ? 200 : 404 );
		}
		if ( preg_match( '#/invoices/([^/]+)$#', $path, $match ) ) {
			$id = rawurldecode( $match[1] );
			return $this->response( isset( $this->invoices[ $id ] ) ? array( 'invoice' => $this->invoices[ $id ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), isset( $this->invoices[ $id ] ) ? 200 : 404 );
		}
		if ( preg_match( '#/orders/([^/]+)$#', $path, $match ) ) {
			$id = rawurldecode( $match[1] );
			return $this->response( isset( $this->orders[ $id ] ) ? array( 'order' => $this->orders[ $id ] ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), isset( $this->orders[ $id ] ) ? 200 : 404 );
		}
		if ( preg_match( '#/payments/([^/]+)$#', $path, $match ) ) {
			$id = rawurldecode( $match[1] );
			return $this->response( $id === $this->payment_id ? array( 'payment' => $this->payment ) : array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), $id === $this->payment_id ? 200 : 404 );
		}
		return $this->response( array( 'errors' => array( array( 'code' => 'NOT_FOUND' ) ) ), 404 );
	}

	private function response( array $body, $status = 200 ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'mock' ), 'cookies' => array(), 'filename' => null );
	}
}
