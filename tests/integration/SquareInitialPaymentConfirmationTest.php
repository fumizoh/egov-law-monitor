<?php

use PHPUnit\Framework\TestCase;

final class SquareInitialPaymentConfirmationTest extends TestCase {
	private $attempt_uuids = array();
	private $http_filter;
	private $http_calls = array();
	private $payment = array();
	private $order = array();
	private $response_status = 200;
	private $transport_error = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->payment = array(
			'id'           => 'confirm-payment-' . wp_generate_uuid4(),
			'order_id'     => 'confirm-order-' . wp_generate_uuid4(),
			'customer_id'  => 'confirm-customer',
			'location_id'  => 'confirm-location',
			'status'       => 'COMPLETED',
			'created_at'   => '2026-11-05T00:00:00Z',
			'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
			'total_money'  => array( 'amount' => 500, 'currency' => 'JPY' ),
		);
		$this->order = array(
			'id'           => $this->payment['order_id'],
			'reference_id' => wp_generate_uuid4(),
			'location_id'  => 'confirm-location',
			'state'        => 'OPEN',
			'version'      => 1,
			'tenders'      => array( array( 'payment_id' => $this->payment['id'] ) ),
			'total_money'  => array( 'amount' => 500, 'currency' => 'JPY' ),
		);

		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = array( 'args' => $args, 'url' => $url );
			if ( is_wp_error( $this->transport_error ) ) {
				return $this->transport_error;
			}
			$body = false !== strpos( $url, '/v2/payments/' )
				? array( 'payment' => $this->payment )
				: array( 'order' => $this->order );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $this->response_status, 'message' => 'mocked' ),
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

	public function test_confirms_single_completed_500_jpy_payment_and_is_read_only(): void {
		$attempt = $this->insert_attempt();
		$before  = $this->get_attempt_row();
		$service = $this->service();

		$result = $service->confirm_attempt_payment( $attempt, 1 );

		$this->assertSame( 'payment_confirmed', $result['status'] );
		$this->assertSame( 'payment_completed_and_verified', $result['reason_code'] );
		$this->assertSame( 1, $result['user_id'] );
		$this->assertSame( $this->order['reference_id'], $result['attempt_uuid'] );
		$this->assertSame( $this->payment['id'], $result['payment_id'] );
		$this->assertSame( $this->payment['order_id'], $result['order_id'] );
		$this->assertSame( 500, $result['amount'] );
		$this->assertSame( 'JPY', $result['currency'] );
		$this->assertSame( 'confirm-customer', $result['customer_id'] );
		$this->assertSame( $before, $this->get_attempt_row() );
	}

	public function test_missing_tender_is_pending(): void {
		$this->order['tenders'] = array();
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'payment_pending', $result['status'] );
		$this->assertSame( 'payment_tender_not_available', $result['reason_code'] );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_uncompleted_payment_is_pending(): void {
		$this->payment['status'] = 'APPROVED';
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'payment_pending', $result['status'] );
		$this->assertSame( 'payment_not_completed', $result['reason_code'] );
	}

	public function test_missing_tender_payment_id_requires_reconciliation(): void {
		$this->order['tenders'] = array( array( 'payment_id' => null ) );
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'tender_payment_id_missing', $result['reason_code'] );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_multiple_tenders_require_reconciliation_even_when_same_payment_repeats(): void {
		$this->order['tenders'] = array(
			array( 'payment_id' => $this->payment['id'] ),
			array( 'payment_id' => $this->payment['id'] ),
		);
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'multiple_or_unexpected_tenders', $result['reason_code'] );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_order_identity_and_location_mismatches_require_reconciliation(): void {
		$attempt = $this->insert_attempt();
		$service = $this->service();

		$this->order['id'] = 'different-order-id';
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->order['id'] = $this->payment['order_id'];
		$this->order['reference_id'] = wp_generate_uuid4();
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->order['reference_id'] = $attempt['attempt_uuid'];
		$this->order['location_id'] = 'different-location';
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );
	}

	public function test_payment_location_mismatch_requires_reconciliation(): void {
		$this->payment['location_id'] = 'different-location';
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'payment_identity_mismatch', $result['reason_code'] );
	}

	public function test_attempt_environment_and_merchant_mismatches_require_reconciliation(): void {
		$attempt = $this->insert_attempt( array( 'environment' => 'production' ) );
		$result = $this->service()->confirm_attempt_payment( $attempt, 1 );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertSame( 'attempt_scope_mismatch', $result['reason_code'] );
		$this->assertCount( 0, $this->http_calls );

		$this->delete_current_attempt();
		$attempt = $this->insert_attempt( array( 'merchant_id' => 'different-merchant' ) );
		$result = $this->service()->confirm_attempt_payment( $attempt, 1 );
		$this->assertSame( 'reconciliation_required', $result['status'] );
		$this->assertCount( 0, $this->http_calls );
	}

	public function test_order_and_payment_amount_or_currency_mismatch_or_missing_data_require_reconciliation(): void {
		$attempt = $this->insert_attempt();
		$service = $this->service();

		$this->order['total_money']['amount'] = 499;
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->order['total_money']['amount'] = 500;
		$this->order['total_money']['currency'] = 'USD';
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->order['total_money'] = array( 'amount' => 500 );
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->order['total_money'] = array( 'amount' => 500, 'currency' => 'JPY' );
		$this->payment['amount_money']['amount'] = 501;
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		$this->payment['amount_money'] = array( 'amount' => 500, 'currency' => 'USD' );
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );

		unset( $this->payment['total_money'] );
		$this->payment['amount_money'] = array( 'amount' => 500, 'currency' => 'JPY' );
		$this->assertSame( 'reconciliation_required', $service->confirm_attempt_payment( $attempt, 1 )['status'] );
	}

	public function test_square_api_failure_is_not_treated_as_unpaid(): void {
		$this->response_status = 503;
		$result = $this->service()->confirm_attempt_payment( $this->insert_attempt(), 1 );

		$this->assertSame( 'square_api_error', $result['status'] );
		$this->assertSame( 'order_api_unavailable', $result['reason_code'] );
	}

	public function test_attempt_snapshot_and_requesting_user_must_match_persisted_owner(): void {
		$attempt = $this->insert_attempt();
		$forged  = $attempt;
		$forged['user_id'] = '2';
		$service = $this->service();

		$this->assertSafeWpError( $service->confirm_attempt_payment( $forged, 1 ), 'payment_confirmation_attempt_mismatch' );
		$this->assertSafeWpError( $service->confirm_attempt_payment( $attempt, 2 ), 'payment_confirmation_attempt_forbidden' );
		$this->assertCount( 0, $this->http_calls );
	}

	public function test_repeated_confirmation_does_not_change_attempt_row(): void {
		$attempt = $this->insert_attempt();
		$before  = $this->get_attempt_row();
		$service = $this->service();

		$first  = $service->confirm_attempt_payment( $attempt, 1 );
		$second = $service->confirm_attempt_payment( $attempt, 1 );

		$this->assertSame( 'payment_confirmed', $first['status'] );
		$this->assertSame( 'payment_confirmed', $second['status'] );
		$this->assertSame( $before, $this->get_attempt_row() );
	}

	private function service(): Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service {
		global $wpdb;
		$client    = new Egov_Law_Monitor_Square_Api_Client( array(
			'environment'                  => 'sandbox',
			'access_token'                 => 'test-token',
			'merchant_id'                  => 'confirm-merchant',
			'location_id'                  => 'confirm-location',
			'catalog_item_variation_id'    => 'confirm-item',
			'subscription_plan_variation_id' => 'confirm-plan',
		) );
		$repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
		$matcher    = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $client );
		return new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $repository, $client, $matcher );
	}

	private function insert_attempt( array $overrides = array() ): array {
		global $wpdb;
		$uuid = $this->order['reference_id'];
		$this->attempt_uuids[] = $uuid;
		$data = array_merge(
			array(
				'attempt_uuid'                    => $uuid,
				'user_id'                         => 1,
				'environment'                     => 'sandbox',
				'merchant_id'                     => 'confirm-merchant',
				'square_location_id'               => 'confirm-location',
				'plan_code'                       => 'standard',
				'catalog_item_variation_id'         => 'confirm-item',
				'subscription_plan_variation_id'    => 'confirm-plan',
				'status'                          => 'pending',
				'idempotency_key'                 => wp_generate_uuid4(),
				'square_order_id'                 => $this->payment['order_id'],
				'created_at'                      => current_time( 'mysql', true ),
				'updated_at'                      => current_time( 'mysql', true ),
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', $data ) );
		$attempt = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE attempt_uuid = %s", $uuid ),
			ARRAY_A
		);
		$this->assertIsArray( $attempt );
		return $attempt;
	}

	private function get_attempt_row(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE attempt_uuid = %s", $this->order['reference_id'] ),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		return $row;
	}

	private function delete_current_attempt(): void {
		global $wpdb;
		$uuid = $this->order['reference_id'];
		$wpdb->delete( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $uuid ), array( '%s' ) );
		$this->attempt_uuids = array_values( array_diff( $this->attempt_uuids, array( $uuid ) ) );
	}

	private function assertSafeWpError( $result, $code ): void {
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
