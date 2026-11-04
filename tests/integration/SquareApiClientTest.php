<?php

use PHPUnit\Framework\TestCase;

final class SquareApiClientTest extends TestCase {
	private $filter;
	private $request;
	private $request_count = 0;
	private $response_body;
	private $response_status = 200;
	private $response_headers = array();

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
