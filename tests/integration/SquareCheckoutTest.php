<?php

use PHPUnit\Framework\TestCase;

final class SquareCheckoutTest extends TestCase {
	private $user_id;
	private $http_filter;
	private $service_filter;
	private $http_calls = array();
	private $link = array(
		'payment_link_id' => 'test-payment-link',
		'order_id'        => 'test-order',
		'url'             => 'https://square.link/u/test-checkout',
	);
	private $http_mode = 'success';
	private $post_attempts = 0;
	private $post_status = 200;
	private $get_status = 200;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->http_filter = function ( $preempt, $args, $url ) {
			$this->http_calls[] = array( 'args' => $args, 'url' => $url );
			if ( 'POST' === strtoupper( $args['method'] ?? '' ) ) {
				++$this->post_attempts;
				if ( 'first_timeout' === $this->http_mode && 1 === $this->post_attempts ) {
					return new WP_Error( 'http_request_failed', 'simulated timeout' );
				}
				if ( 200 === $this->post_status ) {
					$payload = json_decode( (string) ( $args['body'] ?? '' ), true );
					$id_suffix = substr( hash( 'sha256', (string) ( $payload['idempotency_key'] ?? '' ) ), 0, 20 );
					$this->link = array(
						'payment_link_id' => 'test-link-' . $id_suffix,
						'order_id'        => 'test-order-' . $id_suffix,
						'url'             => 'https://square.link/u/test-' . $id_suffix,
					);
				}
				$body = 200 === $this->post_status
					? array(
					'payment_link' => array(
						'id'       => $this->link['payment_link_id'],
						'order_id' => $this->link['order_id'],
						'url'      => $this->link['url'],
					),
					)
					: array( 'errors' => array( array( 'code' => 'INVALID_REQUEST_ERROR' ) ) );
			} else {
				$body = array(
					'payment_link' => array(
						'id'       => $this->link['payment_link_id'],
						'order_id' => $this->link['order_id'],
						'url'      => $this->link['url'],
					),
				);
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => 'GET' === strtoupper( $args['method'] ?? '' ) ? $this->get_status : $this->post_status, 'message' => 'mocked' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
		$this->service_filter = function ( $service, $wpdb_instance ) {
			$config = array(
				'environment'                   => 'sandbox',
				'access_token'                  => 'test-token-never-logged',
				'merchant_id'                   => 'test-merchant',
				'location_id'                   => 'test-location',
				'catalog_item_variation_id'      => 'test-item-variation',
				'subscription_plan_variation_id' => 'test-plan-variation',
			);
			return new Egov_Law_Monitor_Square_Checkout_Service(
				new Egov_Law_Monitor_Checkout_Repository( $wpdb_instance ),
				new Egov_Law_Monitor_Square_Api_Client( $config )
			);
		};
		add_filter( 'egov_law_monitor_square_checkout_service_override', $this->service_filter, 10, 2 );

		$suffix = wp_generate_password( 10, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'checkout-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'checkout-' . $suffix . '@example.org',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->user_id = (int) $user_id;
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		remove_filter( 'egov_law_monitor_square_checkout_service_override', $this->service_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$attempts = $wpdb->prefix . 'egov_law_checkout_attempts';
			$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
			$invoices = $wpdb->prefix . 'egov_law_subscription_invoices';
			$wpdb->delete( $attempts, array( 'user_id' => $this->user_id ), array( '%d' ) );
			$subscription_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$subscriptions} WHERE user_id = %d", $this->user_id ) );
			foreach ( $subscription_ids as $subscription_id ) {
				$wpdb->delete( $invoices, array( 'subscription_id' => (int) $subscription_id ), array( '%d' ) );
			}
			$wpdb->delete( $subscriptions, array( 'user_id' => $this->user_id ), array( '%d' ) );
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_authenticated_user_creates_checkout_without_trusting_request_fields_or_granting_standard(): void {
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout(
			array(
				'user_id'       => 999999,
				'amount'        => 1,
				'environment'   => 'production',
				'plan_code'     => 'free',
				'subscription_plan_id' => 'attacker-value',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->link['url'], $response->get_data()['checkout_url'] );
		$this->assertSame( 1, $this->post_attempts );
		$payload = json_decode( $this->http_calls[0]['args']['body'], true );
		$this->assertSame( 500, $payload['order']['line_items'][0]['base_price_money']['amount'] );
		$this->assertSame( 'test-plan-variation', $payload['checkout_options']['subscription_plan_id'] );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links', $this->http_calls[0]['url'] );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE user_id = %d", $this->user_id ), ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( 'sandbox', $rows[0]['environment'] );
		$this->assertSame( 'standard', $rows[0]['plan_code'] );
		$this->assertSame( $this->user_id, (int) $rows[0]['user_id'] );
		$this->assertSame( $this->link['payment_link_id'], $rows[0]['square_payment_link_id'] );
		$this->assertSame( $this->link['order_id'], $rows[0]['square_order_id'] );
		$this->assertSame( $this->link['url'], $rows[0]['payment_url'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/i', $rows[0]['attempt_uuid'] );
		$this->assertNotSame( '', $rows[0]['idempotency_key'] );
		$entitlement = egov_law_monitor_get_effective_entitlement( $this->user_id );
		$this->assertSame( 'free', $entitlement['effective_plan'], 'Payment Link creation must not activate Standard.' );
	}

	public function test_active_standard_contract_is_rejected_before_square_request(): void {
		$this->add_active_contract();
		$this->assertSame( 'standard', egov_law_monitor_get_effective_entitlement( $this->user_id )['effective_plan'] );
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout();
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertStringNotContainsString( 'entitlement_', wp_json_encode( $response->get_data() ) );
	}

	public function test_existing_pending_link_is_reused_only_after_square_retrieval(): void {
		$row = $this->insert_attempt( 'pending' );
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->link['url'], $response->get_data()['checkout_url'] );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( rawurlencode( $this->link['payment_link_id'] ), $this->http_calls[0]['url'] );
		$this->assertSame( 'pending', $this->get_attempt( $row['id'] )['status'] );
		$second_response = $this->dispatch_checkout();
		$this->assertSame( 200, $second_response->get_status() );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertSame( 2, count( $this->http_calls ), 'Each reuse is verified with Square rather than blindly returning the stored URL.' );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE user_id = %d", $this->user_id ) ) );
	}

	public function test_unverified_old_link_is_not_returned_or_replaced_on_ambiguous_square_error(): void {
		$this->insert_attempt( 'pending' );
		$this->get_status = 503;
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout();
		$this->assertSame( 503, $response->get_status() );
		$this->assertArrayNotHasKey( 'checkout_url', $response->get_data() );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertCount( 1, $this->http_calls );
	}

	public function test_creating_attempt_retries_with_the_same_uuid_and_idempotency_key(): void {
		$row = $this->insert_attempt( 'creating' );
		$this->http_mode = 'first_timeout';
		wp_set_current_user( $this->user_id );
		$first = $this->dispatch_checkout();
		$this->assertSame( 502, $first->get_status() );
		$after_first = $this->get_attempt( $row['id'] );
		$this->assertSame( 'creating', $after_first['status'] );
		$this->assertSame( 'square_http_request_failed', $after_first['last_error_code'] );

		$second = $this->dispatch_checkout();
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 2, $this->post_attempts );
		$this->assertSame( $this->http_calls[0]['args']['headers']['Authorization'], $this->http_calls[1]['args']['headers']['Authorization'] );
		$payload_one = json_decode( $this->http_calls[0]['args']['body'], true );
		$payload_two = json_decode( $this->http_calls[1]['args']['body'], true );
		$this->assertSame( $payload_one['idempotency_key'], $payload_two['idempotency_key'] );
		$this->assertSame( $payload_one['order']['reference_id'], $payload_two['order']['reference_id'] );
		$this->assertSame( 'pending', $this->get_attempt( $row['id'] )['status'] );
	}

	public function test_definite_square_api_error_marks_attempt_failed_without_exposing_internal_error(): void {
		$this->post_status = 400;
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout();
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'checkout_unavailable', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'square_api_error', wp_json_encode( $response->get_data() ) );
		$this->assertArrayNotHasKey( 'checkout_url', $response->get_data() );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_error_code FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE user_id = %d", $this->user_id ), ARRAY_A );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'square_api_error', $row['last_error_code'] );
	}

	public function test_missing_login_and_invalid_nonce_are_rejected(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dispatch_checkout()->get_status() );
		wp_set_current_user( $this->user_id );
		$this->assertContains( $this->dispatch_checkout( array(), 'invalid-nonce' )->get_status(), array( 401, 403 ) );
		$this->assertSame( 0, $this->post_attempts );
	}

	public function test_square_link_404_requires_reconciliation_and_blocks_new_attempts(): void {
		$old = $this->insert_attempt( 'pending' );
		$this->get_status = 404;
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch_checkout();
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'checkout_status_pending', $response->get_data()['code'] );
		$this->assertStringContainsString( '決済状況を確認中', $response->get_data()['message'] );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertSame( 1, count( $this->http_calls ) );
		$this->assertSame( 'reconciliation_required', $this->get_attempt( $old['id'] )['status'] );
		$this->assertSame( 'square_payment_link_not_found', $this->get_attempt( $old['id'] )['last_error_code'] );

		$retry_response = $this->dispatch_checkout();
		$this->assertSame( 503, $retry_response->get_status() );
		$this->assertSame( 'checkout_status_pending', $retry_response->get_data()['code'] );
		$this->assertSame( 0, $this->post_attempts );
		$this->assertSame( 1, count( $this->http_calls ), 'A reconciliation-required attempt must not call Square again.' );
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE user_id = %d", $this->user_id ) );
		$this->assertSame( 1, (int) $count );
	}

	public function test_creating_retry_link_404_requires_reconciliation_without_changing_idempotency_key(): void {
		$attempt = $this->insert_attempt( 'creating' );
		$this->get_status = 404;
		wp_set_current_user( $this->user_id );

		$response = $this->dispatch_checkout();
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'checkout_status_pending', $response->get_data()['code'] );
		$this->assertSame( 1, $this->post_attempts );
		$this->assertSame( 2, count( $this->http_calls ) );
		$this->assertSame( 'reconciliation_required', $this->get_attempt( $attempt['id'] )['status'] );
		$this->assertSame( $attempt['idempotency_key'], $this->get_attempt( $attempt['id'] )['idempotency_key'] );

		$retry_response = $this->dispatch_checkout();
		$this->assertSame( 503, $retry_response->get_status() );
		$this->assertSame( 1, $this->post_attempts );
		$this->assertSame( 2, count( $this->http_calls ) );
	}

	private function dispatch_checkout( array $params = array(), $nonce = null ) {
		$request = new WP_REST_Request( 'POST', '/egov-law-monitor/v1/subscription-checkout' );
		$request->set_header( 'X-WP-Nonce', null === $nonce ? wp_create_nonce( 'wp_rest' ) : $nonce );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function insert_attempt( $status ) {
		global $wpdb;
		$id = wp_generate_uuid4();
		$key = wp_generate_uuid4();
		$data = array(
			'attempt_uuid' => $id,
			'user_id' => $this->user_id,
			'environment' => 'sandbox',
			'merchant_id' => 'test-merchant',
			'square_location_id' => 'test-location',
			'plan_code' => 'standard',
			'catalog_item_variation_id' => 'test-item-variation',
			'subscription_plan_variation_id' => 'test-plan-variation',
			'status' => $status,
			'idempotency_key' => $key,
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( 'pending' === $status ) {
			$data['square_payment_link_id'] = $this->link['payment_link_id'];
			$data['square_order_id'] = $this->link['order_id'];
			$data['payment_url'] = $this->link['url'];
		}
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', $data ) );
		return array( 'id' => (int) $wpdb->insert_id, 'attempt_uuid' => $id, 'idempotency_key' => $key );
	}

	private function get_attempt( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $id ), ARRAY_A );
	}

	private function add_active_contract(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'egov_law_subscriptions',
			array(
				'user_id' => $this->user_id,
				'environment' => 'production',
				'merchant_id' => 'test-merchant',
				'square_location_id' => 'test-location',
				'square_customer_id' => 'test-customer-' . $this->user_id,
				'square_subscription_id' => 'test-subscription-' . $this->user_id,
				'plan_code' => 'standard',
				'internal_status' => 'active',
				'square_status' => 'ACTIVE',
				'initial_payment_status' => 'succeeded',
				'current_slot' => 1,
				'current_period_start' => $now,
				'current_period_end' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 30 ),
				'sync_state' => 'ok',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->assertSame( 1, $inserted );
	}
}
