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
		if ( ! isset( $this->config['access_token'] ) || ! is_string( $this->config['access_token'] ) || '' === trim( $this->config['access_token'] ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
		}
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

	/** Return the server-defined Standard subscription charge in minor currency units. */
	public function get_standard_plan_price() {
		return array(
			'amount'   => self::PRICE_AMOUNT,
			'currency' => self::PRICE_CURRENCY,
		);
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
	public function retrieve_subscription( $subscription_id, $include_actions = false ) {
		if ( ! is_bool( $include_actions ) ) {
			return new WP_Error( 'square_subscription_request_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		$resource = $this->get_square_resource( 'subscriptions', $subscription_id, 'subscription', $include_actions ? array( 'include' => 'actions' ) : array() );
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
					'created_at', 'completed_date', 'monthly_billing_anchor_date', 'invoice_ids', 'version', 'timezone',
				)
			)
		);
		if ( $include_actions ) {
			if ( ! array_key_exists( 'actions', $resource ) || ! is_array( $resource['actions'] ) || ( ! empty( $resource['actions'] ) && array_keys( $resource['actions'] ) !== range( 0, count( $resource['actions'] ) - 1 ) ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
			$normalized['actions_retrieved'] = true;
			$normalized['actions'] = array();
			foreach ( $resource['actions'] as $action ) {
				$normalized_action = $this->normalize_subscription_action( $action );
				if ( is_wp_error( $normalized_action ) ) {
					return $normalized_action;
				}
				$normalized['actions'][] = $normalized_action;
			}
		} else {
			$normalized['actions_retrieved'] = false;
		}
		$known_statuses = array( 'PENDING', 'ACTIVE', 'CANCELED', 'PAUSED', 'DEACTIVATED', 'COMPLETED' );
		$normalized['status_known'] = in_array( $normalized['status'], $known_statuses, true );
		foreach ( array( 'start_date', 'canceled_date', 'charged_through_date', 'paid_until_date', 'created_at', 'timezone' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ! is_string( $normalized[ $key ] ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
		}
		foreach ( array( 'start_date', 'canceled_date', 'charged_through_date', 'paid_until_date', 'completed_date' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ! $this->is_square_date( $normalized[ $key ] ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
		}
		if ( array_key_exists( 'version', $normalized ) && ( ! is_int( $normalized['version'] ) || $normalized['version'] < 0 ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		if ( array_key_exists( 'monthly_billing_anchor_date', $normalized ) && ( ! is_int( $normalized['monthly_billing_anchor_date'] ) || $normalized['monthly_billing_anchor_date'] < 1 || $normalized['monthly_billing_anchor_date'] > 31 ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		if ( array_key_exists( 'completed_date', $normalized ) && ! is_string( $normalized['completed_date'] ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		if ( ! array_key_exists( 'version', $normalized ) ) {
			$normalized['version'] = null;
		}
		if ( array_key_exists( 'invoice_ids', $normalized ) ) {
			if ( ! is_array( $normalized['invoice_ids'] ) || ( ! empty( $normalized['invoice_ids'] ) && array_keys( $normalized['invoice_ids'] ) !== range( 0, count( $normalized['invoice_ids'] ) - 1 ) ) ) {
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

	/** Search candidate subscriptions by server-verified customer and location scope.
	 *
	 * A match from this search is only a candidate. Callers must verify an exact
	 * Checkout Attempt identifier chain before associating it with a WordPress user.
	 *
	 * @return array|WP_Error Candidate identifiers and whether the result page is complete.
	 */
	public function search_subscriptions_by_customer_location( $customer_id, $location_id, $max_results = 100 ) {
		if ( ! is_string( $customer_id ) || ! preg_match( '/\\A[A-Za-z0-9._:-]{1,255}\\z/', $customer_id ) || ! is_string( $location_id ) || ! preg_match( '/\\A[A-Za-z0-9._:-]{1,255}\\z/', $location_id ) || ! is_int( $max_results ) || $max_results < 1 || $max_results > 100 ) {
			return new WP_Error( 'square_subscription_search_request_invalid', 'Squareの契約候補を確認できませんでした。' );
		}
		if ( ! isset( $this->config['environment'], $this->config['access_token'] ) || ! in_array( $this->config['environment'], array( 'sandbox', 'production' ), true ) || ! is_string( $this->config['access_token'] ) || '' === trim( $this->config['access_token'] ) ) {
			return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
		}
		if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'wp_json_encode' ) ) {
			return new WP_Error( 'square_http_api_unavailable', 'Square情報を取得できません。' );
		}
		$host = 'sandbox' === $this->config['environment'] ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
		$response = wp_remote_post(
			$host . '/v2/subscriptions/search',
			array(
				'timeout' => 20,
				'redirection' => 0,
				'sslverify' => true,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->config['access_token'],
					'Square-Version' => self::API_VERSION,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( array(
					'limit' => $max_results,
					'query' => array( 'filter' => array( 'customer_ids' => array( $customer_id ), 'location_ids' => array( $location_id ) ) ),
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'square_resource_request_failed', 'Squareの契約候補を取得できませんでした。' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $decoded ) && ! empty( $decoded['errors'] ) ) {
			return new WP_Error( 'square_resource_api_error', 'Squareの契約候補を取得できませんでした。', array( 'http_status' => $status ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'square_resource_http_error', 'Squareの契約候補を取得できませんでした。', array( 'http_status' => $status ) );
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded['subscriptions'] ) || ! is_array( $decoded['subscriptions'] ) || count( $decoded['subscriptions'] ) > $max_results || ( ! empty( $decoded['subscriptions'] ) && array_keys( $decoded['subscriptions'] ) !== range( 0, count( $decoded['subscriptions'] ) - 1 ) ) ) {
			return new WP_Error( 'square_subscription_search_response_invalid', 'Squareの契約候補を確認できませんでした。' );
		}
		$candidates = array();
		$seen = array();
		foreach ( $decoded['subscriptions'] as $subscription ) {
			if ( ! is_array( $subscription ) || ! isset( $subscription['id'], $subscription['location_id'], $subscription['customer_id'] ) || ! is_string( $subscription['id'] ) || '' === $subscription['id'] || ! is_string( $subscription['location_id'] ) || ! is_string( $subscription['customer_id'] ) || ! hash_equals( $location_id, $subscription['location_id'] ) || ! hash_equals( $customer_id, $subscription['customer_id'] ) || isset( $seen[ $subscription['id'] ] ) ) {
				return new WP_Error( 'square_subscription_search_response_invalid', 'Squareの契約候補を確認できませんでした。' );
			}
			$seen[ $subscription['id'] ] = true;
			$candidates[] = array(
				'id' => $subscription['id'],
				'location_id' => $subscription['location_id'],
				'customer_id' => $subscription['customer_id'],
				'plan_variation_id' => isset( $subscription['plan_variation_id'] ) && is_string( $subscription['plan_variation_id'] ) ? $subscription['plan_variation_id'] : null,
			);
		}
		$cursor = $decoded['cursor'] ?? null;
		if ( null !== $cursor && ( ! is_string( $cursor ) || '' === trim( $cursor ) || strlen( $cursor ) > 2048 || preg_match( '/[\\x00-\\x20\\x7F]/', $cursor ) ) ) {
			return new WP_Error( 'square_subscription_search_response_invalid', 'Squareの契約候補を確認できませんでした。' );
		}
		return array(
			'candidates' => $candidates,
			'candidate_count' => count( $candidates ),
			'complete' => null === $cursor,
			'cursor_present' => null !== $cursor,
		);
	}

	/**
	 * Retrieve the configured Subscription Plan Variation and normalize its phases.
	 *
	 * @param string $variation_id Square Catalog Object ID.
	 * @return array|WP_Error A limited variation snapshot; unknown cadence is preserved.
	 */
	public function retrieve_subscription_plan_variation( $variation_id ) {
		$resource = $this->get_square_resource( 'catalog/object', $variation_id, 'catalog_object' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		if ( ! isset( $resource['id'], $resource['type'] ) || ! is_string( $resource['id'] ) || ! is_string( $resource['type'] ) || ! hash_equals( $variation_id, $resource['id'] ) || 'SUBSCRIPTION_PLAN_VARIATION' !== $resource['type'] || ! isset( $resource['subscription_plan_variation'] ) || ! is_array( $resource['subscription_plan_variation'] ) ) {
			return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
		}

		$variation = $resource['subscription_plan_variation'];
		$normalized = array(
			'id' => $resource['id'],
			'type' => $resource['type'],
			'version' => null,
			'phases_available' => false,
			'phases' => array(),
			'single_monthly_phase' => false,
		);
		if ( array_key_exists( 'version', $resource ) ) {
			if ( ! is_int( $resource['version'] ) || $resource['version'] < 0 ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$normalized['version'] = $resource['version'];
		}
		if ( array_key_exists( 'monthly_billing_anchor_date', $variation ) ) {
			if ( ! is_int( $variation['monthly_billing_anchor_date'] ) || $variation['monthly_billing_anchor_date'] < 1 || $variation['monthly_billing_anchor_date'] > 31 ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$normalized['monthly_billing_anchor_date'] = $variation['monthly_billing_anchor_date'];
		}
		if ( array_key_exists( 'phases', $variation ) ) {
			if ( ! is_array( $variation['phases'] ) || ( ! empty( $variation['phases'] ) && array_keys( $variation['phases'] ) !== range( 0, count( $variation['phases'] ) - 1 ) ) ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$normalized['phases_available'] = true;
			foreach ( $variation['phases'] as $phase ) {
				$normalized_phase = $this->normalize_subscription_plan_phase( $phase );
				if ( is_wp_error( $normalized_phase ) ) {
					return $normalized_phase;
				}
				$normalized['phases'][] = $normalized_phase;
			}
		}
		$normalized['single_monthly_phase'] = 1 === count( $normalized['phases'] ) && 'MONTHLY' === $normalized['phases'][0]['cadence'] && $normalized['phases'][0]['cadence_known'];
		return $normalized;
	}

	/** Retrieve every available Subscription Event up to a bounded event count. */
	public function list_subscription_events( $subscription_id, $max_events = 500 ) {
		if ( ! is_int( $max_events ) || $max_events < 1 || $max_events > 500 ) {
			return new WP_Error( 'square_subscription_events_limit_invalid', 'Squareの変更履歴を確認できませんでした。' );
		}
		if ( ! is_string( $subscription_id ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $subscription_id ) ) {
			return new WP_Error( 'square_resource_id_invalid', 'Squareの情報を確認できませんでした。' );
		}

		$events = array();
		$cursor = null;
		$seen_cursors = array();
		$page_count = 0;
		$page_size = 100;
		while ( true ) {
			$remaining = $max_events - count( $events );
			if ( $remaining < 1 ) {
				return array(
					'subscription_id' => $subscription_id,
					'events' => $events,
					'complete' => false,
					'reason_code' => 'event_limit_reached',
					'page_count' => $page_count,
					'retrieved_event_count' => count( $events ),
					'max_events' => $max_events,
					'coverage_start_date' => $this->event_date_bound( $events, false ),
					'coverage_end_date' => $this->event_date_bound( $events, true ),
				);
			}
			$query = array( 'limit' => min( $page_size, $remaining ) );
			if ( null !== $cursor ) {
				$query['cursor'] = $cursor;
			}
			$response = $this->get_square_api_response( 'subscriptions/' . rawurlencode( $subscription_id ) . '/events', $query );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( ! isset( $response['subscription_events'] ) || ! is_array( $response['subscription_events'] ) || ( ! empty( $response['subscription_events'] ) && array_keys( $response['subscription_events'] ) !== range( 0, count( $response['subscription_events'] ) - 1 ) ) || count( $response['subscription_events'] ) > $query['limit'] ) {
				return new WP_Error( 'square_subscription_events_response_invalid', 'Squareの変更履歴を確認できませんでした。' );
			}
			++$page_count;
			foreach ( $response['subscription_events'] as $event ) {
				$normalized_event = $this->normalize_subscription_event( $event );
				if ( is_wp_error( $normalized_event ) ) {
					return $normalized_event;
				}
				$events[] = $normalized_event;
			}

			if ( ! array_key_exists( 'cursor', $response ) || '' === $response['cursor'] ) {
				return array(
					'subscription_id' => $subscription_id,
					'events' => $events,
					'complete' => true,
					'reason_code' => 'all_event_pages_retrieved',
					'page_count' => $page_count,
					'retrieved_event_count' => count( $events ),
					'max_events' => $max_events,
					'coverage_start_date' => $this->event_date_bound( $events, false ),
					'coverage_end_date' => $this->event_date_bound( $events, true ),
				);
			}
			if ( ! is_string( $response['cursor'] ) || '' === trim( $response['cursor'] ) || strlen( $response['cursor'] ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $response['cursor'] ) || isset( $seen_cursors[ $response['cursor'] ] ) ) {
				return new WP_Error( 'square_subscription_events_cursor_invalid', 'Squareの変更履歴を確認できませんでした。' );
			}
			$seen_cursors[ $response['cursor'] ] = true;
			$cursor = $response['cursor'];
		}
	}

	/** Retrieve one Refund and return only identifiers, status, amount, and currency. */
	public function get_payment_refund( $refund_id ) {
		$resource = $this->get_square_resource( 'refunds', $refund_id, 'refund' );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		if ( ! isset( $resource['id'], $resource['payment_id'], $resource['status'], $resource['amount_money'] ) || ! is_string( $resource['id'] ) || ! is_string( $resource['payment_id'] ) || '' === $resource['payment_id'] || ! is_string( $resource['status'] ) || '' === $resource['status'] || ! hash_equals( $refund_id, $resource['id'] ) ) {
			return new WP_Error( 'square_refund_response_invalid', 'Squareの返金情報を確認できませんでした。' );
		}
		$money = $this->normalize_optional_money( $resource['amount_money'], 'square_refund_response_invalid' );
		if ( is_wp_error( $money ) || ! isset( $money['amount'], $money['currency'] ) || $money['amount'] < 0 ) {
			return new WP_Error( 'square_refund_response_invalid', 'Squareの返金情報を確認できませんでした。' );
		}
		$known_statuses = array( 'PENDING', 'COMPLETED', 'REJECTED', 'FAILED' );
		return array(
			'id' => $resource['id'],
			'payment_id' => $resource['payment_id'],
			'status' => $resource['status'],
			'status_known' => in_array( $resource['status'], $known_statuses, true ),
			'amount_money' => $money,
		);
	}

	/** Normalize an action without returning Square's free-form error details. */
	private function normalize_subscription_action( $action ) {
		if ( ! is_array( $action ) || ! isset( $action['id'], $action['type'], $action['effective_date'] ) || ! is_string( $action['id'] ) || '' === $action['id'] || ! is_string( $action['type'] ) || '' === $action['type'] || ! is_string( $action['effective_date'] ) || ! $this->is_square_date( $action['effective_date'] ) ) {
			return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
		}
		$known_types = array( 'CANCEL', 'PAUSE', 'RESUME', 'SWAP_PLAN', 'CHANGE_BILLING_ANCHOR_DATE', 'COMPLETE' );
		$normalized = array(
			'id' => $action['id'],
			'type' => $action['type'],
			'type_known' => in_array( $action['type'], $known_types, true ),
			'effective_date' => $action['effective_date'],
		);
		if ( array_key_exists( 'monthly_billing_anchor_date', $action ) ) {
			if ( ! is_int( $action['monthly_billing_anchor_date'] ) || $action['monthly_billing_anchor_date'] < 1 || $action['monthly_billing_anchor_date'] > 31 ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
			$normalized['monthly_billing_anchor_date'] = $action['monthly_billing_anchor_date'];
		}
		if ( array_key_exists( 'new_plan_variation_id', $action ) ) {
			if ( ! is_string( $action['new_plan_variation_id'] ) || '' === $action['new_plan_variation_id'] ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
			$normalized['new_plan_variation_id'] = $action['new_plan_variation_id'];
		}
		if ( array_key_exists( 'phases', $action ) ) {
			if ( ! is_array( $action['phases'] ) || ( ! empty( $action['phases'] ) && array_keys( $action['phases'] ) !== range( 0, count( $action['phases'] ) - 1 ) ) ) {
				return new WP_Error( 'square_subscription_response_invalid', 'Squareの契約情報を確認できませんでした。' );
			}
			$normalized['phases_available'] = true;
			$normalized['phase_count'] = count( $action['phases'] );
		}
		return $normalized;
	}

	/** Normalize one Catalog Subscription Plan Variation phase. */
	private function normalize_subscription_plan_phase( $phase ) {
		if ( ! is_array( $phase ) ) {
			return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
		}
		$cadence = null;
		if ( array_key_exists( 'cadence', $phase ) ) {
			if ( ! is_string( $phase['cadence'] ) || '' === $phase['cadence'] ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$cadence = $phase['cadence'];
		}
		$known_cadences = array( 'DAILY', 'WEEKLY', 'EVERY_TWO_WEEKS', 'THIRTY_DAYS', 'SIXTY_DAYS', 'NINETY_DAYS', 'MONTHLY', 'EVERY_TWO_MONTHS', 'QUARTERLY', 'EVERY_FOUR_MONTHS', 'EVERY_SIX_MONTHS', 'ANNUAL', 'EVERY_TWO_YEARS' );
		$normalized = array(
			'cadence' => $cadence,
			'cadence_known' => null !== $cadence && in_array( $cadence, $known_cadences, true ),
			'periods' => null,
			'recurring_price_money' => null,
		);
		foreach ( array( 'uid' => 'uid', 'ordinal' => 'ordinal' ) as $field => $target ) {
			if ( array_key_exists( $field, $phase ) ) {
				if ( 'uid' === $field && ( ! is_string( $phase[ $field ] ) || '' === $phase[ $field ] ) ) {
					return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
				}
				if ( 'ordinal' === $field && ( ! is_int( $phase[ $field ] ) || $phase[ $field ] < 0 ) ) {
					return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
				}
				$normalized[ $target ] = $phase[ $field ];
			}
		}
		if ( array_key_exists( 'periods', $phase ) ) {
			if ( ! is_int( $phase['periods'] ) || $phase['periods'] < 1 ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$normalized['periods'] = $phase['periods'];
		}
		if ( array_key_exists( 'recurring_price_money', $phase ) ) {
			$money = $this->normalize_optional_money( $phase['recurring_price_money'], 'square_catalog_variation_response_invalid' );
			if ( is_wp_error( $money ) || ! isset( $money['amount'], $money['currency'] ) || $money['amount'] < 0 ) {
				return new WP_Error( 'square_catalog_variation_response_invalid', 'Squareのプラン情報を確認できませんでした。' );
			}
			$normalized['recurring_price_money'] = $money;
		}
		return $normalized;
	}

	/** Normalize the public, non-PII fields of a Subscription Event. */
	private function normalize_subscription_event( $event ) {
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['subscription_event_type'], $event['effective_date'] ) || ! is_string( $event['id'] ) || '' === $event['id'] || ! is_string( $event['subscription_event_type'] ) || '' === $event['subscription_event_type'] || ! is_string( $event['effective_date'] ) || ! $this->is_square_date( $event['effective_date'] ) ) {
			return new WP_Error( 'square_subscription_events_response_invalid', 'Squareの変更履歴を確認できませんでした。' );
		}
		$known_types = array( 'START_SUBSCRIPTION', 'STOP_SUBSCRIPTION', 'DEACTIVATE_SUBSCRIPTION', 'RESUME_SUBSCRIPTION', 'PAUSE_SUBSCRIPTION', 'PLAN_CHANGE', 'BILLING_ANCHOR_DATE_CHANGED' );
		$normalized = array(
			'id' => $event['id'],
			'subscription_event_type' => $event['subscription_event_type'],
			'event_type_known' => in_array( $event['subscription_event_type'], $known_types, true ),
			'effective_date' => $event['effective_date'],
		);
		if ( array_key_exists( 'plan_variation_id', $event ) ) {
			if ( ! is_string( $event['plan_variation_id'] ) || '' === $event['plan_variation_id'] ) {
				return new WP_Error( 'square_subscription_events_response_invalid', 'Squareの変更履歴を確認できませんでした。' );
			}
			$normalized['plan_variation_id'] = $event['plan_variation_id'];
		}
		if ( array_key_exists( 'monthly_billing_anchor_date', $event ) ) {
			if ( ! is_int( $event['monthly_billing_anchor_date'] ) || $event['monthly_billing_anchor_date'] < 1 || $event['monthly_billing_anchor_date'] > 31 ) {
				return new WP_Error( 'square_subscription_events_response_invalid', 'Squareの変更履歴を確認できませんでした。' );
			}
			$normalized['monthly_billing_anchor_date'] = $event['monthly_billing_anchor_date'];
		}
		if ( isset( $event['info'] ) && is_array( $event['info'] ) && isset( $event['info']['code'] ) && is_string( $event['info']['code'] ) && '' !== $event['info']['code'] ) {
			$normalized['info_code'] = $event['info']['code'];
		}
		return $normalized;
	}

	/** Return the inclusive min/max effective dates in the retrieved event subset. */
	private function event_date_bound( $events, $maximum ) {
		$dates = array();
		foreach ( $events as $event ) {
			if ( isset( $event['effective_date'] ) ) {
				$dates[] = $event['effective_date'];
			}
		}
		if ( empty( $dates ) ) {
			return null;
		}
		sort( $dates, SORT_STRING );
		return $maximum ? end( $dates ) : reset( $dates );
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
		if ( array_key_exists( 'subscription_id', $resource ) && ( ! is_string( $resource['subscription_id'] ) || '' === $resource['subscription_id'] ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}

		$normalized = array_intersect_key(
			$resource,
			array_flip( array( 'id', 'location_id', 'order_id', 'subscription_id', 'status', 'version', 'created_at', 'updated_at', 'scheduled_at', 'timezone' ) )
		);
		foreach ( array( 'created_at', 'updated_at', 'scheduled_at', 'timezone' ) as $key ) {
			if ( array_key_exists( $key, $normalized ) && ! is_string( $normalized[ $key ] ) ) {
				return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
			}
		}
		if ( array_key_exists( 'version', $normalized ) && ( ! is_int( $normalized['version'] ) || $normalized['version'] < 0 || $normalized['version'] > 2147483647 ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}
		if ( ! array_key_exists( 'version', $normalized ) ) {
			$normalized['version'] = null;
		}

		$normalized['payment_requests'] = array();
		if ( array_key_exists( 'payment_requests', $resource ) ) {
			if ( ! is_array( $resource['payment_requests'] ) || count( $resource['payment_requests'] ) > 13 || ( ! empty( $resource['payment_requests'] ) && array_keys( $resource['payment_requests'] ) !== range( 0, count( $resource['payment_requests'] ) - 1 ) ) ) {
				return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
			}
			foreach ( $resource['payment_requests'] as $request ) {
				$payment_request = $this->normalize_invoice_payment_request( $request );
				if ( is_wp_error( $payment_request ) ) {
					return $payment_request;
				}
				$normalized['payment_requests'][] = $payment_request;
			}
		}

		$normalized['requested_amount_money'] = $this->sum_invoice_request_money( $normalized['payment_requests'], 'computed_amount_money' );
		$normalized['completed_amount_money'] = $this->sum_invoice_request_money( $normalized['payment_requests'], 'total_completed_amount_money' );
		$normalized['status_known'] = in_array(
			$normalized['status'],
			array( 'DRAFT', 'SCHEDULED', 'UNPAID', 'PAYMENT_PENDING', 'PARTIALLY_PAID', 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'CANCELED', 'FAILED' ),
			true
		);
		if ( array_key_exists( 'next_payment_amount_money', $resource ) ) {
			$next_amount = $this->normalize_optional_money( $resource['next_payment_amount_money'], 'square_invoice_response_invalid' );
			if ( is_wp_error( $next_amount ) ) {
				return $next_amount;
			}
			if ( isset( $next_amount['amount'] ) && $next_amount['amount'] < 0 ) {
				return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
			}
			$normalized['next_payment_amount_money'] = $next_amount;
		} else {
			$normalized['next_payment_amount_money'] = null;
		}
		$normalized['payment_state'] = $this->classify_invoice_payment_state( $normalized['status'], $normalized['requested_amount_money'], $normalized['completed_amount_money'] );
		// Invoice does not expose a payment-completion timestamp or Payment ID. Do not infer either from updated_at.
		$normalized['completed_at'] = null;

		return $normalized;
	}

	/** Normalize payment-request fields while excluding card and recipient information. */
	private function normalize_invoice_payment_request( $request ) {
		if ( ! is_array( $request ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}
		$normalized = array();
		foreach ( array( 'uid', 'request_type', 'due_date', 'automatic_payment_source' ) as $key ) {
			if ( array_key_exists( $key, $request ) ) {
				if ( ! is_string( $request[ $key ] ) || '' === $request[ $key ] ) {
					return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
				}
				$normalized[ $key ] = $request[ $key ];
			}
		}
		if ( isset( $normalized['due_date'] ) && ! $this->is_square_date( $normalized['due_date'] ) ) {
			return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
		}
		foreach ( array( 'computed_amount_money', 'total_completed_amount_money' ) as $key ) {
			if ( array_key_exists( $key, $request ) ) {
				$money = $this->normalize_optional_money( $request[ $key ], 'square_invoice_response_invalid' );
				if ( is_wp_error( $money ) ) {
					return $money;
				}
				if ( isset( $money['amount'] ) && $money['amount'] < 0 ) {
					return new WP_Error( 'square_invoice_response_invalid', 'Squareの請求情報を確認できませんでした。' );
				}
				$normalized[ $key ] = $money;
			}
		}
		return $normalized;
	}

	/** Validate Square YYYY-MM-DD calendar dates without timezone conversion. */
	private function is_square_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches ) ) {
			return false;
		}
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	/** Sum request-level money only when every request has a valid amount and matching currency. */
	private function sum_invoice_request_money( $requests, $field ) {
		if ( empty( $requests ) ) {
			return null;
		}
		$total    = 0;
		$currency = null;
		foreach ( $requests as $request ) {
			if ( ! isset( $request[ $field ]['amount'], $request[ $field ]['currency'] ) ) {
				return null;
			}
			if ( null !== $currency && $currency !== $request[ $field ]['currency'] ) {
				return null;
			}
			$currency = $request[ $field ]['currency'];
			if ( $request[ $field ]['amount'] > PHP_INT_MAX - $total ) {
				return null;
			}
			$total += $request[ $field ]['amount'];
		}
		return array( 'amount' => $total, 'currency' => $currency );
	}

	/** Classify the raw Invoice status without treating incomplete money data as payment success. */
	private function classify_invoice_payment_state( $status, $requested, $completed ) {
		$states = array(
			'DRAFT'              => 'planned',
			'SCHEDULED'          => 'scheduled',
			'UNPAID'             => 'unpaid',
			'PAYMENT_PENDING'    => 'processing',
			'PARTIALLY_PAID'     => 'partially_paid',
			'PARTIALLY_REFUNDED' => 'partially_refunded',
			'CANCELED'           => 'canceled',
			'FAILED'             => 'invoice_failed',
		);
		if ( 'REFUNDED' === $status ) {
			return 'refunded';
		}
		if ( 'PAID' === $status ) {
			if ( null === $requested || null === $completed || $requested['currency'] !== $completed['currency'] || $requested['amount'] !== $completed['amount'] ) {
				return 'unknown';
			}
			return 'paid';
		}
		if ( 'PARTIALLY_PAID' === $status ) {
			if ( null === $requested || null === $completed || $requested['currency'] !== $completed['currency'] || 0 >= $completed['amount'] || $completed['amount'] >= $requested['amount'] ) {
				return 'unknown';
			}
		}
		return isset( $states[ $status ] ) ? $states[ $status ] : 'unknown';
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
			array_flip( array( 'id', 'order_id', 'customer_id', 'location_id', 'status', 'created_at', 'updated_at', 'refund_ids' ) )
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
		if ( array_key_exists( 'refund_ids', $resource ) ) {
			if ( ! is_array( $resource['refund_ids'] ) || ( ! empty( $resource['refund_ids'] ) && array_keys( $resource['refund_ids'] ) !== range( 0, count( $resource['refund_ids'] ) - 1 ) ) ) {
				return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
			}
			foreach ( $resource['refund_ids'] as $refund_id ) {
				if ( ! is_string( $refund_id ) || '' === $refund_id ) {
					return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
				}
			}
			$normalized['refund_ids'] = $resource['refund_ids'];
		}
		if ( array_key_exists( 'refunded_money', $resource ) ) {
			$refunded_money = $this->normalize_optional_money( $resource['refunded_money'], 'square_payment_response_invalid' );
			if ( is_wp_error( $refunded_money ) || ( isset( $refunded_money['amount'] ) && $refunded_money['amount'] < 0 ) ) {
				return new WP_Error( 'square_payment_response_invalid', 'Squareの決済情報を確認できませんでした。' );
			}
			$normalized['refunded_money'] = $refunded_money;
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
				$payment_id = array_key_exists( 'payment_id', $tender ) ? $tender['payment_id'] : ( array_key_exists( 'id', $tender ) ? $tender['id'] : null );
				if ( array_key_exists( 'payment_id', $tender ) && array_key_exists( 'id', $tender ) && is_string( $tender['payment_id'] ) && is_string( $tender['id'] ) && ! hash_equals( $tender['payment_id'], $tender['id'] ) ) {
					return new WP_Error( 'square_order_response_invalid', 'Squareの注文情報を確認できませんでした。' );
				}
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
	private function get_square_resource( $resource_path, $resource_id, $response_key, $query = array() ) {
		if ( ! is_string( $resource_id ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $resource_id ) ) {
			return new WP_Error( 'square_resource_id_invalid', 'Squareの情報を確認できませんでした。' );
		}
		$response = $this->get_square_api_response( $resource_path . '/' . rawurlencode( $resource_id ), $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response[ $response_key ] ) || ! is_array( $response[ $response_key ] ) ) {
			return new WP_Error( 'square_resource_response_invalid', 'Squareから有効な情報を取得できませんでした。' );
		}
		return $response[ $response_key ];
	}

	/** Perform an authenticated, read-only Square GET and return its decoded envelope. */
	private function get_square_api_response( $path, $query = array() ) {
		if (
			! isset( $this->config['environment'], $this->config['access_token'] )
			|| ! in_array( $this->config['environment'], array( 'sandbox', 'production' ), true )
			|| ! is_string( $this->config['access_token'] )
			|| '' === trim( $this->config['access_token'] )
		) {
			return new WP_Error( 'square_configuration_invalid', 'Squareのサーバー設定が不足しています。' );
		}
		if ( ! is_string( $path ) || ! preg_match( '/\A[a-z][a-z0-9-]*(?:\/[A-Za-z0-9._:%-]+)*\z/', $path ) || false !== strpos( $path, '..' ) ) {
			return new WP_Error( 'square_resource_path_invalid', 'Squareの情報を確認できませんでした。' );
		}
		if ( ! is_array( $query ) ) {
			return new WP_Error( 'square_resource_query_invalid', 'Squareの情報を確認できませんでした。' );
		}
		foreach ( $query as $key => $value ) {
			if ( ! in_array( $key, array( 'include', 'limit', 'cursor' ), true ) || ! is_scalar( $value ) || is_bool( $value ) || '' === (string) $value ) {
				return new WP_Error( 'square_resource_query_invalid', 'Squareの情報を確認できませんでした。' );
			}
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'square_http_api_unavailable', 'Square情報を取得できません。' );
		}

		$host = 'sandbox' === $this->config['environment']
			? 'https://connect.squareupsandbox.com'
			: 'https://connect.squareup.com';
		$url = $host . '/v2/' . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get(
			$url,
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
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'square_resource_response_invalid', 'Squareから有効な情報を取得できませんでした。' );
		}
		return $decoded;
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
