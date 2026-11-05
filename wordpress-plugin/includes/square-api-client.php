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
				'pricing_options' => array(
					'auto_apply_taxes' => true,
				),
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
		$json_error = json_last_error();

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
			$this->log_invalid_payment_link_response( $response, $decoded, $json_error );
			return new WP_Error( 'square_response_invalid', 'Squareから有効な決済リンク情報を取得できませんでした。' );
		}

		return array(
			'payment_link_id' => $link['id'],
			'order_id'        => $link['order_id'],
			'url'             => $url,
		);
	}

	/**
	 * Return non-secret configuration used to partition local checkout rows.
	 *
	 * @return array|WP_Error
	 */
	public function get_checkout_scope() {
		$required = array( 'environment', 'merchant_id', 'location_id', 'catalog_item_variation_id', 'subscription_plan_variation_id' );
		foreach ( $required as $key ) {
			if ( ! isset( $this->config[ $key ] ) || ! is_string( $this->config[ $key ] ) || '' === trim( $this->config[ $key ] ) ) {
				return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
			}
		}
		if ( ! in_array( $this->config['environment'], array( 'sandbox', 'production' ), true ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareの環境設定が不正です。' );
		}
		return array_intersect_key( $this->config, array_flip( $required ) );
	}

	/**
	 * Retrieve a payment link before reusing it for another checkout response.
	 *
	 * @param string $payment_link_id Square Payment Link ID.
	 * @return array|WP_Error Normalized link or a safe error.
	 */
	public function retrieve_payment_link( $payment_link_id ) {
		$scope = $this->get_checkout_scope();
		if ( is_wp_error( $scope ) || ! isset( $this->config['access_token'] ) || ! is_string( $this->config['access_token'] ) || '' === trim( $this->config['access_token'] ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
		}
		if ( ! is_string( $payment_link_id ) || '' === trim( $payment_link_id ) ) {
			return new WP_Error( 'square_payment_link_id_invalid', '決済リンクを確認できません。' );
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'square_http_api_unavailable', 'Square決済サービスを利用できません。' );
		}

		$host = 'sandbox' === $scope['environment'] ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
		$response = wp_remote_get(
			$host . '/v2/online-checkout/payment-links/' . rawurlencode( $payment_link_id ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->config['access_token'],
					'Square-Version' => self::API_VERSION,
					'Accept'         => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'square_link_verification_failed', '既存の決済リンクを確認できませんでした。' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new WP_Error( 'square_payment_link_not_found', '決済リンクが見つかりません。', array( 'http_status' => $status ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || ! empty( $decoded['errors'] ) ) {
			return new WP_Error( 'square_link_verification_failed', '既存の決済リンクを確認できませんでした。', array( 'http_status' => $status ) );
		}
		$link = isset( $decoded['payment_link'] ) && is_array( $decoded['payment_link'] ) ? $decoded['payment_link'] : array();
		$url  = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
		if ( ! empty( $link['is_deleted'] ) || empty( $link['id'] ) || ! is_string( $link['id'] ) || empty( $link['order_id'] ) || ! is_string( $link['order_id'] ) || ! $this->is_square_checkout_url( $url ) ) {
			return new WP_Error( 'square_link_verification_failed', '既存の決済リンクを確認できませんでした。' );
		}
		return array(
			'payment_link_id' => $link['id'],
			'order_id'        => $link['order_id'],
			'url'             => $url,
		);
	}

	/**
	 * Retrieve and normalize a Square Subscription without associating it with a user.
	 *
	 * Personal and payment-method fields are deliberately omitted from the returned
	 * snapshot. The caller must independently verify all relevant IDs before use.
	 *
	 * @param string $subscription_id Square Subscription ID.
	 * @return array|WP_Error A validated, limited Subscription snapshot.
	 */
	public function retrieve_subscription( $subscription_id ) {
		$resource = $this->get_square_resource( 'subscriptions', $subscription_id, 'subscription' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}

		$required = array( 'id', 'location_id', 'plan_variation_id', 'customer_id', 'status' );
		foreach ( $required as $key ) {
			if ( ! isset( $resource[ $key ] ) || ! is_string( $resource[ $key ] ) || '' === $resource[ $key ] ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
		}
		if ( ! hash_equals( $subscription_id, $resource['id'] ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}

		$normalized = array_intersect_key(
			$resource,
			array_flip(
				array(
					'id', 'location_id', 'plan_variation_id', 'customer_id', 'status',
					'start_date', 'canceled_date', 'charged_through_date', 'paid_until_date',
					'created_at', 'invoice_ids', 'version', 'timezone',
				)
			)
		);
		foreach ( array( 'start_date', 'canceled_date', 'charged_through_date', 'paid_until_date', 'created_at', 'timezone' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ! is_string( $normalized[ $key ] ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
		}
		if ( array_key_exists( 'version', $normalized ) && ! is_int( $normalized['version'] ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		if ( array_key_exists( 'invoice_ids', $normalized ) ) {
			if ( ! is_array( $normalized['invoice_ids'] ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
			foreach ( $normalized['invoice_ids'] as $invoice_id ) {
				if ( ! is_string( $invoice_id ) || '' === $invoice_id ) {
					return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
				}
			}
		}

		return $normalized;
	}

	/**
	 * Retrieve and normalize a Square Invoice without exposing recipient details.
	 *
	 * @param string $invoice_id Square Invoice ID.
	 * @return array|WP_Error A validated, limited Invoice snapshot.
	 */
	public function get_invoice( $invoice_id ) {
		$resource = $this->get_square_resource( 'invoices', $invoice_id, 'invoice' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}

		$required = array( 'id', 'location_id', 'order_id', 'status' );
		foreach ( $required as $key ) {
			if ( ! isset( $resource[ $key ] ) || ! is_string( $resource[ $key ] ) || '' === $resource[ $key ] ) {
				return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
			}
		}
		if ( ! hash_equals( $invoice_id, $resource['id'] ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}

		$normalized = array_intersect_key(
			$resource,
			array_flip( array( 'id', 'location_id', 'order_id', 'status', 'version', 'created_at', 'updated_at', 'scheduled_at', 'timezone' ) )
		);
		foreach ( array( 'created_at', 'updated_at', 'scheduled_at', 'timezone' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ! is_string( $normalized[ $key ] ) ) {
				return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
			}
		}
		if ( array_key_exists( 'version', $normalized ) && ! is_int( $normalized['version'] ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}

		return $normalized;
	}

	/**
	 * Retrieve and normalize a Square Payment without exposing buyer or card details.
	 *
	 * @param string $payment_id Square Payment ID.
	 * @return array|WP_Error A validated, limited Payment snapshot.
	 */
	public function get_payment( $payment_id ) {
		$resource = $this->get_square_resource( 'payments', $payment_id, 'payment' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}

		$required = array( 'id', 'order_id', 'location_id', 'status', 'created_at' );
		foreach ( $required as $key ) {
			if ( ! isset( $resource[ $key ] ) || ! is_string( $resource[ $key ] ) || '' === $resource[ $key ] ) {
				return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
			}
		}
		if ( ! hash_equals( $payment_id, $resource['id'] ) ) {
			return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
		}

		$normalized = array_intersect_key(
			$resource,
			array_flip( array( 'id', 'order_id', 'customer_id', 'location_id', 'status', 'created_at', 'updated_at' ) )
		);
		foreach ( array( 'customer_id', 'updated_at' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ( ! is_string( $normalized[ $key ] ) || '' === $normalized[ $key ] ) ) {
				return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
			}
		}
		foreach ( array( 'amount_money', 'total_money' ) as $key ) {
			if ( array_key_exists( $key, $resource ) ) {
				$money = $this->normalize_optional_money( $resource[ $key ], 'square_payment_response_invalid' );
				if ( is_wp_error( $money ) ) {
					return $money;
				}
				$normalized[ $key ] = $money;
			}
		}

		return $normalized;
	}

	/**
	 * Retrieve and normalize a Square Order without returning payment or buyer details.
	 *
	 * @param string $order_id Square Order ID.
	 * @return array|WP_Error A validated, limited Order snapshot.
	 */
	public function retrieve_order( $order_id ) {
		$resource = $this->get_square_resource( 'orders', $order_id, 'order' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}

		$required = array( 'id', 'location_id', 'state' );
		foreach ( $required as $key ) {
			if ( ! isset( $resource[ $key ] ) || ! is_string( $resource[ $key ] ) || '' === $resource[ $key ] ) {
				return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
			}
		}
		if ( ! hash_equals( $order_id, $resource['id'] ) ) {
			return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
		}

		$normalized = array_intersect_key(
			$resource,
			array_flip( array( 'id', 'reference_id', 'location_id', 'state', 'version', 'created_at', 'updated_at' ) )
		);
		foreach ( array( 'reference_id', 'created_at', 'updated_at' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ( ! is_string( $normalized[ $key ] ) || ( 'reference_id' === $key && '' === $normalized[ $key ] ) ) ) {
				return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
			}
		}
		if ( array_key_exists( 'version', $normalized ) && ! is_int( $normalized['version'] ) ) {
			return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
		}
		$normalized['tenders'] = array();
		if ( array_key_exists( 'tenders', $resource ) ) {
			if ( ! is_array( $resource['tenders'] ) ) {
				return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
			}
			foreach ( $resource['tenders'] as $tender ) {
				if ( ! is_array( $tender ) ) {
					return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
				}
				$payment_id = array_key_exists( 'payment_id', $tender ) ? $tender['payment_id'] : null;
				if ( null !== $payment_id && ( ! is_string( $payment_id ) || '' === $payment_id ) ) {
					return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
				}
				$normalized['tenders'][] = array( 'payment_id' => $payment_id );
			}
		}
		if ( array_key_exists( 'total_money', $resource ) ) {
			$money = $this->normalize_optional_money( $resource['total_money'], 'square_order_response_invalid' );
			if ( is_wp_error( $money ) ) {
				return $money;
			}
			$normalized['total_money'] = $money;
		}

		return $normalized;
	}

	/** Normalize only the amount and currency fields of an optional Square Money object. */
	private function normalize_optional_money( $money, $error_code ) {
		if ( ! is_array( $money ) ) {
			return new WP_Error( $error_code, 'Squareの金額情報を確認できませんでした。' );
		}

		$normalized = array();
		if ( array_key_exists( 'amount', $money ) ) {
			if ( ! is_int( $money['amount'] ) ) {
				return new WP_Error( $error_code, 'Squareの金額情報を確認できませんでした。' );
			}
			$normalized['amount'] = $money['amount'];
		}
		if ( array_key_exists( 'currency', $money ) ) {
			if ( ! is_string( $money['currency'] ) || ! preg_match( '/\\A[A-Z]{3}\\z/', $money['currency'] ) ) {
				return new WP_Error( $error_code, 'Squareの金額情報を確認できませんでした。' );
			}
			$normalized['currency'] = $money['currency'];
		}

		return $normalized;
	}

	/** Perform a sandbox-or-production GET and return the named Square object. */
	private function get_square_resource( $resource_path, $resource_id, $response_key ) {
		if (
			! isset( $this->config['environment'], $this->config['access_token'] )
			|| ! in_array( $this->config['environment'], array( 'sandbox', 'production' ), true )
			|| ! is_string( $this->config['access_token'] )
			|| '' === trim( $this->config['access_token'] )
		) {
			return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
		}
		if ( ! is_string( $resource_id ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $resource_id ) ) {
			return new WP_Error( 'square_resource_id_invalid', 'Squareの情報を確認できませんでした。' );
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'square_http_api_unavailable', 'Square情報を取得できません。' );
		}

		$host = 'sandbox' === $this->config['environment']
			? 'https://connect.squareupsandbox.com'
			: 'https://connect.squareup.com';
		$response = wp_remote_get(
			$host . '/v2/' . $resource_path . '/' . rawurlencode( $resource_id ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->config['access_token'],
					'Square-Version' => self::API_VERSION,
					'Accept'         => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'square_resource_request_failed', 'Square情報を取得できませんでした。' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new WP_Error( 'square_resource_not_found', 'Square情報が見つかりません。', array( 'http_status' => $status ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $decoded ) && ! empty( $decoded['errors'] ) ) {
			return new WP_Error( 'square_resource_api_error', 'Square情報を取得できませんでした。', array( 'http_status' => $status ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'square_resource_http_error', 'Square情報を取得できませんでした。', array( 'http_status' => $status ) );
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded[ $response_key ] ) || ! is_array( $decoded[ $response_key ] ) ) {
			return new WP_Error( 'square_resource_response_invalid', 'Squareから有効な情報を取得できませんでした。' );
		}

		return $decoded[ $response_key ];
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

		$allowed_hosts = 'sandbox' === ( $this->config['environment'] ?? '' )
			? array( 'sandbox.square.link' )
			: array( 'square.link', 'checkout.square.site' );

		return in_array( strtolower( $parts['host'] ?? '' ), $allowed_hosts, true );
	}

	/** Log redacted response metadata only when explicitly enabled on Sandbox staging. */
	private function log_invalid_payment_link_response( $response, $decoded, $json_error ) {
		if (
			! defined( 'EGOV_LAW_MONITOR_SQUARE_DIAGNOSTICS' )
			|| true !== EGOV_LAW_MONITOR_SQUARE_DIAGNOSTICS
			|| ! defined( 'WP_ENVIRONMENT_TYPE' )
			|| 'staging' !== WP_ENVIRONMENT_TYPE
			|| ! defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' )
			|| 'sandbox' !== EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT
		) {
			return;
		}

		$payment_link_exists = is_array( $decoded ) && array_key_exists( 'payment_link', $decoded );
		$link                = $payment_link_exists && is_array( $decoded['payment_link'] ) ? $decoded['payment_link'] : array();
		$url                 = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
		$parts               = '' !== $url ? wp_parse_url( $url ) : false;
		$host                = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( ! preg_match( '/\A[a-z0-9.-]{1,253}\z/', $host ) ) {
			$host = '' === $host ? 'unavailable' : 'invalid';
		}

		$content_type = function_exists( 'wp_remote_retrieve_header' ) ? wp_remote_retrieve_header( $response, 'content-type' ) : '';
		if ( is_array( $content_type ) ) {
			$content_type = reset( $content_type );
		}
		$media_type = is_string( $content_type ) && preg_match( '/\A\s*([a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+)/i', $content_type, $matches )
			? strtolower( $matches[1] )
			: 'unavailable';

		$reason = $this->payment_link_response_failure_reason( $decoded, $json_error, $link, $url );
		$fields = array();
		foreach ( array( 'id', 'order_id', 'url' ) as $field ) {
			$exists            = array_key_exists( $field, $link );
			$fields[ $field ] = array(
				'present' => $exists,
				'type'    => $exists ? gettype( $link[ $field ] ) : 'missing',
			);
		}

		$diagnostics = array(
			'http_status'              => function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0,
			'content_type'             => $media_type,
			'json_decoded'             => JSON_ERROR_NONE === $json_error,
			'payment_link_present'     => $payment_link_exists,
			'payment_link_type'        => $payment_link_exists ? gettype( $decoded['payment_link'] ) : 'missing',
			'payment_link_fields'      => $fields,
			'url_host'                 => $host,
			'validation_failure_code'  => $reason,
		);

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $diagnostics ) : false;
		if ( is_string( $encoded ) ) {
			error_log( 'e-Gov Law Monitor Square diagnostic: ' . $encoded );
		}
	}

	/** Return a stable non-sensitive code for the first failed response check. */
	private function payment_link_response_failure_reason( $decoded, $json_error, $link, $url ) {
		if ( JSON_ERROR_NONE !== $json_error ) {
			return 'json_decode_failed';
		}
		if ( ! is_array( $decoded ) ) {
			return 'response_not_object';
		}
		if ( ! array_key_exists( 'payment_link', $decoded ) ) {
			return 'payment_link_missing';
		}
		if ( ! is_array( $decoded['payment_link'] ) ) {
			return 'payment_link_not_object';
		}
		if ( empty( $link['id'] ) ) {
			return 'payment_link_id_missing_or_empty';
		}
		if ( ! is_string( $link['id'] ) ) {
			return 'payment_link_id_not_string';
		}
		if ( empty( $link['order_id'] ) ) {
			return 'order_id_missing_or_empty';
		}
		if ( ! is_string( $link['order_id'] ) ) {
			return 'order_id_not_string';
		}
		if ( ! $this->is_square_checkout_url( $url ) ) {
			return 'url_not_allowed';
		}

		return 'response_validation_failed';
	}
}
