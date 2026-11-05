<?php

use PHPUnit\Framework\TestCase;

final class SquarePaymentAttemptMatcherTest extends TestCase {
	private $attempt_uuids = array();
	private $http_filter;
	private $http_calls = array();
	private $payment = array();
	private $order = array();
	private $transport_error = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->payment = array(
			'id'          => 'matcher-payment-' . wp_generate_uuid4(),
			'order_id'    => 'matcher-order-' . wp_generate_uuid4(),
			'customer_id' => 'matcher-customer',
			'location_id' => 'matcher-location',
			'status'      => 'COMPLETED',
			'created_at'  => '2026-11-05T00:00:00Z',
		);
		$this->order = array(
			'id'           => $this->payment['order_id'],
			'reference_id' => wp_generate_uuid4(),
			'location_id'  => 'matcher-location',
			'state'        => 'OPEN',
			'version'      => 1,
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

	public function test_matches_completed_payment_order_and_pending_attempt(): void {
		$user_id = 1;
		$this->insert_attempt( $user_id );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertSame(
			array(
				'user_id'      => $user_id,
				'attempt_uuid' => $this->order['reference_id'],
				'payment_id'   => $this->payment['id'],
				'order_id'     => $this->payment['order_id'],
				'environment'  => 'sandbox',
				'merchant_id'  => 'matcher-merchant',
				'customer_id'  => 'matcher-customer',
			),
			$result
		);
		$this->assertSame( 2, count( $this->http_calls ) );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/payments/' . rawurlencode( $this->payment['id'] ), $this->http_calls[0]['url'] );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/orders/' . rawurlencode( $this->payment['order_id'] ), $this->http_calls[1]['url'] );
	}

	public function test_matches_payment_without_customer_id(): void {
		unset( $this->payment['customer_id'] );
		$this->insert_attempt( 1 );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayNotHasKey( 'customer_id', $result );
	}

	public function test_matches_reconciliation_required_attempt_but_rejects_other_states(): void {
		$this->insert_attempt( 1, 'reconciliation_required' );
		$this->assertFalse( is_wp_error( $this->matcher()->match_payment( $this->payment['id'] ) ) );

		$this->delete_attempts();
		$this->insert_attempt( 1, 'creating' );
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );
	}

	public function test_rejects_uncompleted_payment_and_does_not_fetch_order(): void {
		$this->payment['status'] = 'APPROVED';
		$this->insert_attempt( 1 );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_payment_not_completed', $result->get_error_code() );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_rejects_payment_without_order_id(): void {
		unset( $this->payment['order_id'] );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_payment_unavailable', $result->get_error_code() );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_rejects_order_without_reference_id(): void {
		unset( $this->order['reference_id'] );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_order_mismatch', $result->get_error_code() );
	}

	public function test_rejects_missing_or_multiple_attempt_candidates(): void {
		$missing = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $missing ) );
		$this->assertSame( 'payment_attempt_not_unique', $missing->get_error_code() );

		$row = $this->insert_attempt( 1 );
		$repository = new Egov_Test_Duplicate_Attempt_Repository( $GLOBALS['wpdb'], array( $row, $row ) );
		$duplicate_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $repository, $this->client() );
		$multiple = $duplicate_matcher->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $multiple ) );
		$this->assertSame( 'payment_attempt_not_unique', $multiple->get_error_code() );
	}

	public function test_rejects_order_id_mismatch(): void {
		$this->insert_attempt( 1 );
		$this->attempts_table_update( array( 'square_order_id' => 'different-order' ) );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );
	}

	public function test_rejects_square_order_response_with_a_different_id(): void {
		$this->insert_attempt( 1 );
		$this->order['id'] = 'different-square-order';
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_order_unavailable', $result->get_error_code() );
	}

	public function test_rejects_payment_order_and_config_location_mismatches(): void {
		$this->insert_attempt( 1 );
		$this->order['location_id'] = 'other-location';
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );

		$this->order['location_id'] = $this->payment['location_id'];
		$this->payment['location_id'] = 'other-location';
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );

		$this->payment['location_id'] = 'matcher-location';
		$this->order['location_id'] = 'matcher-location';
		$this->attempts_table_update( array( 'square_location_id' => 'other-location' ) );
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );
	}

	public function test_rejects_attempt_environment_or_merchant_mismatch(): void {
		$this->insert_attempt( 1, 'pending', array( 'environment' => 'production' ) );
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );

		$this->delete_attempts();
		$this->insert_attempt( 1, 'pending', array( 'merchant_id' => 'other-merchant' ) );
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_mismatch', $result->get_error_code() );
	}

	public function test_rejects_missing_wordpress_user(): void {
		$this->insert_attempt( 99999999 );
		$result = $this->matcher()->match_payment( $this->payment['id'] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_user_unavailable', $result->get_error_code() );
	}

	public function test_square_transport_failure_is_safe_and_does_not_change_attempt(): void {
		$this->insert_attempt( 1 );
		$before = $this->get_attempt_row();
		$this->transport_error = new WP_Error( 'http_request_failed', 'private transport detail' );
		$result = $this->matcher()->match_payment( $this->payment['id'] );
		$after = $this->get_attempt_row();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_payment_unavailable', $result->get_error_code() );
		$this->assertStringNotContainsString( 'private transport detail', $result->get_error_message() );
		$this->assertSame( $before, $after );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_rejects_invalid_payment_id_without_http_or_db_changes(): void {
		$this->insert_attempt( 1 );
		$before = $this->get_attempt_row();
		$result = $this->matcher()->match_payment( '../invalid' );
		$after = $this->get_attempt_row();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'payment_attempt_payment_unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->http_calls );
		$this->assertSame( $before, $after );
	}

	private function matcher(): Egov_Law_Monitor_Square_Payment_Attempt_Matcher {
		return new Egov_Law_Monitor_Square_Payment_Attempt_Matcher(
			new Egov_Law_Monitor_Checkout_Repository( $GLOBALS['wpdb'] ),
			$this->client()
		);
	}

	private function client(): Egov_Law_Monitor_Square_Api_Client {
		return new Egov_Law_Monitor_Square_Api_Client(
			array(
				'environment'                    => 'sandbox',
				'access_token'                   => 'test-token-never-logged',
				'merchant_id'                    => 'matcher-merchant',
				'location_id'                    => 'matcher-location',
				'catalog_item_variation_id'       => 'matcher-item',
				'subscription_plan_variation_id'  => 'matcher-plan',
			)
		);
	}

	private function insert_attempt( $user_id, $attempt_status = 'pending', array $overrides = array() ) {
		global $wpdb;
		$attempt_uuid = $this->order['reference_id'];
		$this->attempt_uuids[] = $attempt_uuid;
		$data = array_merge(
			array(
				'attempt_uuid'                 => $attempt_uuid,
				'user_id'                      => $user_id,
				'environment'                  => 'sandbox',
				'merchant_id'                  => 'matcher-merchant',
				'square_location_id'            => 'matcher-location',
				'plan_code'                    => 'standard',
				'catalog_item_variation_id'      => 'matcher-item',
				'subscription_plan_variation_id' => 'matcher-plan',
				'status'                       => $attempt_status,
				'idempotency_key'              => wp_generate_uuid4(),
				'square_order_id'              => $this->payment['order_id'] ?? 'missing-payment-order',
				'created_at'                   => current_time( 'mysql', true ),
				'updated_at'                   => current_time( 'mysql', true ),
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', $data ) );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT id, attempt_uuid, user_id, environment, merchant_id, square_location_id, status, square_order_id FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE attempt_uuid = %s", $attempt_uuid ),
			ARRAY_A
		);
	}

	private function attempts_table_update( array $data ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_checkout_attempts', $data, array( 'attempt_uuid' => $this->order['reference_id'] ) );
	}

	private function get_attempt_row(): array {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE attempt_uuid = %s", $this->order['reference_id'] ),
			ARRAY_A
		);
	}

	private function delete_attempts(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $this->order['reference_id'] ), array( '%s' ) );
	}
}

final class Egov_Test_Duplicate_Attempt_Repository extends Egov_Law_Monitor_Checkout_Repository {
	private $rows;

	public function __construct( $wpdb, array $rows ) {
		parent::__construct( $wpdb );
		$this->rows = $rows;
	}

	public function find_attempts_by_uuid( $attempt_uuid ) {
		return $this->rows;
	}
}
