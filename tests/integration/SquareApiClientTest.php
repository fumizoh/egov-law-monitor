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

			return array(
				'headers'  => $this->response_headers,
				'body'     => wp_json_encode( $this->response_body ),
				'response' => array( 'code' => $this->response_status, 'message' => 'mocked' ),
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
				'tenders'        => array( array( 'payment_id' => 'private-payment-id' ) ),
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
			),
			$result
		);
		$this->assertSame( 'https://connect.squareupsandbox.com/v2/orders/order-123', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertSame( 'Bearer test-sandbox-token', $this->request['args']['headers']['Authorization'] );
		$this->assertSame( '2026-09-16', $this->request['args']['headers']['Square-Version'] );
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
