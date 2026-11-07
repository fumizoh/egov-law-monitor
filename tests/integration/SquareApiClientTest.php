<?php

use PHPUnit\Framework\TestCase;

final class SquareApiClientTest extends TestCase {
	private $filter;
	private $request;
	private $request_count = 0;
	private $response_body;
	private $response_status = 200;
	private $response_headers = array();
	private $transport_error = null;
	private $response_resolver = null;

	protected function setUp(): void {
		parent::setUp();

		$this->response_body = array(
			'payment_link' => array(
				'id'       => 'sandbox-link-id',
				'order_id' => 'sandbox-order-id',
				'url'      => 'https://sandbox.square.link/u/test-link',
			),
		);
		$this->response_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		$this->filter = function ( $preempt, $args, $url ) {
			++$this->request_count;
			$this->request = array( 'args' => $args, 'url' => $url );
			if ( is_wp_error( $this->transport_error ) ) {
				return $this->transport_error;
			}
			$body = $this->response_body;
			$status = $this->response_status;
			if ( is_callable( $this->response_resolver ) ) {
				$resolved = call_user_func( $this->response_resolver, $url, $args );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
			if ( is_array( $resolved ) && array_key_exists( 'body', $resolved ) ) {
					$body = $resolved['body'];
					$status = $resolved['status'] ?? 200;
				}
			}

			return array(
				'headers'  => $this->response_headers,
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array( 'code' => $status, 'message' => 'mocked' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
		parent::tearDown();
	}

	public function test_sandbox_request_uses_server_values_fixed_price_and_returns_link_ids(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'attempt-idempotency-key' );

		$this->assertSame(
			array(
				'payment_link_id' => 'sandbox-link-id',
				'order_id'        => 'sandbox-order-id',
				'url'             => 'https://sandbox.square.link/u/test-link',
			),
			$result
		);
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links', $this->request['url'] );
		$this->assertSame( 'Bearer test-sandbox-token', $this->request['args']['headers']['Authorization'] );
		$this->assertSame( '2026-09-16', $this->request['args']['headers']['Square-Version'] );

		$payload = json_decode( $this->request['args']['body'], true );
		$this->assertSame( 'attempt-idempotency-key', $payload['idempotency_key'] );
		$this->assertSame( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', $payload['order']['reference_id'] );
		$this->assertSame( 'test-location', $payload['order']['location_id'] );
		$this->assertSame( array( 'auto_apply_taxes' => true ), $payload['order']['pricing_options'] );
		$this->assertSame( 'test-item-variation', $payload['order']['line_items'][0]['catalog_object_id'] );
		$this->assertSame( 500, $payload['order']['line_items'][0]['base_price_money']['amount'] );
		$this->assertSame( 'JPY', $payload['order']['line_items'][0]['base_price_money']['currency'] );
		$this->assertSame( 'test-subscription-variation', $payload['checkout_options']['subscription_plan_id'] );
		$this->assertArrayNotHasKey( 'quick_pay', $payload );
	}

	public function test_invalid_environment_does_not_call_square(): void {
		$config               = $this->config( 'invalid' );
		$config['environment'] = 'invalid';
		$client               = new Egov_Law_Monitor_Square_Api_Client( $config );
		$result               = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'attempt-idempotency-key' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_configuration_invalid', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}

	public function test_production_host_is_selected_only_for_explicit_production_configuration(): void {
		$this->response_body['payment_link']['url'] = 'https://square.link/u/test-link';
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'production' ) );
		$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'production-config-test-key' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'https://connect.squareup.com/v2/online-checkout/payment-links', $this->request['url'] );
		$this->assertSame( 1, $this->request_count );
	}

	public function test_production_accepts_existing_checkout_square_site_host(): void {
		$this->response_body['payment_link']['url'] = 'https://checkout.square.site/pay/test-link';
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'production' ) );
		$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'production-checkout-site-key' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'https://checkout.square.site/pay/test-link', $result['url'] );
	}

	public function test_production_rejects_sandbox_payment_link_host(): void {
		$this->response_body['payment_link']['url'] = 'https://sandbox.square.link/u/test-link';
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'production' ) );
		$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'production-sandbox-link-key' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_response_invalid', $result->get_error_code() );
	}

	public function test_retrieve_subscription_uses_sandbox_and_returns_validated_non_sensitive_fields(): void {
		$this->response_body = array(
			'subscription' => array(
				'id'                   => 'subscription-123',
				'location_id'          => 'location-123',
				'plan_variation_id'    => 'variation-123',
				'customer_id'          => 'customer-123',
				'status'               => 'ACTIVE',
				'start_date'           => '2026-11-05',
				'charged_through_date' => '2026-12-04',
				'invoice_ids'          => array( 'invoice-123' ),
				'version'              => 1,
				'card_id'              => 'private-card-id',
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_subscription( 'subscription-123' );

		$this->assertSame( 'subscription-123', $result['id'] );
		$this->assertSame( 'location-123', $result['location_id'] );
		$this->assertSame( 'variation-123', $result['plan_variation_id'] );
		$this->assertSame( 'customer-123', $result['customer_id'] );
		$this->assertSame( 'ACTIVE', $result['status'] );
		$this->assertSame( array( 'invoice-123' ), $result['invoice_ids'] );
		$this->assertArrayNotHasKey( 'card_id', $result );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/subscriptions/subscription-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertSame( 'Bearer test-sandbox-token', $this->request['args']['headers']['Authorization'] );
		$this->assertSame( '2026-09-16', $this->request['args']['headers']['Square-Version'] );
	}

	public function test_get_invoice_uses_sandbox_and_returns_validated_non_sensitive_fields(): void {
		$this->response_body = array(
			'invoice' => array(
				'id'          => 'invoice-123',
				'location_id' => 'location-123',
				'order_id'    => 'order-123',
				'status'      => 'PAID',
				'version'     => 2,
				'created_at'  => '2026-11-05T00:00:00Z',
				'primary_recipient' => array( 'email_address' => 'private@example.test' ),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_invoice( 'invoice-123' );

		$this->assertSame( 'invoice-123', $result['id'] );
		$this->assertSame( 'location-123', $result['location_id'] );
		$this->assertSame( 'order-123', $result['order_id'] );
		$this->assertSame( 'PAID', $result['status'] );
		$this->assertArrayNotHasKey( 'primary_recipient', $result );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/invoices/invoice-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertSame( 2, $result['version'] );
	}

	public function test_subscription_preserves_raw_unknown_status_and_nullable_version(): void {
		$this->response_body = array(
			'subscription' => array(
				'id'                => 'subscription-123',
				'location_id'       => 'location-123',
				'plan_variation_id' => 'variation-123',
				'customer_id'       => 'customer-123',
				'status'            => 'FUTURE_STATUS',
				'start_date'        => '2026-11-05',
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_subscription( 'subscription-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'FUTURE_STATUS', $result['status'] );
		$this->assertFalse( $result['status_known'] );
		$this->assertNull( $result['version'] );
		$this->assertSame( '2026-11-05', $result['start_date'] );
		$this->assertArrayNotHasKey( 'charged_through_date', $result );
	}

	public function test_subscription_rejects_invalid_version_and_anchor_types(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$base = array( 'id' => 'subscription-123', 'location_id' => 'location', 'plan_variation_id' => 'variation', 'customer_id' => 'customer', 'status' => 'ACTIVE' );
		$this->response_body = array( 'subscription' => $base + array( 'version' => '2' ) );
		$bad_version = $client->retrieve_subscription( 'subscription-123' );
		$this->assertTrue( is_wp_error( $bad_version ) );
		$this->assertSame( 'square_subscription_response_invalid', $bad_version->get_error_code() );

		$this->response_body = array( 'subscription' => $base + array( 'monthly_billing_anchor_date' => 32 ) );
		$bad_anchor = $client->retrieve_subscription( 'subscription-123' );
		$this->assertTrue( is_wp_error( $bad_anchor ) );
		$this->assertSame( 'square_subscription_response_invalid', $bad_anchor->get_error_code() );
	}

	public function test_subscription_and_invoice_reject_identity_and_version_mismatches(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_body = array(
			'subscription' => array( 'id' => 'other-id', 'location_id' => 'location', 'plan_variation_id' => 'variation', 'customer_id' => 'customer', 'status' => 'ACTIVE' ),
		);
		$subscription_mismatch = $client->retrieve_subscription( 'subscription-123' );
		$this->assertTrue( is_wp_error( $subscription_mismatch ) );
		$this->assertSame( 'square_subscription_response_invalid', $subscription_mismatch->get_error_code() );

		$this->response_body = array(
			'invoice' => array( 'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'UNPAID', 'version' => '4' ),
		);
		$invoice_version = $client->get_invoice( 'invoice-123' );
		$this->assertTrue( is_wp_error( $invoice_version ) );
		$this->assertSame( 'square_invoice_response_invalid', $invoice_version->get_error_code() );
	}

	public function test_invoice_normalizes_request_money_and_only_marks_fully_paid_with_complete_evidence(): void {
		$this->response_body = array(
			'invoice' => array(
				'id'              => 'invoice-123',
				'location_id'     => 'location-123',
				'order_id'        => 'order-123',
				'subscription_id' => 'subscription-123',
				'status'          => 'PAID',
				'version'         => 4,
				'updated_at'      => '2026-11-06T01:00:00Z',
				'payment_requests' => array(
					array(
						'uid'                           => 'request-1',
						'request_type'                  => 'BALANCE',
						'due_date'                      => '2026-11-06',
						'computed_amount_money'          => array( 'amount' => 500, 'currency' => 'JPY' ),
						'total_completed_amount_money'   => array( 'amount' => 500, 'currency' => 'JPY' ),
						'card_id'                       => 'private-card-id',
					),
				),
				'primary_recipient' => array( 'email_address' => 'private@example.test' ),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_invoice( 'invoice-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 4, $result['version'] );
		$this->assertSame( 'subscription-123', $result['subscription_id'] );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['requested_amount_money'] );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['completed_amount_money'] );
		$this->assertSame( 'paid', $result['payment_state'] );
		$this->assertTrue( $result['status_known'] );
		$this->assertNull( $result['completed_at'] );
		$this->assertArrayNotHasKey( 'card_id', $result['payment_requests'][0] );
		$this->assertArrayNotHasKey( 'primary_recipient', $result );
	}

	public function test_invoice_multiple_requests_sum_schedule_once_and_keep_partial_payment_distinct(): void {
		$this->response_body = array(
			'invoice' => array(
				'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'PARTIALLY_PAID',
				'payment_requests' => array(
					array( 'request_type' => 'DEPOSIT', 'due_date' => '2026-11-06', 'computed_amount_money' => array( 'amount' => 200, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 200, 'currency' => 'JPY' ) ),
					array( 'request_type' => 'BALANCE', 'due_date' => '2026-12-06', 'computed_amount_money' => array( 'amount' => 300, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 0, 'currency' => 'JPY' ) ),
				),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_invoice( 'invoice-123' );

		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['requested_amount_money'] );
		$this->assertSame( array( 'amount' => 200, 'currency' => 'JPY' ), $result['completed_amount_money'] );
		$this->assertSame( 'partially_paid', $result['payment_state'] );
		$this->assertCount( 2, $result['payment_requests'] );
	}

	public function test_invoice_statuses_are_distinguished_without_confusing_webhook_event_types(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$cases = array(
			'DRAFT' => 'planned', 'SCHEDULED' => 'scheduled', 'UNPAID' => 'unpaid',
			'PAYMENT_PENDING' => 'processing', 'PARTIALLY_PAID' => 'partially_paid',
			'PARTIALLY_REFUNDED' => 'partially_refunded', 'REFUNDED' => 'refunded',
			'CANCELED' => 'canceled', 'FAILED' => 'invoice_failed', 'NEW_STATUS' => 'unknown',
		);
		foreach ( $cases as $raw_status => $expected_state ) {
			$invoice = array( 'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => $raw_status );
			if ( 'PARTIALLY_PAID' === $raw_status ) {
				$invoice['payment_requests'] = array(
					array(
						'computed_amount_money'        => array( 'amount' => 500, 'currency' => 'JPY' ),
						'total_completed_amount_money' => array( 'amount' => 200, 'currency' => 'JPY' ),
					),
				);
			}
			$this->response_body = array( 'invoice' => $invoice );
			$result = $client->get_invoice( 'invoice-123' );
			$this->assertFalse( is_wp_error( $result ), $raw_status );
			$this->assertSame( $raw_status, $result['status'] );
			$this->assertSame( $expected_state, $result['payment_state'], $raw_status );
			$this->assertSame( 'NEW_STATUS' !== $raw_status, $result['status_known'] );
		}
	}

	public function test_invoice_missing_or_inconsistent_money_is_not_defaulted_or_marked_paid(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_body = array(
			'invoice' => array(
				'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'PAID',
				'payment_requests' => array( array( 'computed_amount_money' => array( 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 0, 'currency' => 'JPY' ) ) ),
			),
		);
		$missing = $client->get_invoice( 'invoice-123' );
		$this->assertNull( $missing['requested_amount_money'] );
		$this->assertSame( 'unknown', $missing['payment_state'] );

		$this->response_body = array(
			'invoice' => array(
				'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'PAID',
				'payment_requests' => array(
					array( 'computed_amount_money' => array( 'amount' => 200, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 200, 'currency' => 'JPY' ) ),
					array( 'computed_amount_money' => array( 'amount' => 300, 'currency' => 'USD' ), 'total_completed_amount_money' => array( 'amount' => 300, 'currency' => 'USD' ) ),
				),
			),
		);
		$mixed = $client->get_invoice( 'invoice-123' );
		$this->assertNull( $mixed['requested_amount_money'] );
		$this->assertSame( 'unknown', $mixed['payment_state'] );
	}

	public function test_invoice_rejects_malformed_money_and_request_shapes(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$invalid_payloads = array(
			array( 'payment_requests' => 'invalid' ),
			array( 'payment_requests' => array( 'invalid' ) ),
			array( 'payment_requests' => array( array( 'computed_amount_money' => array( 'amount' => '500', 'currency' => 'JPY' ) ) ) ),
			array( 'payment_requests' => array( array( 'computed_amount_money' => array( 'amount' => -1, 'currency' => 'JPY' ) ) ) ),
			array( 'payment_requests' => array( array( 'computed_amount_money' => array( 'amount' => 500, 'currency' => 'jpy' ) ) ) ),
		);
		foreach ( $invalid_payloads as $extra ) {
			$this->response_body = array( 'invoice' => array_merge( array( 'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'UNPAID' ), $extra ) );
			$result = $client->get_invoice( 'invoice-123' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'square_invoice_response_invalid', $result->get_error_code() );
		}
	}

	public function test_invoice_preserves_absent_values_as_unknown_and_missing_version_as_null(): void {
		$this->response_body = array( 'invoice' => array( 'id' => 'invoice-123', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'UNPAID' ) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_invoice( 'invoice-123' );

		$this->assertNull( $result['version'] );
		$this->assertSame( array(), $result['payment_requests'] );
		$this->assertNull( $result['requested_amount_money'] );
		$this->assertNull( $result['completed_amount_money'] );
		$this->assertNull( $result['next_payment_amount_money'] );
		$this->assertNull( $result['completed_at'] );
		$this->assertSame( 'unpaid', $result['payment_state'] );
	}

	public function test_retrieve_subscription_reports_http_errors_without_exposing_square_body(): void {
		$this->response_status = 500;
		$this->response_body = array( 'errors' => array( array( 'detail' => 'private square detail' ) ) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_subscription( 'subscription-123' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_resource_api_error', $result->get_error_code() );
		$this->assertStringNotContainsString( 'private square detail', $result->get_error_message() );
		$this->assertSame( 1, $this->request_count );
	}

	public function test_get_invoice_reports_transport_errors(): void {
		$this->transport_error = new WP_Error( 'http_request_failed', 'private transport details' );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_invoice( 'invoice-123' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_resource_request_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'private transport details', $result->get_error_message() );
		$this->assertSame( 1, $this->request_count );
	}

	public function test_get_invoice_reports_square_api_and_http_errors(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_status = 400;
		$this->response_body = array( 'errors' => array( array( 'detail' => 'private invoice detail' ) ) );
		$api_error = $client->get_invoice( 'invoice-123' );
		$this->assertTrue( is_wp_error( $api_error ) );
		$this->assertSame( 'square_resource_api_error', $api_error->get_error_code() );
		$this->assertStringNotContainsString( 'private invoice detail', $api_error->get_error_message() );

		$this->response_status = 503;
		$this->response_body = array( 'unexpected' => true );
		$http_error = $client->get_invoice( 'invoice-123' );
		$this->assertTrue( is_wp_error( $http_error ) );
		$this->assertSame( 'square_resource_http_error', $http_error->get_error_code() );
	}

	public function test_retrieve_subscription_rejects_invalid_json(): void {
		$this->response_body = 'not-json';
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_subscription( 'subscription-123' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_resource_response_invalid', $result->get_error_code() );
	}

	public function test_get_invoice_rejects_unexpected_response_shape_and_mismatched_id(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_body = array( 'invoice' => 'not-an-object' );
		$unexpected = $client->get_invoice( 'invoice-123' );
		$this->assertTrue( is_wp_error( $unexpected ) );
		$this->assertSame( 'square_resource_response_invalid', $unexpected->get_error_code() );

		$this->response_body = array( 'invoice' => array( 'id' => 'other-invoice', 'location_id' => 'location', 'order_id' => 'order', 'status' => 'PAID' ) );
		$mismatched = $client->get_invoice( 'invoice-123' );
		$this->assertTrue( is_wp_error( $mismatched ) );
		$this->assertSame( 'square_invoice_response_invalid', $mismatched->get_error_code() );
	}

	public function test_get_payment_uses_sandbox_get_and_returns_only_allowed_fields(): void {
		$this->response_body = array(
			'payment' => array(
				'id'                  => 'payment-123',
				'order_id'            => 'order-123',
				'customer_id'         => 'customer-123',
				'location_id'         => 'location-123',
				'status'              => 'COMPLETED',
				'created_at'          => '2026-11-05T01:02:03Z',
				'updated_at'          => '2026-11-05T01:02:04Z',
				'amount_money'        => array( 'amount' => 500, 'currency' => 'JPY' ),
				'total_money'         => array( 'amount' => 550, 'currency' => 'JPY' ),
				'card_details'        => array( 'card' => 'private-card-data' ),
				'billing_address'     => array( 'address_line_1' => 'private-address' ),
				'buyer_email_address' => 'private@example.test',
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_payment( 'payment-123' );

		$this->assertSame(
			array(
				'id'          => 'payment-123',
				'order_id'    => 'order-123',
				'customer_id' => 'customer-123',
				'location_id' => 'location-123',
				'status'      => 'COMPLETED',
				'created_at'  => '2026-11-05T01:02:03Z',
				'updated_at'  => '2026-11-05T01:02:04Z',
				'amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
				'total_money'  => array( 'amount' => 550, 'currency' => 'JPY' ),
			),
			$result
		);
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/payments/payment-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertSame( 'Bearer test-sandbox-token', $this->request['args']['headers']['Authorization'] );
		$this->assertSame( '2026-09-16', $this->request['args']['headers']['Square-Version'] );
		$this->assertArrayNotHasKey( 'card_details', $result );
		$this->assertArrayNotHasKey( 'billing_address', $result );
		$this->assertArrayNotHasKey( 'buyer_email_address', $result );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['amount_money'] );
		$this->assertSame( array( 'amount' => 550, 'currency' => 'JPY' ), $result['total_money'] );
	}

	public function test_get_payment_accepts_response_without_customer_id(): void {
		$this->response_body = array(
			'payment' => array(
				'id'          => 'payment-123',
				'order_id'    => 'order-123',
				'location_id' => 'location-123',
				'status'      => 'COMPLETED',
				'created_at'  => '2026-11-05T01:02:03Z',
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_payment( 'payment-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayNotHasKey( 'customer_id', $result );
		$this->assertArrayNotHasKey( 'amount_money', $result );
		$this->assertArrayNotHasKey( 'total_money', $result );
	}

	public function test_retrieve_order_uses_sandbox_get_and_returns_only_allowed_fields(): void {
		$this->response_body = array(
			'order' => array(
				'id'             => 'order-123',
				'reference_id'   => 'attempt-uuid',
				'location_id'    => 'location-123',
				'state'          => 'OPEN',
				'version'        => 2,
				'created_at'     => '2026-11-05T01:02:03Z',
				'updated_at'     => '2026-11-05T01:02:04Z',
				'customer_id'    => 'private-customer-id',
				'tenders'        => array( array( 'payment_id' => 'payment-123' ), array( 'payment_id' => 'payment-456' ) ),
				'total_money'    => array( 'amount' => 500, 'currency' => 'JPY' ),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_order( 'order-123' );

		$this->assertSame(
			array(
				'id'           => 'order-123',
				'reference_id' => 'attempt-uuid',
				'location_id'  => 'location-123',
				'state'        => 'OPEN',
				'version'      => 2,
				'created_at'   => '2026-11-05T01:02:03Z',
				'updated_at'   => '2026-11-05T01:02:04Z',
				'tenders'      => array( array( 'payment_id' => 'payment-123' ), array( 'payment_id' => 'payment-456' ) ),
				'total_money'  => array( 'amount' => 500, 'currency' => 'JPY' ),
			),
			$result
		);
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/orders/order-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertSame( 'Bearer test-sandbox-token', $this->request['args']['headers']['Authorization'] );
		$this->assertSame( '2026-09-16', $this->request['args']['headers']['Square-Version'] );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['total_money'] );
	}

	public function test_retrieve_order_accepts_response_without_reference_id(): void {
		$this->response_body = array(
			'order' => array(
				'id'          => 'order-123',
				'location_id' => 'location-123',
				'state'       => 'OPEN',
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_order( 'order-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertArrayNotHasKey( 'reference_id', $result );
		$this->assertSame( array(), $result['tenders'] );
		$this->assertArrayNotHasKey( 'total_money', $result );
	}

	public function test_order_preserves_missing_tender_payment_id_and_partial_money_without_defaults(): void {
		$this->response_body = array(
			'order' => array(
				'id'          => 'order-123',
				'location_id' => 'location-123',
				'state'       => 'OPEN',
				'tenders'     => array(
					array( 'type' => 'CARD' ),
					array( 'payment_id' => 'payment-456' ),
				),
				'total_money' => array( 'currency' => 'JPY' ),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_order( 'order-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( array( 'payment_id' => null ), array( 'payment_id' => 'payment-456' ) ), $result['tenders'] );
		$this->assertSame( array( 'currency' => 'JPY' ), $result['total_money'] );
	}

	public function test_order_accepts_square_tender_id_as_payment_id_and_rejects_conflicting_ids(): void {
		$this->response_body = array( 'order' => array(
			'id' => 'order-123', 'location_id' => 'location-123', 'state' => 'COMPLETED',
			'tenders' => array( array( 'id' => 'payment-from-tender' ) ),
		) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_order( 'order-123' );
		$this->assertSame( array( array( 'payment_id' => 'payment-from-tender' ) ), $result['tenders'] );

		$this->response_body['order']['tenders'] = array( array( 'id' => 'payment-one', 'payment_id' => 'payment-two' ) );
		$conflict = $client->retrieve_order( 'order-123' );
		$this->assertInstanceOf( WP_Error::class, $conflict );
		$this->assertSame( 'square_order_response_invalid', $conflict->get_error_code() );
	}

	public function test_payment_returns_refund_metadata_without_card_or_buyer_fields(): void {
		$this->response_body = array( 'payment' => array(
			'id' => 'payment-123', 'order_id' => 'order-123', 'location_id' => 'location-123',
			'status' => 'COMPLETED', 'created_at' => '2026-11-05T01:02:03Z',
			'refund_ids' => array( 'refund-123' ), 'refunded_money' => array( 'amount' => 100, 'currency' => 'JPY' ),
			'card_details' => array( 'card' => array( 'last_4' => '1111' ) ), 'buyer_email_address' => 'private@example.test',
		) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_payment( 'payment-123' );
		$this->assertSame( array( 'refund-123' ), $result['refund_ids'] );
		$this->assertSame( array( 'amount' => 100, 'currency' => 'JPY' ), $result['refunded_money'] );
		$this->assertArrayNotHasKey( 'card_details', $result );
		$this->assertArrayNotHasKey( 'buyer_email_address', $result );
	}

	public function test_order_without_tenders_returns_empty_array(): void {
		$this->response_body = array( 'order' => array( 'id' => 'order-123', 'location_id' => 'location-123', 'state' => 'OPEN' ) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_order( 'order-123' );

		$this->assertSame( array(), $result['tenders'] );
	}

	public function test_payment_preserves_partial_money_without_zero_or_currency_defaults(): void {
		$this->response_body = array(
			'payment' => array(
				'id'           => 'payment-123',
				'order_id'     => 'order-123',
				'location_id'  => 'location-123',
				'status'       => 'COMPLETED',
				'created_at'   => '2026-11-05T01:02:03Z',
				'amount_money' => array( 'amount' => 500 ),
			),
		);
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->get_payment( 'payment-123' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( 'amount' => 500 ), $result['amount_money'] );
		$this->assertArrayNotHasKey( 'currency', $result['amount_money'] );
		$this->assertArrayNotHasKey( 'total_money', $result );
	}

	public function test_payment_and_order_reject_invalid_ids_and_mismatched_response_ids(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$invalid_ids = array(
			array( 'method' => 'get_payment', 'id' => '' ),
			array( 'method' => 'get_payment', 'id' => array( 'invalid' ) ),
			array( 'method' => 'retrieve_order', 'id' => '' ),
			array( 'method' => 'retrieve_order', 'id' => array( 'invalid' ) ),
		);
		foreach ( $invalid_ids as $case ) {
			$result = $client->{$case['method']}( $case['id'] );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'square_resource_id_invalid', $result->get_error_code() );
		}
		$this->assertSame( 0, $this->request_count );

		$this->response_body = array( 'payment' => array( 'id' => 'different-payment', 'order_id' => 'order', 'location_id' => 'location', 'status' => 'COMPLETED', 'created_at' => '2026-11-05T00:00:00Z' ) );
		$payment = $client->get_payment( 'payment-123' );
		$this->assertTrue( is_wp_error( $payment ) );
		$this->assertSame( 'square_payment_response_invalid', $payment->get_error_code() );

		$this->response_body = array( 'order' => array( 'id' => 'different-order', 'location_id' => 'location', 'state' => 'OPEN' ) );
		$order = $client->retrieve_order( 'order-123' );
		$this->assertTrue( is_wp_error( $order ) );
		$this->assertSame( 'square_order_response_invalid', $order->get_error_code() );
	}

	public function test_payment_and_order_reject_api_http_transport_and_invalid_json_errors(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );

		$this->response_status = 503;
		$this->response_body = array( 'errors' => array( array( 'detail' => 'private Square error' ) ) );
		$payment_api_error = $client->get_payment( 'payment-123' );
		$this->assertTrue( is_wp_error( $payment_api_error ) );
		$this->assertSame( 'square_resource_api_error', $payment_api_error->get_error_code() );
		$this->assertStringNotContainsString( 'private Square error', $payment_api_error->get_error_message() );

		$this->response_status = 500;
		$this->response_body = array( 'unexpected' => true );
		$order_http_error = $client->retrieve_order( 'order-123' );
		$this->assertTrue( is_wp_error( $order_http_error ) );
		$this->assertSame( 'square_resource_http_error', $order_http_error->get_error_code() );

		$this->transport_error = new WP_Error( 'http_request_failed', 'private transport detail' );
		$transport_error = $client->get_payment( 'payment-123' );
		$this->assertTrue( is_wp_error( $transport_error ) );
		$this->assertSame( 'square_resource_request_failed', $transport_error->get_error_code() );
		$this->assertStringNotContainsString( 'private transport detail', $transport_error->get_error_message() );
		$this->transport_error = null;

		$this->response_status = 200;
		$this->response_body = 'not-json';
		$invalid_json = $client->retrieve_order( 'order-123' );
		$this->assertTrue( is_wp_error( $invalid_json ) );
		$this->assertSame( 'square_resource_response_invalid', $invalid_json->get_error_code() );
	}

	public function test_payment_and_order_reject_missing_required_fields_and_invalid_types(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_body = array(
			'payment' => array(
				'id'         => 'payment-123',
				'location_id' => 'location',
				'status'     => 'COMPLETED',
				'created_at' => '2026-11-05T00:00:00Z',
			),
		);
		$missing_payment_field = $client->get_payment( 'payment-123' );
		$this->assertTrue( is_wp_error( $missing_payment_field ) );
		$this->assertSame( 'square_payment_response_invalid', $missing_payment_field->get_error_code() );

		$this->response_body = array(
			'payment' => array(
				'id'          => 'payment-123',
				'order_id'    => 'order',
				'location_id' => 'location',
				'status'      => 'COMPLETED',
				'created_at'  => '2026-11-05T00:00:00Z',
				'customer_id' => 42,
			),
		);
		$invalid_payment_type = $client->get_payment( 'payment-123' );
		$this->assertTrue( is_wp_error( $invalid_payment_type ) );
		$this->assertSame( 'square_payment_response_invalid', $invalid_payment_type->get_error_code() );

		$this->response_body = array(
			'order' => array(
				'id'          => 'order-123',
				'location_id' => 'location',
			),
		);
		$missing_order_field = $client->retrieve_order( 'order-123' );
		$this->assertTrue( is_wp_error( $missing_order_field ) );
		$this->assertSame( 'square_order_response_invalid', $missing_order_field->get_error_code() );

		$this->response_body = array(
			'order' => array(
				'id'          => 'order-123',
				'location_id' => 'location',
				'state'       => 'OPEN',
				'version'     => '2',
			),
		);
		$invalid_order_type = $client->retrieve_order( 'order-123' );
		$this->assertTrue( is_wp_error( $invalid_order_type ) );
		$this->assertSame( 'square_order_response_invalid', $invalid_order_type->get_error_code() );
	}

	public function test_sandbox_rejects_lookalike_payment_link_hosts(): void {
		$this->response_body['payment_link']['url'] = 'https://sandbox.square.link.attacker.example/u/test-link';
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'sandbox-lookalike-key' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_response_invalid', $result->get_error_code() );
	}

	public function test_square_api_error_and_invalid_response_are_not_returned_as_success(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_status = 400;
		$this->response_body   = array( 'errors' => array( array( 'code' => 'INVALID_REQUEST_ERROR' ) ) );
		$api_error             = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'retryable-key' );
		$this->assertTrue( is_wp_error( $api_error ) );
		$this->assertSame( 'square_api_error', $api_error->get_error_code() );
		$this->assertStringNotContainsString( 'test-sandbox-token', $api_error->get_error_message() );

		$this->response_status = 200;
		$this->response_body   = array( 'payment_link' => array( 'id' => 'link-without-order' ) );
		$invalid               = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'another-key' );
		$this->assertTrue( is_wp_error( $invalid ) );
		$this->assertSame( 'square_response_invalid', $invalid->get_error_code() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_staging_sandbox_diagnostics_log_only_redacted_response_metadata(): void {
		$log = $this->capture_diagnostic_log( true, 'staging', 'sandbox', array( 'payment_link' => array( 'id' => 'private-link-id', 'url' => 'https://sandbox.square.link/u/private-checkout-url' ) ) );
		$this->assertMatchesRegularExpression( '/e-Gov Law Monitor Square diagnostic: (.+)/', $log );
		preg_match( '/e-Gov Law Monitor Square diagnostic: (.+)/', $log, $matches );
		$diagnostics = isset( $matches[1] ) ? json_decode( $matches[1], true ) : null;
		$this->assertIsArray( $diagnostics );

		$this->assertSame( 200, $diagnostics['http_status'] );
		$this->assertSame( 'application/json', $diagnostics['content_type'] );
		$this->assertTrue( $diagnostics['json_decoded'] );
		$this->assertTrue( $diagnostics['payment_link_present'] );
		$this->assertSame( 'array', $diagnostics['payment_link_type'] );
		$this->assertSame( 'order_id_missing_or_empty', $diagnostics['validation_failure_code'] );
		$this->assertSame( 'sandbox.square.link', $diagnostics['url_host'] );
		$this->assertSame( array( 'present' => true, 'type' => 'string' ), $diagnostics['payment_link_fields']['id'] );
		$this->assertSame( array( 'present' => false, 'type' => 'missing' ), $diagnostics['payment_link_fields']['order_id'] );
		$this->assertSame( array( 'present' => true, 'type' => 'string' ), $diagnostics['payment_link_fields']['url'] );
		$this->assertStringNotContainsString( 'private-link-id', $log );
		$this->assertStringNotContainsString( 'private-checkout-url', $log );
		$this->assertStringNotContainsString( 'test-sandbox-token', $log );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_diagnostics_are_disabled_when_flag_is_false(): void {
		$this->assertSame( '', $this->capture_diagnostic_log( false, 'staging', 'sandbox', array( 'payment_link' => array() ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_diagnostics_are_disabled_when_flag_is_undefined(): void {
		$this->assertSame( '', $this->capture_diagnostic_log( null, 'staging', 'sandbox', array( 'payment_link' => array() ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_diagnostics_are_disabled_in_wordpress_production(): void {
		$this->assertSame( '', $this->capture_diagnostic_log( true, 'production', 'sandbox', array( 'payment_link' => array() ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_diagnostics_are_disabled_for_square_production(): void {
		$this->assertSame( '', $this->capture_diagnostic_log( true, 'staging', 'production', array( 'payment_link' => array() ) ) );
	}

	private function capture_diagnostic_log( $enabled, $wp_environment, $square_environment, $response_body ) {
		if ( null !== $enabled ) {
			define( 'EGOV_LAW_MONITOR_SQUARE_DIAGNOSTICS', $enabled );
		}
		define( 'WP_ENVIRONMENT_TYPE', $wp_environment );
		define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', $square_environment );
		$log_path = tempnam( sys_get_temp_dir(), 'egov-square-diagnostic-' );
		$this->assertNotFalse( $log_path );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_path );

		try {
			$this->response_body = $response_body;
			$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
			$result = $client->create_payment_link( '6f1c9802-3225-4e0a-80c1-b4de60d9bb90', 'attempt-idempotency-key' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'square_response_invalid', $result->get_error_code() );
			return (string) file_get_contents( $log_path );
		} finally {
			ini_set( 'error_log', $previous_log );
			if ( is_string( $log_path ) && file_exists( $log_path ) ) {
				unlink( $log_path );
			}
		}
	}

	public function test_checkout_scope_requires_server_api_access_token_without_returning_it(): void {
		$config = $this->config( 'production' );
		$client = new Egov_Law_Monitor_Square_Api_Client( $config );
		$scope = $client->get_checkout_scope();
		$this->assertIsArray( $scope );
		$this->assertArrayNotHasKey( 'access_token', $scope );

		unset( $config['access_token'] );
		$client_without_token = new Egov_Law_Monitor_Square_Api_Client( $config );
		$error = $client_without_token->get_checkout_scope();
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'square_configuration_invalid', $error->get_error_code() );
	}

	public function test_retrieve_subscription_plan_variation_normalizes_one_monthly_phase_and_omits_sensitive_fields(): void {
		$this->response_body = array( 'catalog_object' => array(
			'id' => 'variation-123', 'type' => 'SUBSCRIPTION_PLAN_VARIATION', 'version' => 4,
			'subscription_plan_variation' => array(
				'name' => 'private plan name', 'monthly_billing_anchor_date' => 31,
				'phases' => array( array(
					'uid' => 'phase-1', 'ordinal' => 0, 'cadence' => 'MONTHLY', 'periods' => 1,
					'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ),
					'private_field' => 'must-not-return',
				) ),
			),
		) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->retrieve_subscription_plan_variation( 'variation-123' );

		$this->assertSame( 'variation-123', $result['id'] );
		$this->assertSame( 'SUBSCRIPTION_PLAN_VARIATION', $result['type'] );
		$this->assertSame( 4, $result['version'] );
		$this->assertSame( 31, $result['monthly_billing_anchor_date'] );
		$this->assertTrue( $result['phases_available'] );
		$this->assertTrue( $result['single_monthly_phase'] );
		$this->assertSame( 'MONTHLY', $result['phases'][0]['cadence'] );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'JPY' ), $result['phases'][0]['recurring_price_money'] );
		$this->assertArrayNotHasKey( 'name', $result );
		$this->assertArrayNotHasKey( 'private_field', $result['phases'][0] );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/catalog/object/variation-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
	}

	public function test_catalog_variation_rejects_wrong_type_id_and_malformed_phase_but_preserves_unknown_cadence(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_body = array( 'catalog_object' => array( 'id' => 'variation-123', 'type' => 'ITEM_VARIATION', 'subscription_plan_variation' => array( 'phases' => array() ) ) );
		$wrong_type = $client->retrieve_subscription_plan_variation( 'variation-123' );
		$this->assertInstanceOf( WP_Error::class, $wrong_type );		$this->assertSame( 'square_catalog_variation_response_invalid', $wrong_type->get_error_code() );

		$this->response_body = array( 'catalog_object' => array( 'id' => 'different', 'type' => 'SUBSCRIPTION_PLAN_VARIATION', 'subscription_plan_variation' => array( 'phases' => array() ) ) );
		$id_mismatch = $client->retrieve_subscription_plan_variation( 'variation-123' );
		$this->assertInstanceOf( WP_Error::class, $id_mismatch );

		$this->response_body = array( 'catalog_object' => array( 'id' => 'variation-123', 'type' => 'SUBSCRIPTION_PLAN_VARIATION', 'subscription_plan_variation' => array( 'phases' => array( array( 'cadence' => 'FUTURE_CADENCE' ) ) ) ) );
		$unknown = $client->retrieve_subscription_plan_variation( 'variation-123' );
		$this->assertFalse( is_wp_error( $unknown ) );
		$this->assertSame( 'FUTURE_CADENCE', $unknown['phases'][0]['cadence'] );
		$this->assertFalse( $unknown['phases'][0]['cadence_known'] );
		$this->assertFalse( $unknown['single_monthly_phase'] );

		$this->response_body['catalog_object']['subscription_plan_variation']['phases'][] = array( 'cadence' => 'MONTHLY' );
		$multiple = $client->retrieve_subscription_plan_variation( 'variation-123' );
		$this->assertCount( 2, $multiple['phases'] );
		$this->assertFalse( $multiple['single_monthly_phase'] );

		$this->response_body['catalog_object']['subscription_plan_variation']['phases'] = 'invalid';
		$malformed = $client->retrieve_subscription_plan_variation( 'variation-123' );
		$this->assertInstanceOf( WP_Error::class, $malformed );
	}

	public function test_subscription_actions_distinguish_not_requested_from_retrieved_empty_list(): void {
		$this->response_body = array( 'subscription' => array(
			'id' => 'subscription-123', 'location_id' => 'location', 'plan_variation_id' => 'variation', 'customer_id' => 'customer', 'status' => 'ACTIVE',
		) );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$without_actions = $client->retrieve_subscription( 'subscription-123' );
		$this->assertFalse( $without_actions['actions_retrieved'] );
		$this->assertArrayNotHasKey( 'actions', $without_actions );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/subscriptions/subscription-123', $this->request['url'] );

		$this->response_body['subscription']['actions'] = array();
		$with_empty_actions = $client->retrieve_subscription( 'subscription-123', true );
		$this->assertTrue( $with_empty_actions['actions_retrieved'] );
		$this->assertSame( array(), $with_empty_actions['actions'] );
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/subscriptions/subscription-123?include=actions', $this->request['url'] );

		$this->response_body['subscription']['actions'] = array( array( 'id' => 'action-1', 'type' => 'CHANGE_BILLING_ANCHOR_DATE', 'effective_date' => '2026-12-01', 'monthly_billing_anchor_date' => 1 ) );
		$with_action = $client->retrieve_subscription( 'subscription-123', true );
		$this->assertSame( 'CHANGE_BILLING_ANCHOR_DATE', $with_action['actions'][0]['type'] );
		$this->assertSame( 1, $with_action['actions'][0]['monthly_billing_anchor_date'] );

		unset( $this->response_body['subscription']['actions'] );
		$missing_actions = $client->retrieve_subscription( 'subscription-123', true );
		$this->assertInstanceOf( WP_Error::class, $missing_actions );
		$this->assertSame( 'square_subscription_response_invalid', $missing_actions->get_error_code() );
	}

	public function test_subscription_events_retrieve_multiple_pages_and_report_complete_scope(): void {
		$this->response_resolver = function ( $url ) {
			if ( false === strpos( $url, '/subscriptions/subscription-123/events' ) ) {
				return array( 'body' => array() );
			}
			if ( false === strpos( $url, 'cursor=cursor-page-2' ) ) {
				return array( 'body' => array( 'subscription_events' => array( array( 'id' => 'event-1', 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => '2026-10-01' ) ), 'cursor' => 'cursor-page-2' ) );
			}
			return array( 'body' => array( 'subscription_events' => array( array( 'id' => 'event-2', 'subscription_event_type' => 'BILLING_ANCHOR_DATE_CHANGED', 'effective_date' => '2026-11-01', 'monthly_billing_anchor_date' => 1, 'info' => array( 'detail' => 'private detail', 'code' => 'ANCHOR_CHANGED' ) ) ) ) );
		};
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->list_subscription_events( 'subscription-123' );

		$this->assertTrue( $result['complete'] );
		$this->assertSame( 'all_event_pages_retrieved', $result['reason_code'] );
		$this->assertSame( 2, $result['page_count'] );
		$this->assertSame( 2, $result['retrieved_event_count'] );
		$this->assertSame( '2026-10-01', $result['coverage_start_date'] );
		$this->assertSame( '2026-11-01', $result['coverage_end_date'] );
		$this->assertSame( 2, $this->request_count );
		$this->assertStringContainsString( 'limit=100', $this->request['url'] );
		$this->assertStringContainsString( 'cursor=cursor-page-2', $this->request['url'] );
		$this->assertArrayNotHasKey( 'info_detail', $result['events'][1] );
		$this->assertSame( 'ANCHOR_CHANGED', $result['events'][1]['info_code'] );
	}

	public function test_subscription_events_report_limit_and_do_not_claim_complete(): void {
		$this->response_body = array( 'subscription_events' => array( array( 'id' => 'event-1', 'subscription_event_type' => 'START_SUBSCRIPTION', 'effective_date' => '2026-10-01' ) ), 'cursor' => 'next-page' );
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$result = $client->list_subscription_events( 'subscription-123', 1 );
		$this->assertFalse( $result['complete'] );
		$this->assertSame( 'event_limit_reached', $result['reason_code'] );
		$this->assertSame( 1, $result['retrieved_event_count'] );
		$this->assertSame( 1, $this->request_count );
	}

	public function test_subscription_events_reject_api_errors_invalid_cursor_and_invalid_event_data(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->response_status = 503;
		$this->response_body = array( 'errors' => array( array( 'detail' => 'secret square detail' ) ) );
		$api_error = $client->list_subscription_events( 'subscription-123' );
		$this->assertInstanceOf( WP_Error::class, $api_error );
		$this->assertSame( 'square_resource_api_error', $api_error->get_error_code() );
		$this->assertStringNotContainsString( 'secret square detail', $api_error->get_error_message() );

		$this->response_status = 200;
		$this->response_body = array( 'subscription_events' => array(), 'cursor' => array( 'invalid' ) );
		$cursor_error = $client->list_subscription_events( 'subscription-123' );
		$this->assertInstanceOf( WP_Error::class, $cursor_error );
		$this->assertSame( 'square_subscription_events_cursor_invalid', $cursor_error->get_error_code() );

		$this->response_resolver = function ( $url ) {
			return array( 'body' => array( 'subscription_events' => array(), 'cursor' => 'stuck-cursor' ) );
		};
		$repeated_cursor = $client->list_subscription_events( 'subscription-123' );
		$this->assertInstanceOf( WP_Error::class, $repeated_cursor );
		$this->assertSame( 'square_subscription_events_cursor_invalid', $repeated_cursor->get_error_code() );
		$this->assertSame( 4, $this->request_count );
		$this->response_resolver = null;

		$this->response_body = array( 'subscription_events' => array( array( 'id' => 'event-1', 'subscription_event_type' => 'PLAN_CHANGE', 'effective_date' => 'bad-date' ) ) );
		$event_error = $client->list_subscription_events( 'subscription-123' );
		$this->assertInstanceOf( WP_Error::class, $event_error );
		$this->assertSame( 'square_subscription_events_response_invalid', $event_error->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $client->list_subscription_events( array( 'invalid' ) ) );
		$this->assertInstanceOf( WP_Error::class, $client->list_subscription_events( 'subscription-123', 501 ) );
	}

	public function test_get_payment_refund_normalizes_each_known_status_without_personal_fields(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		foreach ( array( 'PENDING', 'COMPLETED', 'REJECTED', 'FAILED' ) as $status ) {
			$this->response_body = array( 'refund' => array(
				'id' => 'refund-123', 'payment_id' => 'payment-123', 'status' => $status,
				'amount_money' => array( 'amount' => 125, 'currency' => 'JPY' ),
				'reason' => 'private reason', 'destination_details' => array( 'private' => true ),
			) );
			$result = $client->get_payment_refund( 'refund-123' );
			$this->assertSame( $status, $result['status'] );
			$this->assertTrue( $result['status_known'] );
			$this->assertSame( 'payment-123', $result['payment_id'] );
			$this->assertSame( array( 'amount' => 125, 'currency' => 'JPY' ), $result['amount_money'] );
			$this->assertArrayNotHasKey( 'reason', $result );
			$this->assertArrayNotHasKey( 'destination_details', $result );
		}
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/refunds/refund-123', $this->request['url'] );
	}

	public function test_get_payment_refund_rejects_invalid_ids_missing_fields_types_and_mismatched_ids(): void {
		$client = new Egov_Law_Monitor_Square_Api_Client( $this->config( 'sandbox' ) );
		$this->assertInstanceOf( WP_Error::class, $client->get_payment_refund( '' ) );
		$this->assertInstanceOf( WP_Error::class, $client->get_payment_refund( array( 'invalid' ) ) );
		$this->assertSame( 0, $this->request_count );

		$this->response_body = array( 'refund' => array( 'id' => 'other-refund', 'payment_id' => 'payment-123', 'status' => 'COMPLETED', 'amount_money' => array( 'amount' => 100, 'currency' => 'JPY' ) ) );
		$id_error = $client->get_payment_refund( 'refund-123' );
		$this->assertInstanceOf( WP_Error::class, $id_error );
		$this->assertSame( 'square_refund_response_invalid', $id_error->get_error_code() );

		$this->response_body = array( 'refund' => array( 'id' => 'refund-123', 'status' => 'COMPLETED', 'amount_money' => array( 'amount' => 100, 'currency' => 'JPY' ) ) );
		$missing_payment = $client->get_payment_refund( 'refund-123' );
		$this->assertInstanceOf( WP_Error::class, $missing_payment );
		$this->assertSame( 'square_refund_response_invalid', $missing_payment->get_error_code() );

		$this->response_body = array( 'refund' => array( 'id' => 'refund-123', 'payment_id' => 'payment-123', 'status' => 'COMPLETED', 'amount_money' => array( 'amount' => '100', 'currency' => 'JPY' ) ) );
		$bad_money = $client->get_payment_refund( 'refund-123' );
		$this->assertInstanceOf( WP_Error::class, $bad_money );
		$this->assertSame( 'square_refund_response_invalid', $bad_money->get_error_code() );

		$this->response_status = 503;
		$this->response_body = array( 'errors' => array( array( 'detail' => 'private refund detail' ) ) );
		$api_error = $client->get_payment_refund( 'refund-123' );
		$this->assertInstanceOf( WP_Error::class, $api_error );
		$this->assertSame( 'square_resource_api_error', $api_error->get_error_code() );
		$this->assertStringNotContainsString( 'private refund detail', $api_error->get_error_message() );
	}

	private function config( $environment ): array {
		return array(
			'environment'                    => $environment,
			'access_token'                   => 'test-' . $environment . '-token',
			'merchant_id'                    => 'test-merchant',
			'location_id'                    => 'test-location',
			'catalog_item_variation_id'       => 'test-item-variation',
			'subscription_plan_variation_id'  => 'test-subscription-variation',
		);
	}
}
