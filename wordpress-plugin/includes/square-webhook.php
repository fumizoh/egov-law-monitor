<?php

/**
 * Square webhook receiver. This stage verifies and persists events only; it
 * deliberately does not synchronize subscriptions, invoices, or entitlements.
 */
class Egov_Law_Monitor_Square_Webhook_Repository {
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/** Save a verified event once, relying on the database unique key for races. */
	public function insert_event( $event, $environment, $merchant_id, $raw_body ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		$now   = current_time( 'mysql', true );
		$created_at = null;
		if ( isset( $event['created_at'] ) && is_string( $event['created_at'] ) ) {
			try {
				$created_at = ( new DateTimeImmutable( $event['created_at'] ) )
					->setTimezone( new DateTimeZone( 'UTC' ) )
					->format( 'Y-m-d H:i:s' );
			} catch ( Exception $exception ) {
				return new WP_Error( 'square_webhook_event_invalid', 'Webhookイベントを確認できません。' );
			}
		}

		$this->wpdb->last_error = '';
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'environment'       => $environment,
				'merchant_id'       => $merchant_id,
				'square_event_id'   => $event['event_id'],
				'event_type'         => $event['type'],
				'square_created_at'  => $created_at,
				'received_at'        => $now,
				'processing_status'  => 'received',
				'payload_hash'       => hash( 'sha256', $raw_body ),
				'normalized_payload' => $raw_body,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( 1 === $inserted && '' === (string) $this->wpdb->last_error ) {
			return array( 'duplicate' => false );
		}

		// A concurrent insert can fail on the unique key. Only acknowledge it as
		// a duplicate if the exact environment/merchant/event tuple now exists.
		$this->wpdb->last_error = '';
		$existing_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table} WHERE environment = %s AND merchant_id = %s AND square_event_id = %s LIMIT 1",
				$environment,
				$merchant_id,
				$event['event_id']
			)
		);
		if ( '' === (string) $this->wpdb->last_error && null !== $existing_id ) {
			return array( 'duplicate' => true );
		}

		return new WP_Error( 'square_webhook_storage_failed', 'Webhookイベントを保存できません。' );
	}
}

class Egov_Law_Monitor_Square_Webhook_Service {
	private const MAX_BODY_BYTES = 262144;
	private $repository;

	public function __construct( $repository ) {
		$this->repository = $repository;
	}

	/** Verify and persist a webhook. No event-specific business processing occurs. */
	public function receive( $raw_body, $signature, $config ) {
		if ( ! is_array( $config ) || ! isset( $config['environment'] ) || 'sandbox' !== $config['environment'] ) {
			return new WP_Error( 'square_webhook_production_disabled', 'Webhook受信は現在利用できません。' );
		}
		foreach ( array( 'signature_key', 'notification_url', 'merchant_id' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) || '' === $config[ $key ] ) {
				return new WP_Error( 'square_webhook_configuration_invalid', 'Webhook設定を確認できません。' );
			}
		}
		if ( strlen( $config['merchant_id'] ) > 64 ) {
			return new WP_Error( 'square_webhook_configuration_invalid', 'Webhook設定を確認できません。' );
		}
		if ( ! $this->is_valid_notification_url( $config['notification_url'] ) ) {
			return new WP_Error( 'square_webhook_configuration_invalid', 'Webhook設定を確認できません。' );
		}
		if ( ! is_string( $raw_body ) || strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'square_webhook_body_too_large', 'Webhookリクエストが大きすぎます。' );
		}
		if ( ! is_string( $signature ) || '' === $signature ) {
			return new WP_Error( 'square_webhook_signature_missing', 'Webhook署名を確認できません。' );
		}

		$expected_signature = base64_encode( hash_hmac( 'sha256', $config['notification_url'] . $raw_body, $config['signature_key'], true ) );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new WP_Error( 'square_webhook_signature_invalid', 'Webhook署名を確認できません。' );
		}

		$event = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $event ) ) {
			return new WP_Error( 'square_webhook_json_invalid', 'Webhookイベント形式を確認できません。' );
		}
		if (
			! isset( $event['event_id'], $event['type'], $event['merchant_id'] )
			|| ! is_string( $event['event_id'] )
			|| ! is_string( $event['type'] )
			|| ! is_string( $event['merchant_id'] )
			|| strlen( $event['merchant_id'] ) > 64
			|| ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $event['event_id'] )
			|| ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $event['type'] )
			|| ! hash_equals( $config['merchant_id'], $event['merchant_id'] )
		) {
			return new WP_Error( 'square_webhook_event_invalid', 'Webhookイベント形式を確認できません。' );
		}
		if ( isset( $event['created_at'] ) && ( ! is_string( $event['created_at'] ) || '' === $event['created_at'] ) ) {
			return new WP_Error( 'square_webhook_event_invalid', 'Webhookイベント形式を確認できません。' );
		}

		return $this->repository->insert_event( $event, 'sandbox', $config['merchant_id'], $raw_body );
	}

	private function is_valid_notification_url( $url ) {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& '' !== $parts['host']
			&& ! isset( $parts['user'], $parts['pass'], $parts['fragment'] )
			&& 0 === preg_match( '/\s/', $url );
	}
}

/** Read environment-specific webhook settings; never reuse Square API tokens. */
function egov_law_monitor_square_webhook_config() {
	$environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : '';
	if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
		$config = new WP_Error( 'square_webhook_configuration_invalid', 'Webhook設定を確認できません。' );
		return apply_filters( 'egov_law_monitor_square_webhook_config', $config );
	}

	$suffix = 'sandbox' === $environment ? 'SANDBOX' : 'PRODUCTION';
	$constant_names = array(
		'signature_key'    => 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_SIGNATURE_KEY_' . $suffix,
		'notification_url' => 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_NOTIFICATION_URL_' . $suffix,
		'merchant_id'      => 'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_' . $suffix,
	);
	$config = array( 'environment' => $environment );
	foreach ( $constant_names as $key => $constant_name ) {
		if ( defined( $constant_name ) && is_string( constant( $constant_name ) ) ) {
			$config[ $key ] = constant( $constant_name );
		}
	}

	return apply_filters( 'egov_law_monitor_square_webhook_config', $config );
}

function egov_law_monitor_square_webhook_service() {
	global $wpdb;
	$service = new Egov_Law_Monitor_Square_Webhook_Service( new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb ) );
	return apply_filters( 'egov_law_monitor_square_webhook_service', $service, $wpdb );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'egov-law-monitor/v1',
			'/square-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$config = egov_law_monitor_square_webhook_config();
					if ( is_wp_error( $config ) ) {
						return new WP_Error( 'square_webhook_unavailable', 'Webhook受信を利用できません。', array( 'status' => 503 ) );
					}
					if ( ! is_array( $config ) || ! isset( $config['environment'] ) || 'sandbox' !== $config['environment'] ) {
						return new WP_Error( 'square_webhook_disabled', 'Webhook受信は現在利用できません。', array( 'status' => 403 ) );
					}
					$service = egov_law_monitor_square_webhook_service();
					if ( ! $service instanceof Egov_Law_Monitor_Square_Webhook_Service ) {
						return new WP_Error( 'square_webhook_unavailable', 'Webhook受信を利用できません。', array( 'status' => 503 ) );
					}
					$result  = $service->receive( $request->get_body(), $request->get_header( 'x-square-hmacsha256-signature' ), $config );
					if ( is_wp_error( $result ) ) {
						$code = $result->get_error_code();
						$statuses = array(
							'square_webhook_configuration_invalid' => 503,
							'square_webhook_production_disabled'     => 403,
							'square_webhook_body_too_large'          => 413,
							'square_webhook_signature_missing'       => 401,
							'square_webhook_signature_invalid'       => 403,
							'square_webhook_storage_failed'           => 500,
						);
						$status = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 400;
						return new WP_Error( 'square_webhook_rejected', 'Webhookを受信できませんでした。', array( 'status' => $status ) );
					}
					return new WP_REST_Response( array( 'received' => true, 'duplicate' => ! empty( $result['duplicate'] ) ), 200 );
				},
			)
		);
	}
);
