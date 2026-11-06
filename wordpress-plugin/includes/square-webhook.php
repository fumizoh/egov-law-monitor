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
		if (
			! $this->valid_scope( $environment, $merchant_id )
			|| ! is_array( $event )
			|| ! isset( $event['event_id'], $event['type'], $event['merchant_id'] )
			|| ! is_string( $event['event_id'] )
			|| ! is_string( $event['type'] )
			|| ! is_string( $event['merchant_id'] )
			|| ! hash_equals( $merchant_id, $event['merchant_id'] )
			|| ! is_string( $raw_body )
		) {
			return new WP_Error( 'square_webhook_event_invalid', 'Webhookイベントを確認できません。' );
		}
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
		$inserted = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$table} (environment, merchant_id, square_event_id, event_type, square_created_at, received_at, processing_status, payload_hash, normalized_payload, created_at, updated_at) VALUES (%s, %s, %s, %s, NULLIF(%s, ''), %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id",
				$environment,
				$merchant_id,
				$event['event_id'],
				$event['type'],
				$created_at,
				$now,
				'received',
				hash( 'sha256', $raw_body ),
				$raw_body,
				$now,
				$now
			)
		);

		if ( 1 === (int) $inserted && '' === (string) $this->wpdb->last_error ) {
			return array( 'duplicate' => false );
		}
		if ( false === $inserted || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'square_webhook_storage_failed', 'Webhookイベントを保存できません。' );
		}

		// The no-op duplicate-key update avoids surfacing an expected SQL error.
		// Only acknowledge a duplicate if the exact scoped event now exists.
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
	 * @param string $environment Square environment scope.
	 * @param string $merchant_id Square merchant scope.
	 * @param int $limit Maximum number of rows (clamped to 1..100).
	 * @param int $stale_after_seconds Age after which a processing claim is stale.
	 * @return array|WP_Error Rows ordered by receive time, or a database error.
	 */
	public function get_events_ready_for_processing( $environment, $merchant_id, $limit = self::DEFAULT_BATCH_SIZE, $stale_after_seconds = self::DEFAULT_STALE_AFTER_SECONDS ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->valid_scope( $environment, $merchant_id ) ) {
			return new WP_Error( 'square_webhook_repository_scope_invalid', 'Webhook処理条件を確認できません。' );
		}
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
				"SELECT id, environment, merchant_id, square_event_id, event_type, square_created_at, received_at, processing_status, retry_count, next_retry_at, processing_started_at, claim_generation, payload_hash, normalized_payload FROM {$table} WHERE environment = %s AND merchant_id = %s AND (processing_status = 'received' OR (processing_status = 'retry' AND next_retry_at IS NOT NULL AND next_retry_at <= %s) OR (processing_status = 'processing' AND processing_started_at IS NOT NULL AND processing_started_at <= %s)) ORDER BY received_at ASC, id ASC LIMIT %d",
				$environment,
				$merchant_id,
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
	 * @param string $environment Square environment scope.
	 * @param string $merchant_id Square merchant scope.
	 * @param int $stale_after_seconds Stale processing threshold.
	 * @return array|false|WP_Error Claimed row, false if claim lost, or DB error.
	 */
	public function claim_event( $event_row_id, $environment, $merchant_id, $stale_after_seconds = self::DEFAULT_STALE_AFTER_SECONDS ) {
		$table = $this->wpdb->prefix . 'egov_law_square_events';
		if ( ! $this->is_positive_integer( $event_row_id ) || ! $this->valid_scope( $environment, $merchant_id ) ) {
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
				"UPDATE {$table} SET processing_status = 'processing', claim_generation = claim_generation + 1, processing_started_at = %s, next_retry_at = NULL, updated_at = %s WHERE id = %d AND environment = %s AND merchant_id = %s AND (processing_status = 'received' OR (processing_status = 'retry' AND next_retry_at IS NOT NULL AND next_retry_at <= %s) OR (processing_status = 'processing' AND processing_started_at IS NOT NULL AND processing_started_at <= %s))",
				$now,
				$now,
				(int) $event_row_id,
				$environment,
				$merchant_id,
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
				"SELECT id, environment, merchant_id, square_event_id, event_type, square_created_at, received_at, processing_status, retry_count, next_retry_at, processing_started_at, claim_generation, payload_hash, normalized_payload FROM {$table} WHERE id = %d AND environment = %s AND merchant_id = %s",
				(int) $event_row_id,
				$environment,
				$merchant_id
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

	private function valid_scope( $environment, $merchant_id ) {
		return is_string( $environment )
			&& in_array( $environment, array( 'sandbox', 'production' ), true )
			&& is_string( $merchant_id )
			&& preg_match( '/\A[A-Za-z0-9._:-]{1,64}\z/', $merchant_id );
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

/**
 * Process persisted, signature-verified Square webhook events outside the REST
 * request. This worker only matches; it never writes checkout, subscription,
 * invoice, user-meta, or entitlement records.
 */
class Egov_Law_Monitor_Square_Webhook_Event_Worker {
	private const MAX_BATCH_SIZE = 100;
	private const MAX_PAYLOAD_BYTES = 262144;
	private const SUPPORTED_EVENTS = array( 'subscription.created', 'subscription.updated', 'invoice.payment_made' );

	private $repository;
	private $client;
	private $matcher;

	public function __construct( Egov_Law_Monitor_Square_Webhook_Repository $repository, Egov_Law_Monitor_Square_Api_Client $client, Egov_Law_Monitor_Square_Subscription_Attempt_Matcher $matcher ) {
		$this->repository = $repository;
		$this->client     = $client;
		$this->matcher    = $matcher;
	}

	/**
	 * Process up to the requested number of ready events.
	 *
	 * @param int $limit Batch size, capped at 100.
	 * @return array|WP_Error Safe aggregate counts, or a database/configuration error.
	 */
	public function process_batch( $limit = 25 ) {
		if ( ! is_scalar( $limit ) || ! preg_match( '/\A[1-9][0-9]*\z/', (string) $limit ) ) {
			return new WP_Error( 'square_webhook_worker_arguments_invalid', 'Webhook処理件数を確認できません。' );
		}
		$limit = min( self::MAX_BATCH_SIZE, (int) $limit );
		$scope = $this->client->get_checkout_scope();
		if ( is_wp_error( $scope ) || ! isset( $scope['environment'], $scope['merchant_id'] ) || ! egov_law_monitor_square_webhook_environment_enabled( $scope['environment'] ) ) {
			return new WP_Error( 'square_webhook_worker_environment_disabled', 'Webhook処理を利用できません。' );
		}
		$events = $this->repository->get_events_ready_for_processing( $scope['environment'], $scope['merchant_id'], $limit );
		if ( is_wp_error( $events ) ) {
			return new WP_Error( 'square_webhook_worker_queue_unavailable', 'Webhook処理対象を取得できません。' );
		}

		$summary = array(
			'found'                    => count( $events ),
			'claimed'                  => 0,
			'processed'                => 0,
			'retry'                    => 0,
			'reconciliation_required' => 0,
			'skipped'                  => 0,
			'claim_errors'             => 0,
			'result_write_errors'      => 0,
			'lost_claims'              => 0,
		);

		foreach ( $events as $event_row ) {
			if ( ! isset( $event_row['id'] ) ) {
				$summary['claim_errors']++;
				continue;
			}
			$claim = $this->repository->claim_event( $event_row['id'], $scope['environment'], $scope['merchant_id'] );
			if ( is_wp_error( $claim ) ) {
				$summary['claim_errors']++;
				continue;
			}
			if ( false === $claim ) {
				$summary['skipped']++;
				continue;
			}
			$summary['claimed']++;
			if ( ! is_array( $claim ) || ! isset( $claim['id'], $claim['claim_generation'] ) ) {
				$summary['result_write_errors']++;
				continue;
			}

			try {
				$decision = $this->decide( $claim, $scope );
			} catch ( Throwable $exception ) {
				// Do not expose exception details. Leave a retryable, fixed-code trail.
				$decision = $this->decision( 'retry', 'webhook_worker_unexpected_error' );
			}

			try {
				$result = $this->record_decision( $claim, $decision );
			} catch ( Throwable $exception ) {
				$summary['result_write_errors']++;
				continue;
			}
			if ( is_wp_error( $result ) ) {
				$summary['result_write_errors']++;
				continue;
			}
			if ( false === $result ) {
				$summary['lost_claims']++;
				continue;
			}
			$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : $decision['status'];
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}

		return $summary;
	}

	private function decide( array $row, array $scope ) {
		$payload = isset( $row['normalized_payload'] ) ? $row['normalized_payload'] : null;
		$payload_hash = isset( $row['payload_hash'] ) ? $row['payload_hash'] : null;
		if (
			! is_string( $payload )
			|| '' === $payload
			|| strlen( $payload ) > self::MAX_PAYLOAD_BYTES
			|| ! is_string( $payload_hash )
			|| ! preg_match( '/\A[a-f0-9]{64}\z/i', $payload_hash )
			|| ! hash_equals( strtolower( $payload_hash ), hash( 'sha256', $payload ) )
		) {
			return $this->decision( 'reconciliation_required', 'stored_event_payload_invalid' );
		}

		$event = json_decode( $payload, true );
		if (
			JSON_ERROR_NONE !== json_last_error()
			|| ! is_array( $event )
			|| ! isset( $event['event_id'], $event['type'], $event['merchant_id'] )
			|| ! is_string( $event['event_id'] )
			|| ! is_string( $event['type'] )
			|| ! is_string( $event['merchant_id'] )
			|| ! isset( $row['square_event_id'], $row['event_type'], $row['merchant_id'], $row['environment'] )
			|| ! is_string( $row['square_event_id'] )
			|| ! is_string( $row['event_type'] )
			|| ! is_string( $row['merchant_id'] )
			|| ! is_string( $row['environment'] )
			|| ! hash_equals( $row['square_event_id'], $event['event_id'] )
			|| ! hash_equals( $row['event_type'], $event['type'] )
			|| ! hash_equals( $row['merchant_id'], $event['merchant_id'] )
			|| ! hash_equals( $scope['environment'], $row['environment'] )
			|| ! hash_equals( $scope['merchant_id'], $row['merchant_id'] )
		) {
			return $this->decision( 'reconciliation_required', 'stored_event_identity_invalid' );
		}

		if ( ! in_array( $event['type'], self::SUPPORTED_EVENTS, true ) ) {
			return $this->decision( 'processed', '' );
		}

		if ( ! isset( $scope['location_id'] ) || ! is_string( $scope['location_id'] ) ) {
			return $this->decision( 'retry', 'square_scope_unavailable' );
		}

		if ( 'subscription.created' === $event['type'] || 'subscription.updated' === $event['type'] ) {
			$subscription_id = $this->extract_resource_id( $event, 'subscription' );
			if ( is_wp_error( $subscription_id ) ) {
				return $this->decision( 'reconciliation_required', 'subscription_event_id_invalid' );
			}
			return $this->decide_match_result( $this->matcher->match_subscription( $subscription_id ) );
		}

		$invoice_id = $this->extract_resource_id( $event, 'invoice' );
		if ( is_wp_error( $invoice_id ) ) {
			return $this->decision( 'reconciliation_required', 'invoice_event_id_invalid' );
		}
		$invoice = $this->client->get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $this->is_invalid_square_response( $invoice )
				? $this->decision( 'reconciliation_required', 'invoice_response_invalid' )
				: $this->decision( 'retry', 'invoice_api_unavailable' );
		}
		if ( ! isset( $invoice['id'], $invoice['subscription_id'], $invoice['location_id'] ) || ! is_string( $invoice['id'] ) || ! is_string( $invoice['location_id'] ) || ! hash_equals( $invoice_id, $invoice['id'] ) || ! hash_equals( $scope['location_id'], $invoice['location_id'] ) ) {
			return $this->decision( 'reconciliation_required', 'invoice_identity_invalid' );
		}
		if ( ! is_string( $invoice['subscription_id'] ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $invoice['subscription_id'] ) ) {
			return $this->decision( 'reconciliation_required', 'invoice_subscription_id_missing_or_invalid' );
		}

		return $this->decide_match_result( $this->matcher->match_subscription( $invoice['subscription_id'] ) );
	}

	/** Extract a resource ID, requiring duplicate representations to agree. */
	private function extract_resource_id( array $event, $resource_type ) {
		if ( ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return new WP_Error( 'square_webhook_event_resource_missing', 'Webhook対象を確認できません。' );
		}
		$data = $event['data'];
		if ( isset( $data['type'] ) && ( ! is_string( $data['type'] ) || $resource_type !== $data['type'] ) ) {
			return new WP_Error( 'square_webhook_event_resource_type_invalid', 'Webhook対象を確認できません。' );
		}
		$ids = array();
		if ( array_key_exists( 'id', $data ) ) {
			$ids[] = $data['id'];
		}
		if ( isset( $data['object'] ) ) {
			if ( ! is_array( $data['object'] ) ) {
				return new WP_Error( 'square_webhook_event_resource_invalid', 'Webhook対象を確認できません。' );
			}
			if ( isset( $data['object'][ $resource_type ] ) ) {
				if ( ! is_array( $data['object'][ $resource_type ] ) ) {
					return new WP_Error( 'square_webhook_event_resource_invalid', 'Webhook対象を確認できません。' );
				}
				if ( array_key_exists( 'id', $data['object'][ $resource_type ] ) ) {
					$ids[] = $data['object'][ $resource_type ]['id'];
				}
			}
		}
		if ( empty( $ids ) ) {
			return new WP_Error( 'square_webhook_event_resource_missing', 'Webhook対象を確認できません。' );
		}
		$resource_id = null;
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/\A[A-Za-z0-9._:-]{1,255}\z/', $id ) || ( null !== $resource_id && ! hash_equals( $resource_id, $id ) ) ) {
				return new WP_Error( 'square_webhook_event_resource_invalid', 'Webhook対象を確認できません。' );
			}
			$resource_id = $id;
		}
		return $resource_id;
	}

	private function decide_match_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return $this->decision( 'retry', 'subscription_match_unavailable' );
		}
		if ( ! is_array( $result ) || ! isset( $result['status'] ) || ! is_string( $result['status'] ) ) {
			return $this->decision( 'reconciliation_required', 'subscription_match_result_invalid' );
		}
		switch ( $result['status'] ) {
			case 'subscription_matched':
				return $this->decision( 'processed', '' );
			case 'subscription_pending':
				return $this->decision( 'retry', 'subscription_pending' );
			case 'square_api_error':
				return $this->decision( 'retry', 'square_subscription_api_unavailable' );
			case 'reconciliation_required':
				return $this->decision( 'reconciliation_required', 'subscription_match_requires_review' );
			default:
				return $this->decision( 'reconciliation_required', 'subscription_match_result_invalid' );
		}
	}

	private function is_invalid_square_response( $error ) {
		$code = is_wp_error( $error ) ? $error->get_error_code() : '';
		return false !== strpos( $code, '_response_invalid' ) || false !== strpos( $code, '_id_invalid' );
	}

	private function decision( $status, $error_code ) {
		return array( 'status' => $status, 'error_code' => $error_code );
	}

	private function record_decision( array $claim, array $decision ) {
		switch ( $decision['status'] ) {
			case 'processed':
				return $this->repository->mark_event_processed( $claim['id'], (int) $claim['claim_generation'] );
			case 'retry':
				return $this->repository->mark_event_retry( $claim['id'], (int) $claim['claim_generation'], $decision['error_code'] );
			case 'reconciliation_required':
				return $this->repository->mark_event_reconciliation_required( $claim['id'], (int) $claim['claim_generation'], $decision['error_code'] );
			default:
				return new WP_Error( 'square_webhook_worker_decision_invalid', 'Webhook処理結果を確認できません。' );
		}
	}
}

/** Build an environment-scoped webhook event worker. No cron hook is registered. */
function egov_law_monitor_square_webhook_event_worker() {
	global $wpdb;
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return new WP_Error( 'square_webhook_worker_configuration_unavailable', 'Webhook処理を利用できません。' );
	}
	$scope = $client->get_checkout_scope();
	if ( is_wp_error( $scope ) || ! isset( $scope['environment'] ) || ! egov_law_monitor_square_webhook_environment_enabled( $scope['environment'] ) ) {
		return new WP_Error( 'square_webhook_worker_environment_disabled', 'Webhook処理を利用できません。' );
	}

	$checkout_repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
	$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $checkout_repository, $client );
	$payment_confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $checkout_repository, $client, $payment_matcher );
	$subscription_matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $checkout_repository, $client, $payment_confirmation );
	return new Egov_Law_Monitor_Square_Webhook_Event_Worker( new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb ), $client, $subscription_matcher );
}

class Egov_Law_Monitor_Square_Webhook_Service {
	private const MAX_BODY_BYTES = 262144;
	private $repository;

	public function __construct( $repository ) {
		$this->repository = $repository;
	}

	/** Verify and persist a webhook. No event-specific business processing occurs. */
	public function receive( $raw_body, $signature, $config ) {
		if ( ! is_array( $config ) || ! isset( $config['environment'] ) || ! egov_law_monitor_square_webhook_environment_enabled( $config['environment'] ) ) {
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
		if ( 'production' === $config['environment'] && ! egov_law_monitor_square_webhook_production_settings_ready( $config ) ) {
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

		return $this->repository->insert_event( $event, $config['environment'], $config['merchant_id'], $raw_body );
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

/** Return whether a specific Square environment is enabled for webhook work. */
function egov_law_monitor_square_webhook_environment_enabled( $environment ) {
	if ( 'sandbox' === $environment ) {
		return true;
	}
	return 'production' === $environment
		&& defined( 'EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED' )
		&& egov_law_monitor_square_webhook_activation_value_enabled( EGOV_LAW_MONITOR_SQUARE_PRODUCTION_WEBHOOKS_ENABLED );
}

/** Strict boolean check kept separate so non-boolean settings cannot enable Production. */
function egov_law_monitor_square_webhook_activation_value_enabled( $value ) {
	return true === $value;
}

/** Validate all server-side Production API settings without making a request. */
function egov_law_monitor_square_webhook_production_settings_ready( array $webhook_config ) {
	if (
		! egov_law_monitor_square_webhook_environment_enabled( 'production' )
		|| ! isset( $webhook_config['merchant_id'] )
		|| ! is_string( $webhook_config['merchant_id'] )
	) {
		return false;
	}
	$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
	if ( is_wp_error( $client ) ) {
		return false;
	}
	$scope = $client->get_checkout_scope();
	return ! is_wp_error( $scope )
		&& isset( $scope['environment'], $scope['merchant_id'], $scope['location_id'], $scope['subscription_plan_variation_id'] )
		&& 'production' === $scope['environment']
		&& is_string( $scope['merchant_id'] )
		&& hash_equals( $scope['merchant_id'], $webhook_config['merchant_id'] );
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
					if ( ! is_array( $config ) || ! isset( $config['environment'] ) || ! egov_law_monitor_square_webhook_environment_enabled( $config['environment'] ) ) {
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
