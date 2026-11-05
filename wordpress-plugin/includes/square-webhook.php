<?php

/**
 * Square webhook receiver. This stage verifies and persists events only; it
 * deliberately does not synchronize subscriptions, invoices, or entitlements.
 */
class Egov_Law_Monitor_Square_Webhook_Repository {
	private const DEFAULT_BATCH_SIZE = 25;
	private const MAX_BATCH_SIZE = 100;
	private const DEFAULT_STALE_AFTER_SECONDS = 300;
	private const MIN_STALE_AFTER_SECONDS = 60;
	private const MAX_STALE_AFTER_SECONDS = 86400;
	private const MAX_RETRIES = 5;
	private const RETRY_BASE_SECONDS = 60;

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

	/**
	 * Read events that are ready for a worker, without claiming them.
	 *
	 * @param int $limit Maximum number of rows (clamped to 1..100).
	 * @param int $stale_after_seconds Age after which a processing claim is stale.
	 * @return array|WP_Error Rows ordered by receive time, or a database error.
	 */
	public function get_events_ready_for_processing( $limit = self::DEFAULT_BATCH_SIZE, $stale_after_seconds = self::DEFAULT_STALE_AFTER_SECONDS ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		$limit = $this->normalize_limit( $limit );
		$stale_after_seconds = $this->normalize_stale_after( $stale_after_seconds );
		if ( false === $limit || false === $stale_after_seconds ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理条件を確認できません。' );
		}

		$now = current_time( 'mysql', true );
		$stale_before = $this->utc_time_minus_seconds( $now, $stale_after_seconds );
		if ( false === $stale_before ) {
			return new WP_Error( 'square_webhook_repository_time_invalid', 'Webhook処理条件を確認できません。' );
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, environment, merchant_id, square_event_id, event_type, square_created_at, received_at, processing_status, retry_count, next_retry_at, processing_started_at, claim_generation, normalized_payload FROM {$table} WHERE processing_status = 'received' OR (processing_status = 'retry' AND next_retry_at IS NOT NULL AND next_retry_at <= %s) OR (processing_status = 'processing' AND processing_started_at IS NOT NULL AND processing_started_at <= %s) ORDER BY received_at ASC, id ASC LIMIT %d",
				$now,
				$stale_before,
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理情報を読み取れません。' );
		}
		return $rows;
	}

	/**
	 * Atomically claim a due event and increment its fencing generation.
	 *
	 * The local database row ID is used here (not Square's event ID). A false
	 * return means another worker already claimed it or it is not due yet.
	 *
	 * @param int $event_row_id Local event-table primary key.
	 * @param int $stale_after_seconds Stale processing threshold.
	 * @return array|false|WP_Error Claimed row, false if claim lost, or DB error.
	 */
	public function claim_event( $event_row_id, $stale_after_seconds = self::DEFAULT_STALE_AFTER_SECONDS ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->is_positive_integer( $event_row_id ) ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理条件を確認できません。' );
		}
		$stale_after_seconds = $this->normalize_stale_after( $stale_after_seconds );
		if ( false === $stale_after_seconds ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理条件を確認できません。' );
		}

		$now = current_time( 'mysql', true );
		$stale_before = $this->utc_time_minus_seconds( $now, $stale_after_seconds );
		if ( false === $stale_before ) {
			return new WP_Error( 'square_webhook_repository_time_invalid', 'Webhook処理条件を確認できません。' );
		}

		$this->wpdb->last_error = '';
		$claimed = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET processing_status = 'processing', claim_generation = claim_generation + 1, processing_started_at = %s, next_retry_at = NULL, updated_at = %s WHERE id = %d AND (processing_status = 'received' OR (processing_status = 'retry' AND next_retry_at IS NOT NULL AND next_retry_at <= %s) OR (processing_status = 'processing' AND processing_started_at IS NOT NULL AND processing_started_at <= %s))",
				$now,
				$now,
				(int) $event_row_id,
				$now,
				$stale_before
			)
		);
		if ( false === $claimed || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理情報を更新できません。' );
		}
		if ( 1 !== (int) $claimed ) {
			return false;
		}

		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, environment, merchant_id, square_event_id, event_type, square_created_at, received_at, processing_status, retry_count, next_retry_at, processing_started_at, claim_generation, normalized_payload FROM {$table} WHERE id = %d",
				(int) $event_row_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || '' !== (string) $this->wpdb->last_error || 'processing' !== $row['processing_status'] ) {
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理情報を読み取れません。' );
		}
		return $row;
	}

	/** Mark an event processed only if the worker still owns its claim. */
	public function mark_event_processed( $event_row_id, $claim_generation ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->valid_claim( $event_row_id, $claim_generation ) ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理結果を確認できません。' );
		}
		$now = current_time( 'mysql', true );
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET processing_status = 'processed', processing_started_at = NULL, next_retry_at = NULL, processed_at = %s, last_error_code = '', last_error_message = NULL, updated_at = %s WHERE id = %d AND processing_status = 'processing' AND claim_generation = %d",
				$now,
				$now,
				(int) $event_row_id,
				(int) $claim_generation
			)
		);
		return $this->result_update_status( $updated, 'Webhook処理結果を保存できません。' );
	}

	/**
	 * Record a transient failure using 1, 2, 4, 8, then 16 minute backoff.
	 * After five scheduled retries, the next failed attempt requires review.
	 */
	public function mark_event_retry( $event_row_id, $claim_generation, $error_code ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->valid_claim( $event_row_id, $claim_generation ) ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理結果を確認できません。' );
		}
		$safe_code = $this->safe_error_code( $error_code );
		$this->wpdb->last_error = '';
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理結果を保存できません。' );
		}

		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT retry_count FROM {$table} WHERE id = %d AND processing_status = 'processing' AND claim_generation = %d FOR UPDATE",
				(int) $event_row_id,
				(int) $claim_generation
			),
			ARRAY_A
		);
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理結果を保存できません。' );
		}
		if ( ! is_array( $row ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		$current_retry_count = max( 0, (int) $row['retry_count'] );
		$next_retry_count = $current_retry_count;
		if ( $current_retry_count >= self::MAX_RETRIES ) {
			$status = 'reconciliation_required';
			$next_retry_at = null;
		} else {
			$next_retry_count++;
			$status = 'retry';
			$delay = self::RETRY_BASE_SECONDS * ( 2 ** ( $next_retry_count - 1 ) );
			$next_retry_at = $this->utc_time_plus_seconds( current_time( 'mysql', true ), $delay );
			if ( false === $next_retry_at ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'square_webhook_repository_time_invalid', 'Webhook処理結果を保存できません。' );
			}
		}

		$this->wpdb->last_error = '';
		$updated = $this->wpdb->update(
			$table,
			array(
				'processing_status'    => $status,
				'retry_count'          => $next_retry_count,
				'next_retry_at'        => $next_retry_at,
				'processing_started_at' => null,
				'last_error_code'      => $safe_code,
				'last_error_message'   => null,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array(
				'id'                => (int) $event_row_id,
				'processing_status' => 'processing',
				'claim_generation'  => (int) $claim_generation,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%d' )
		);
		if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理結果を保存できません。' );
		}
		if ( false === $this->wpdb->query( 'COMMIT' ) || '' !== (string) $this->wpdb->last_error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'square_webhook_repository_database_unavailable', 'Webhook処理結果を保存できません。' );
		}

		return array(
			'status'         => $status,
			'retry_count'    => $next_retry_count,
			'next_retry_at'  => $next_retry_at,
		);
	}

	/** Move an unmatchable event to manual reconciliation, fenced by its claim. */
	public function mark_event_reconciliation_required( $event_row_id, $claim_generation, $error_code ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->valid_claim( $event_row_id, $claim_generation ) ) {
			return new WP_Error( 'square_webhook_repository_arguments_invalid', 'Webhook処理結果を確認できません。' );
		}
		$now = current_time( 'mysql', true );
		$this->wpdb->last_error = '';
		$updated = $this->wpdb->update(
			$table,
			array(
				'processing_status'     => 'reconciliation_required',
				'next_retry_at'         => null,
				'processing_started_at' => null,
				'last_error_code'       => $this->safe_error_code( $error_code ),
				'last_error_message'    => null,
				'updated_at'            => $now,
			),
			array(
				'id'                => (int) $event_row_id,
				'processing_status' => 'processing',
				'claim_generation'  => (int) $claim_generation,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%d' )
		);
		return $this->result_update_status( $updated, 'Webhook処理結果を保存できません。' );
	}

	private function result_update_status( $updated, $message ) {
		if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_webhook_repository_database_unavailable', $message );
		}
		return 1 === (int) $updated;
	}

	private function valid_claim( $event_row_id, $claim_generation ) {
		return $this->is_positive_integer( $event_row_id ) && $this->is_positive_integer( $claim_generation );
	}

	private function is_positive_integer( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/\A[1-9][0-9]*\z/', (string) $value ) ) {
			return false;
		}
		$integer = (int) $value;
		return $integer > 0 && (string) $integer === (string) $value;
	}

	private function normalize_limit( $limit ) {
		if ( ! is_scalar( $limit ) || ! preg_match( '/\A[1-9][0-9]*\z/', (string) $limit ) ) {
			return false;
		}
		return min( self::MAX_BATCH_SIZE, (int) $limit );
	}

	private function normalize_stale_after( $seconds ) {
		if ( ! is_scalar( $seconds ) || ! preg_match( '/\A[0-9]+\z/', (string) $seconds ) ) {
			return false;
		}
		$seconds = (int) $seconds;
		return $seconds < self::MIN_STALE_AFTER_SECONDS || $seconds > self::MAX_STALE_AFTER_SECONDS ? false : $seconds;
	}

	private function utc_time_minus_seconds( $datetime, $seconds ) {
		try {
			return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) ) )->modify( '-' . (int) $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return false;
		}
	}

	private function utc_time_plus_seconds( $datetime, $seconds ) {
		try {
			return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) ) )->modify( '+' . (int) $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return false;
		}
	}

	private function safe_error_code( $error_code ) {
		$code = sanitize_key( is_scalar( $error_code ) ? (string) $error_code : '' );
		return '' === $code ? 'webhook_processing_failed' : substr( $code, 0, 100 );
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
