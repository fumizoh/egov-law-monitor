<?php

/**
 * Persistence service for AI summary quota reservations.
 *
 * This service deliberately does not call Square or generate summaries. All
 * timestamps stored in the ledger are UTC; Standard usage_month is calculated
 * in the WordPress site's timezone.
 */
class Egov_Law_Monitor_AI_Summary_Usage_Service {
	const RESERVATION_TTL_SECONDS = 900;

	private $wpdb;
	private $environment;

	public function __construct( $wpdb, $environment = 'production' ) {
		$this->wpdb        = $wpdb;
		$this->environment = in_array( $environment, array( 'production', 'sandbox' ), true ) ? $environment : '';
	}

	/** Reserve one quota unit atomically. */
	public function reserve( $user_id, $plan_code, $request_key, DateTimeImmutable $now = null, $subscription_id = null ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! in_array( $plan_code, array( 'free', 'standard' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $request_key ) || '' === $this->environment ) {
			return new WP_Error( 'invalid_usage_reservation', 'AI要約の利用予約情報が不正です。' );
		}

		$now       = $this->normalize_now( $now );
		$now_utc   = $now->setTimezone( new DateTimeZone( 'UTC' ) );
		$now_sql   = $now_utc->format( 'Y-m-d H:i:s' );
		$expires   = $now_utc->modify( '+' . self::RESERVATION_TTL_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' );
		$usage_month = 'standard' === $plan_code ? $now->setTimezone( wp_timezone() )->format( 'Y-m-01' ) : null;
		$table     = $this->usage_table();
		$users     = $this->wpdb->users;

		if ( ! $this->users_table_is_innodb() || ! $this->table_is_innodb( $table ) ) {
			return new WP_Error( 'usage_lock_unsupported', '利用枠を安全に確認できないため、AI要約を開始できません。' );
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'usage_db_error', '利用枠を確認できませんでした。' );
		}

		try {
			$locked_user = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM {$users} WHERE ID = %d FOR UPDATE", $user_id ) );
			if ( null === $locked_user || '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_db_error', '利用枠を確認できませんでした。' );
			}
			if ( ! $locked_user ) {
				return $this->rollback_error( 'usage_user_missing', 'ユーザーを確認できませんでした。' );
			}

			$existing = $this->wpdb->get_row( $this->wpdb->prepare(
				"SELECT id, usage_status, reservation_expires_at FROM {$table} WHERE environment = %s AND user_id = %d AND request_key = %s FOR UPDATE",
				$this->environment,
				$user_id,
				$request_key
			), ARRAY_A );
			if ( '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_db_error', '利用枠を確認できませんでした。' );
			}
			if ( is_array( $existing ) && 'committed' === $existing['usage_status'] ) {
				$this->wpdb->query( 'COMMIT' );
				return array( 'id' => (int) $existing['id'], 'status' => 'committed', 'already_committed' => true );
			}
			if ( is_array( $existing ) && 'reserved' === $existing['usage_status'] && $existing['reservation_expires_at'] > $now_sql ) {
				$this->wpdb->query( 'COMMIT' );
				return array( 'id' => (int) $existing['id'], 'status' => 'reserved', 'already_reserved' => true );
			}

			$limit = 'free' === $plan_code ? 3 : 100;
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE environment = %s AND user_id = %d AND plan_code = %s AND (usage_status = 'committed' OR (usage_status = 'reserved' AND reservation_expires_at > %s))";
			$args = array( $this->environment, $user_id, $plan_code, $now_sql );
			if ( 'standard' === $plan_code ) {
				$count_sql .= ' AND usage_month = %s';
				$args[] = $usage_month;
			}
			$count = $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $args ) );
			if ( null === $count || '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_db_error', '利用枠を確認できませんでした。' );
			}
			$legacy_baseline = ( 'free' === $plan_code && 'production' === $this->environment )
				? max( 0, (int) get_user_meta( $user_id, 'egov_law_monitor_ai_summary_trial_usage', true ) )
				: 0;
			if ( (int) $count + $legacy_baseline >= $limit ) {
				return $this->rollback_error( 'usage_limit_reached', 'AI要約の利用上限に達しています。' );
			}

			$data = array(
				'environment' => $this->environment,
				'user_id' => $user_id,
				'subscription_id' => $subscription_id ? absint( $subscription_id ) : null,
				'request_key' => $request_key,
				'plan_code' => $plan_code,
				'usage_month' => $usage_month,
				'usage_status' => 'reserved',
				'reserved_at' => $now_sql,
				'reservation_expires_at' => $expires,
				'committed_at' => null,
				'released_at' => null,
				'expired_at' => null,
				'created_at' => $now_sql,
				'updated_at' => $now_sql,
			);
			if ( is_array( $existing ) ) {
				$updated = $this->wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
				$id = (int) $existing['id'];
			} else {
				$updated = $this->wpdb->insert( $table, $data );
				$id = (int) $this->wpdb->insert_id;
			}
			if ( false === $updated || ! $id || '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_db_error', '利用枠を予約できませんでした。' );
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'usage_db_error', '利用枠を予約できませんでした。' );
			}
			return array( 'id' => $id, 'status' => 'reserved', 'already_reserved' => false );
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'usage_db_error', '利用枠を予約できませんでした。' );
		}
	}

	/** Atomically persist the history record and confirm the reservation. */
	public function commit_with_history( $usage_id, $user_id, callable $save_history, DateTimeImmutable $now = null ) {
		$now = $this->normalize_now( $now )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$table = $this->usage_table();
		$history_table = $this->wpdb->prefix . 'egov_law_ai_summary_history';
		if ( ! $this->table_is_innodb( $history_table ) || ! $this->table_is_innodb( $table ) || ! $this->users_table_is_innodb() ) {
			return new WP_Error( 'usage_storage_unsupported', 'AI要約の利用を安全に保存できないため、処理を完了できません。' );
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'usage_db_error', 'AI要約の利用を確定できませんでした。' );
		}
		try {
			$user = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM {$this->wpdb->users} WHERE ID = %d FOR UPDATE", absint( $user_id ) ) );
			$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND environment = %s AND user_id = %d FOR UPDATE", absint( $usage_id ), $this->environment, absint( $user_id ) ), ARRAY_A );
			if ( ! $user || ! is_array( $row ) || '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_reservation_missing', 'AI要約の利用予約を確認できませんでした。' );
			}
			if ( 'committed' === $row['usage_status'] ) {
				$this->wpdb->query( 'COMMIT' );
				return array( 'history_id' => (int) $row['summary_history_id'], 'already_committed' => true );
			}
			if ( 'reserved' !== $row['usage_status'] || $row['reservation_expires_at'] <= $now ) {
				return $this->rollback_error( 'usage_reservation_expired', 'AI要約の利用予約の有効期限が切れました。もう一度お試しください。' );
			}
			$history_id = call_user_func( $save_history );
			if ( ! $history_id || is_wp_error( $history_id ) ) {
				return $this->rollback_error( 'usage_history_error', 'AI要約の履歴を保存できませんでした。' );
			}
			$updates = array(
				'summary_history_id' => absint( $history_id ),
				'usage_status' => 'committed',
				'committed_at' => $now,
				'reservation_expires_at' => null,
				'updated_at' => $now,
			);
			if ( 'standard' === $row['plan_code'] ) {
				$month = new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) );
				$month = $month->setTimezone( wp_timezone() )->format( 'Y-m-01' );
				if ( $month !== $row['usage_month'] ) {
					$count = $this->wpdb->get_var( $this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE environment = %s AND user_id = %d AND plan_code = 'standard' AND usage_month = %s AND (usage_status = 'committed' OR (usage_status = 'reserved' AND reservation_expires_at > %s))",
						$this->environment, absint( $user_id ), $month, $now
					) );
					if ( null === $count || '' !== (string) $this->wpdb->last_error || (int) $count >= 100 ) {
						return $this->rollback_error( 'usage_limit_reached', '今月のAI要約利用回数の上限に達しています。' );
					}
					$updates['usage_month'] = $month;
				}
			}
			$updated = $this->wpdb->update( $table, $updates, array( 'id' => absint( $usage_id ), 'usage_status' => 'reserved' ) );
			if ( false === $updated || 1 !== $updated || '' !== (string) $this->wpdb->last_error ) {
				return $this->rollback_error( 'usage_db_error', 'AI要約の利用を確定できませんでした。' );
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'usage_db_error', 'AI要約の利用を確定できませんでした。' );
			}
			return array( 'history_id' => absint( $history_id ), 'already_committed' => false );
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'usage_db_error', 'AI要約の利用を確定できませんでした。' );
		}
	}

	/** Release an uncommitted reservation; repeated calls are harmless. */
	public function release( $usage_id, $user_id, DateTimeImmutable $now = null ) {
		$now = $this->normalize_now( $now )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$result = $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE {$this->usage_table()} SET usage_status = 'released', released_at = %s, updated_at = %s WHERE id = %d AND environment = %s AND user_id = %d AND usage_status = 'reserved'",
			$now, $now, absint( $usage_id ), $this->environment, absint( $user_id )
		) );
		return false === $result ? new WP_Error( 'usage_db_error', 'AI要約の利用予約を解放できませんでした。' ) : true;
	}

	/** Expire abandoned reservations. Returns number of rows transitioned. */
	public function expire_reservations( DateTimeImmutable $now = null ) {
		$now = $this->normalize_now( $now )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$result = $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE {$this->usage_table()} SET usage_status = 'expired', expired_at = %s, updated_at = %s WHERE environment = %s AND usage_status = 'reserved' AND reservation_expires_at <= %s",
			$now, $now, $this->environment, $now
		) );
		return false === $result ? new WP_Error( 'usage_db_error', '期限切れ予約を更新できませんでした。' ) : (int) $result;
	}

	/** Count active reservations and confirmed uses for display. */
	public function count_usage( $user_id, $plan_code, DateTimeImmutable $now = null ) {
		$now = $this->normalize_now( $now );
		$now_sql = $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$sql = "SELECT COUNT(*) FROM {$this->usage_table()} WHERE environment = %s AND user_id = %d AND plan_code = %s AND (usage_status = 'committed' OR (usage_status = 'reserved' AND reservation_expires_at > %s))";
		$args = array( $this->environment, absint( $user_id ), $plan_code, $now_sql );
		if ( 'standard' === $plan_code ) {
			$sql .= ' AND usage_month = %s';
			$args[] = $now->setTimezone( wp_timezone() )->format( 'Y-m-01' );
		}
		$count = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $args ) );
		return null === $count || '' !== (string) $this->wpdb->last_error ? new WP_Error( 'usage_db_error', 'AI要約の利用状況を取得できませんでした。' ) : (int) $count;
	}

	private function usage_table() { return $this->wpdb->prefix . 'egov_law_ai_summary_usage'; }

	private function normalize_now( DateTimeImmutable $now = null ) {
		if ( $now ) { return $now; }
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	private function users_table_is_innodb() {
		return $this->table_is_innodb( $this->wpdb->users );
	}

	private function table_is_innodb( $table_name ) {
		$status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ), ARRAY_A );
		return is_array( $status ) && isset( $status['Engine'] ) && 'InnoDB' === $status['Engine'] && '' === (string) $this->wpdb->last_error;
	}

	private function rollback_error( $code, $message ) {
		$this->wpdb->query( 'ROLLBACK' );
		return new WP_Error( $code, $message );
	}
}

/** Resolve the isolated current usage environment. */
function egov_law_monitor_ai_usage_service() {
	global $wpdb;
	$environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : 'production';
	return new Egov_Law_Monitor_AI_Summary_Usage_Service( $wpdb, $environment );
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'egov_law_monitor_expire_ai_usage_reservations' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'egov_law_monitor_expire_ai_usage_reservations' );
	}
} );
add_action( 'egov_law_monitor_expire_ai_usage_reservations', function () {
	$result = egov_law_monitor_ai_usage_service()->expire_reservations();
	if ( is_wp_error( $result ) ) {
		error_log( 'e-Gov Law Monitor: Failed to expire AI summary usage reservations.' );
	}
} );

