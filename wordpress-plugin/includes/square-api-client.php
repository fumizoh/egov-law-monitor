<?php

/**
 * Minimal Square Checkout API client for creating hosted subscription links.
 *
 * Credentials and catalog identifiers are read from constants defined by the
 * server configuration. This class does not persist checkout attempts, process
 * webhooks, or grant an entitlement.
 */
class Egov_Law_Monitor_Square_Api_Client {
	private const API_VERSION = '2026-09-16';
	private const PRICE_AMOUNT = 500;
	private const PRICE_CURRENCY = 'JPY';

	private $config;

	/**
	 * @param array $config Validated server-side configuration. Primarily public for deterministic tests.
	 */
	public function __construct( $config ) {
		$this->config = is_array( $config ) ? $config : array();
	}

	/**
	 * Build a client from constants configured on the server (for example wp-config.php).
	 *
	 * Required constants are EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT and, for the
	 * selected environment, EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_{SANDBOX|PRODUCTION},
	 * EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_{SANDBOX|PRODUCTION},
	 * EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_{SANDBOX|PRODUCTION},
	 * EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_{SANDBOX|PRODUCTION}, and
	 * EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_{SANDBOX|PRODUCTION}.
	 *
	 * @return self|WP_Error
	 */
	public static function from_wordpress_config() {
		$environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' )
			? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT
			: '';

		if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareの環境設定を確認できません。' );
		}

		$suffix = 'sandbox' === $environment ? 'SANDBOX' : 'PRODUCTION';
		$keys   = array(
			'access_token'                    => 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_' . $suffix,
			'merchant_id'                     => 'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_' . $suffix,
			'location_id'                     => 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_' . $suffix,
			'catalog_item_variation_id'        => 'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_' . $suffix,
			'subscription_plan_variation_id'   => 'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_' . $suffix,
		);
		$config = array( 'environment' => $environment );

		foreach ( $keys as $key => $constant_name ) {
			if ( ! defined( $constant_name ) || ! is_string( constant( $constant_name ) ) || '' === trim( constant( $constant_name ) ) ) {
				return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
			}
			$config[ $key ] = trim( constant( $constant_name ) );
		}

		return new self( $config );
	}

	/**
	 * Create a Square-hosted subscription payment link.
	 *
	 * The request uses a server-configured catalog item variation and subscription
	 * plan variation. The price is fixed here and is never accepted from a caller.
	 *
	 * @param string $attempt_uuid   Local UUID placed in Order.reference_id.
	 * @param string $idempotency_key Stable key to reuse on retries.
	 * @return array|WP_Error Payment link ID, Order ID, and URL, or a safe error.
	 */
	public function create_payment_link( $attempt_uuid, $idempotency_key ) {
		$error = $this->validate_request( $attempt_uuid, $idempotency_key );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'wp_json_encode' ) ) {
			return new WP_Error( 'square_http_api_unavailable', 'Square決済サービスを利用できません。' );
		}

		$payload = array(
			'idempotency_key' => $idempotency_key,
			'order'           => array(
				'location_id' => $this->config['location_id'],
				'reference_id' => $attempt_uuid,
				'line_items'   => array(
					array(
						'quantity'         => '1',
						'catalog_object_id' => $this->config['catalog_item_variation_id'],
						'base_price_money' => array(
							'amount'   => self::PRICE_AMOUNT,
							'currency' => self::PRICE_CURRENCY,
						),
					),
				),
			),
			'checkout_options' => array(
				'subscription_plan_id' => $this->config['subscription_plan_variation_id'],
			),
		);

		$host = 'sandbox' === $this->config['environment']
			? 'https://connect.squareupsandbox.com'
			: 'https://connect.squareup.com';

		$response = wp_remote_post(
			$host . '/v2/online-checkout/payment-links',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->config['access_token'],
					'Square-Version' => self::API_VERSION,
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'square_http_request_failed', 'Square決済リンクを作成できませんでした。' );
		}

		$status = function_exists( 'wp_remote_retrieve_response_code' )
			? (int) wp_remote_retrieve_response_code( $response )
			: 0;
		$body = function_exists( 'wp_remote_retrieve_body' )
			? wp_remote_retrieve_body( $response )
			: '';
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;

		if ( is_array( $decoded ) && ! empty( $decoded['errors'] ) ) {
			return new WP_Error(
				'square_api_error',
				'Squareが決済リンクの作成を受け付けませんでした。',
				array( 'http_status' => $status )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'square_http_error',
				'Square決済リンクを作成できませんでした。',
				array( 'http_status' => $status )
			);
		}

		$link = is_array( $decoded ) && isset( $decoded['payment_link'] ) && is_array( $decoded['payment_link'] )
			? $decoded['payment_link']
			: array();
		$url = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';

		if (
			empty( $link['id'] )
			|| ! is_string( $link['id'] )
			|| empty( $link['order_id'] )
			|| ! is_string( $link['order_id'] )
			|| ! $this->is_square_checkout_url( $url )
		) {
			return new WP_Error( 'square_response_invalid', 'Squareから有効な決済リンク情報を取得できませんでした。' );
		}

		return array(
			'payment_link_id' => $link['id'],
			'order_id'        => $link['order_id'],
			'url'             => $url,
		);
	}

	private function validate_request( $attempt_uuid, $idempotency_key ) {
		$required = array(
			'environment',
			'access_token',
			'merchant_id',
			'location_id',
			'catalog_item_variation_id',
			'subscription_plan_variation_id',
		);

		foreach ( $required as $key ) {
			if ( ! isset( $this->config[ $key ] ) || ! is_string( $this->config[ $key ] ) || '' === trim( $this->config[ $key ] ) ) {
				return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
			}
		}

		if ( ! in_array( $this->config['environment'], array( 'sandbox', 'production' ), true ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareの環境設定が不正です。' );
		}

		if ( ! is_string( $attempt_uuid ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $attempt_uuid ) ) {
			return new WP_Error( 'square_attempt_id_invalid', '申込識別子が不正です。' );
		}

		if ( ! is_string( $idempotency_key ) || '' === $idempotency_key || strlen( $idempotency_key ) > 192 ) {
			return new WP_Error( 'square_idempotency_key_invalid', '決済要求識別子が不正です。' );
		}

		return null;
	}

	private function is_square_checkout_url( $url ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ?? '' ), array( 'square.link', 'checkout.square.site' ), true );
	}
}
