<?php

use PHPUnit\Framework\TestCase;

final class SquareSubscriptionAttemptMatcherTest extends TestCase {
	private $attempt_uuids = array();
	private $http_filter;
	private $http_calls = array();
	private $subscription;
	private $invoices = array();
	private $orders = array();
	private $payment;
	private $http_status = 200;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$this->subscription = array(
			'id'                 => 'subscription-' . wp_generate_uuid4(),
			'location_id'        => 'sub-match-location',
			'plan_variation_id'  => 'sub-match-plan',
			'customer_id'        => 'sub-match-customer',
			'status'             => 'ACTIVE',
			'invoice_ids'        => array( 'invoice-' . wp_generate_uuid4() ),
		);
		$this->payment = array(
			'id'           => 'payment-' . wp_generate_uuid4(),
			'order_id'     => 'checkout-order-' . wp_generate_uuid4(),
			'customer_id'  => 'sub-match-customer',
			'location_id'  => 'sub-match-location',
			'status'       => 'COMPLETED',
			'created_at'   => '2026-11-05T00:00:00Z',
			'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_money'  => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$invoice_id = $this->subscription['invoice_ids'][0];
		$this->invoices[ $invoice_id ] = array(
			'id'              => $invoice_id,
			'subscription_id' => $this->subscription['id'],
			'location_id'     => 'sub-match-location',
			'order_id'        => $this->payment['order_id'],
			'status'          => 'PAID',
		);
		$this->orders[ $this->payment['order_id'] ] = array(
			'id'           => $this->payment['order_id'],
			'reference_id' => wp_generate_uuid4(),
			'location_id'  => 'sub-match-location',
			'state'        => 'OPEN',
			'tenders'      => array( array( 'payment_id' => $this->payment['id'] ) ),
			'total_money'  => array( 'amount' => 500, 'currency' => 'JPY' ),
		);

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = array( 'args' => $args, 'url' => $url );
			if ( $this->http_status < 200 || $this->http_status >= 300 ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'errors' => array( array( 'code' => 'UNAVAILABLE' ) ) ) ),
					'response' => array( 'code' => $this->http_status, 'message' => 'mocked' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			if ( false !== strpos( $url, '/v2/subscriptions/' ) ) {
				$body = array( 'subscription' => $this->subscription );
			} elseif ( false !== strpos( $url, '/v2/invoices/' ) ) {
				$invoice_id = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				$body = array( 'invoice' => isset( $this->invoices[ $invoice_id ] ) ? $this->invoices[ $invoice_id ] : array() );
			} elseif ( false !== strpos( $url, '/v2/orders/' ) ) {
				$order_id = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				$body = array( 'order' => isset( $this->orders[ $order_id ] ) ? $this->orders[ $order_id ] : array() );
			} elseif ( false !== strpos( $url, '/v2/payments/' ) ) {
				$body = array( 'payment' => $this->payment );
			} else {
				$body = array();
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'mocked' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$table = $wpdb->prefix . 'egov_law_checkout_attempts';
			foreach ( $this->attempt_uuids as $attempt_uuid ) {
				$wpdb->delete( $table, array( 'attempt_uuid' => $attempt_uuid ), array( '%s' ) );
			}
		}
		parent::tearDown();
	}

	public function test_matches_subscription_invoice_order_attempt_and_completed_initial_payment_read_only(): void {
		$attempt = $this->insert_attempt();
		$before  = $this->get_attempt_row();
		$subscriptions_before = $this->table_count( 'egov_law_subscriptions' );
		$invoices_before      = $this->table_count( 'egov_law_subscription_invoices' );

		$result = $this->service()->match_subscription( $this->subscription['id'] );

		$this->assertSame( 'subscription_matched', $result['status'] );
		$this->assertSame( 'subscription_attempt_and_initial_payment_verified', $result['reason_code'] );
		$this->assertSame( 1, $result['user_id'] );
		$this->assertSame( $attempt['attempt_uuid'], $result['attempt_uuid'] );
		$this->assertSame( $this->subscription['id'], $result['subscription_id'] );
		$this->assertSame( $this->subscription['invoice_ids'][0], $result['invoice_id'] );
		$this->assertSame( $this->payment['order_id'], $result['order_id'] );
		$this->assertSame( $this->payment['id'], $result['payment_id'] );
		$this->assertSame( 'sub-match-customer', $result['customer_id'] );
		$this->assertSame( 'sandbox', $result['environment'] );
		$this->assertSame( 'sub-match-merchant', $result['merchant_id'] );
		$this->assertSame( 'sub-match-location', $result['location_id'] );
		$this->assertSame( 'sub-match-plan', $result['plan_variation_id'] );
		$this->assertSame( $before, $this->get_attempt_row() );
		$this->assertSame( $subscriptions_before, $this->table_count( 'egov_law_subscriptions' ) );
		$this->assertSame( $invoices_before, $this->table_count( 'egov_law_subscription_invoices' ) );
	}

	public function test_missing_invoice_ids_is_pending(): void {
		$this->subscription['invoice_ids'] = array();
		$result = $this->service()->match_subscription( $this->subscription['id'] );

		$this->assertResult( 'subscription_pending', 'subscription_invoice_not_available', $result );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_invoice_subscription_id_mismatch_requires_reconciliation(): void {
		$this->insert_attempt();
		$this->invoices[ $this->subscription['invoice_ids'][0] ]['subscription_id'] = 'other-subscription';
		$this->assertResult( 'reconciliation_required', 'invoice_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_invoice_order_id_must_equal_checkout_order_id(): void {
		$this->insert_attempt();
		$invoice = $this->invoices[ $this->subscription['invoice_ids'][0] ];
		$invoice['order_id'] = 'different-invoice-order';
		$this->invoices[ $invoice['id'] ] = $invoice;
		$this->orders['different-invoice-order'] = array(
			'id' => 'different-invoice-order', 'reference_id' => $this->orders[ $this->payment['order_id'] ]['reference_id'],
			'location_id' => 'sub-match-location', 'state' => 'OPEN',
		);

		$this->assertResult( 'reconciliation_required', 'attempt_subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_order_reference_mismatch_and_missing_attempt_do_not_match(): void {
		$this->insert_attempt();
		$this->orders[ $this->payment['order_id'] ]['reference_id'] = wp_generate_uuid4();
		$this->assertResult( 'reconciliation_required', 'checkout_order_link_not_proven', $this->service()->match_subscription( $this->subscription['id'] ) );

		$this->delete_attempts();
		$this->orders[ $this->payment['order_id'] ]['reference_id'] = wp_generate_uuid4();
		$this->assertResult( 'reconciliation_required', 'checkout_order_link_not_proven', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_multiple_attempt_candidates_require_reconciliation(): void {
		$this->insert_attempt();
		$repository = new Egov_Law_Monitor_Test_Duplicate_Attempt_Repository( $this->wpdb() );
		$result = $this->service( $repository )->match_subscription( $this->subscription['id'] );

		$this->assertResult( 'reconciliation_required', 'attempt_not_unique', $result );
	}

	public function test_customer_environment_merchant_location_and_plan_mismatches_require_reconciliation(): void {
		$this->insert_attempt();
		$this->subscription['customer_id'] = 'other-customer';
		$this->assertResult( 'reconciliation_required', 'attempt_subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );

		$this->subscription['customer_id'] = 'sub-match-customer';
		$this->delete_attempts();
		$this->insert_attempt( array( 'square_customer_id' => null ) );
		$this->assertResult( 'reconciliation_required', 'attempt_subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );

		foreach ( array(
			array( 'environment' => 'production' ),
			array( 'merchant_id' => 'other-merchant' ),
			array( 'square_location_id' => 'other-location' ),
			array( 'subscription_plan_variation_id' => 'other-plan' ),
		) as $override ) {
			$this->delete_attempts();
			$this->insert_attempt( $override );
			$this->assertResult( 'reconciliation_required', 'attempt_subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
		}
	}

	public function test_subscription_location_and_plan_mismatch_require_reconciliation(): void {
		$this->insert_attempt();
		$this->subscription['location_id'] = 'other-location';
		$this->assertResult( 'reconciliation_required', 'subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );

		$this->subscription['location_id'] = 'sub-match-location';
		$this->subscription['plan_variation_id'] = 'other-plan';
		$this->assertResult( 'reconciliation_required', 'subscription_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_invoice_or_order_location_mismatch_requires_reconciliation(): void {
		$this->insert_attempt();
		$this->invoices[ $this->subscription['invoice_ids'][0] ]['location_id'] = 'other-location';
		$this->assertResult( 'reconciliation_required', 'invoice_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );

		$this->invoices[ $this->subscription['invoice_ids'][0] ]['location_id'] = 'sub-match-location';
		$this->orders[ $this->payment['order_id'] ]['location_id'] = 'other-location';
		$this->assertResult( 'reconciliation_required', 'invoice_order_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_initial_payment_must_be_completed_and_verified(): void {
		$this->insert_attempt();
		$this->payment['status'] = 'APPROVED';
		$this->assertResult( 'subscription_pending', 'initial_payment_not_confirmed', $this->service()->match_subscription( $this->subscription['id'] ) );

		$this->payment['status'] = 'COMPLETED';
		$this->payment['amount_money']['amount'] = 499;
		$this->assertResult( 'reconciliation_required', 'initial_payment_not_verified', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_square_api_errors_are_distinct_from_pending(): void {
		$this->insert_attempt();
		$this->http_status = 503;
		$this->assertResult( 'square_api_error', 'subscription_api_unavailable', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_every_invoice_is_checked_and_conflicting_invoice_is_rejected(): void {
		$this->insert_attempt();
		$extra_id = 'invoice-extra-' . wp_generate_uuid4();
		$this->subscription['invoice_ids'][] = $extra_id;
		$this->invoices[ $extra_id ] = array(
			'id' => $extra_id,
			'subscription_id' => 'not-this-subscription',
			'location_id' => 'sub-match-location',
			'order_id' => 'other-order',
			'status' => 'PAID',
		);
		$this->assertResult( 'reconciliation_required', 'invoice_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
		$invoice_urls = array_map(
			static function ( $call ) {
				return $call['url'];
			},
			array_values(
				array_filter(
					$this->http_calls,
					static function ( $call ) {
						return false !== strpos( $call['url'], '/v2/invoices/' );
					}
			)
			)
		);
		$this->assertContains( 'https://connect.squareupsandbox.com/v2/invoices/' . rawurlencode( $extra_id ), $invoice_urls );
	}

	public function test_unrelated_later_invoice_is_validated_without_becoming_checkout_match(): void {
		$this->insert_attempt();
		$extra_id = 'invoice-later-' . wp_generate_uuid4();
		$extra_order = 'later-order-' . wp_generate_uuid4();
		$this->subscription['invoice_ids'][] = $extra_id;
		$this->invoices[ $extra_id ] = array(
			'id' => $extra_id, 'subscription_id' => $this->subscription['id'], 'location_id' => 'sub-match-location',
			'order_id' => $extra_order, 'status' => 'PAID',
		);
		$this->orders[ $extra_order ] = array(
			'id' => $extra_order, 'location_id' => 'sub-match-location', 'state' => 'COMPLETED',
			'reference_id' => wp_generate_uuid4(),
		);

		$result = $this->service()->match_subscription( $this->subscription['id'] );
		$this->assertSame( 'subscription_matched', $result['status'] );
		$this->assertSame( 2, count( array_filter( $this->http_calls, static function ( $call ) { return false !== strpos( $call['url'], '/v2/invoices/' ); } ) ) );
	}

	public function test_missing_invoice_subscription_id_requires_reconciliation(): void {
		$this->insert_attempt();
		unset( $this->invoices[ $this->subscription['invoice_ids'][0] ]['subscription_id'] );
		$this->assertResult( 'reconciliation_required', 'invoice_identity_mismatch', $this->service()->match_subscription( $this->subscription['id'] ) );
	}

	public function test_invalid_subscription_id_is_rejected_without_square_request(): void {
		$result = $this->service()->match_subscription( '' );
		$this->assertResult( 'reconciliation_required', 'subscription_id_invalid', $result );
		$this->assertCount( 0, $this->http_calls );
	}

	private function service( Egov_Law_Monitor_Checkout_Repository $repository = null ): Egov_Law_Monitor_Square_Subscription_Attempt_Matcher {
		$client = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment' => 'sandbox',
			'access_token' => 'test-token',
			'merchant_id' => 'sub-match-merchant',
			'location_id' => 'sub-match-location',
			'catalog_item_variation_id' => 'sub-match-item',
			'subscription_plan_variation_id' => 'sub-match-plan',
		) );
		$repository = $repository ?: new Egov_Law_Monitor_Checkout_Repository( $this->wpdb() );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
		$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $payment_matcher );
		return new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $repository, $client, $confirmation );
	}

	private function insert_attempt( array $overrides = array() ): array {
		global $wpdb;
		$attempt_uuid = $this->orders[ $this->payment['order_id'] ]['reference_id'];
		$this->attempt_uuids[] = $attempt_uuid;
		$data = array_merge(
			array(
				'attempt_uuid' => $attempt_uuid,
				'user_id' => 1,
				'environment' => 'sandbox',
				'merchant_id' => 'sub-match-merchant',
				'square_location_id' => 'sub-match-location',
				'plan_code' => 'standard',
				'catalog_item_variation_id' => 'sub-match-item',
				'subscription_plan_variation_id' => 'sub-match-plan',
				'status' => 'pending',
				'idempotency_key' => wp_generate_uuid4(),
				'square_order_id' => $this->payment['order_id'],
				'square_customer_id' => 'sub-match-customer',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', $data ) );
		return $this->get_attempt_row( $attempt_uuid );
	}

	private function get_attempt_row( $uuid = null ): array {
		global $wpdb;
		$uuid = $uuid ?: $this->orders[ $this->payment['order_id'] ]['reference_id'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE attempt_uuid = %s", $uuid ), ARRAY_A );
		$this->assertIsArray( $row );
		return $row;
	}

	private function delete_attempts(): void {
		global $wpdb;
		foreach ( $this->attempt_uuids as $uuid ) {
			$wpdb->delete( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $uuid ), array( '%s' ) );
		}
		$this->attempt_uuids = array();
	}

	private function table_count( $suffix ): int {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private function assertResult( $status, $reason, $result ): void {
		$this->assertIsArray( $result );
		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $reason, $result['reason_code'] );
	}

	private function wpdb() {
		global $wpdb;
		return $wpdb;
	}
}

/** Test-only repository decorator for exercising a corrupt duplicate lookup. */
final class Egov_Law_Monitor_Test_Duplicate_Attempt_Repository extends Egov_Law_Monitor_Checkout_Repository {
	public function find_attempts_by_uuid( $attempt_uuid ) {
		$attempts = parent::find_attempts_by_uuid( $attempt_uuid );
		return is_array( $attempts ) && 1 === count( $attempts ) ? array( $attempts[0], $attempts[0] ) : $attempts;
	}
}
