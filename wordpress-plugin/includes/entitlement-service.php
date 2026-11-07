<?php

/**
 * Read-only access to subscription data used by the entitlement policy.
 *
 * The repository treats `payment_status` as an application-normalized value.
 * Only settled values are excluded from the unresolved invoice count; unknown
 * values therefore fail closed through the policy's payment-grace rules.
 */
class Egov_Law_Monitor_Subscription_Repository {
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Build a normalized policy snapshot for one user in one Square environment.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $environment `production` or `sandbox`.
	 * @return array|WP_Error
	 */
	public function get_entitlement_snapshot( $user_id, $environment ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! in_array( $environment, array( 'production', 'sandbox' ), true ) ) {
			return new WP_Error( 'entitlement_invalid_scope', '契約情報の検索条件が不正です。' );
		}

		$subscriptions = $this->wpdb->prefix . 'egov_law_subscriptions';
		$invoices      = $this->wpdb->prefix . 'egov_law_subscription_invoices';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$subscriptions} WHERE user_id = %d AND environment = %s ORDER BY id ASC",
				$user_id,
				$environment
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'entitlement_database_error', '契約情報を読み取れませんでした。' );
		}
		if ( empty( $rows ) ) {
			return array( 'sync_status' => 'ok', 'contracts' => array() );
		}

		$subscription_ids = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['id'] ) || (int) $row['user_id'] !== $user_id || $row['environment'] !== $environment ) {
				return new WP_Error( 'entitlement_data_inconsistent', '契約データのユーザーまたは環境が一致しません。' );
			}
			$subscription_ids[] = (int) $row['id'];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $subscription_ids ), '%d' ) );
		$this->wpdb->last_error = '';
		$invoice_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$invoices} WHERE subscription_id IN ({$placeholders}) ORDER BY id ASC",
				$subscription_ids
			),
			ARRAY_A
		);
		if ( ! is_array( $invoice_rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'entitlement_database_error', '請求情報を読み取れませんでした。' );
		}

		$contracts_by_id = array();
		foreach ( $rows as $row ) {
			if ( ! array_key_exists( 'current_slot', $row ) || ( null !== $row['current_slot'] && 1 !== (int) $row['current_slot'] ) ) {
				return new WP_Error( 'entitlement_data_inconsistent', '現行契約の識別情報が不正です。' );
			}
			$contracts_by_id[ (int) $row['id'] ] = array(
				'user_id'                  => $user_id,
				'environment'              => $environment,
				'merchant_id'              => (string) $row['merchant_id'],
				'is_current'               => null !== $row['current_slot'],
				'plan_code'                => (string) $row['plan_code'],
				'status'                   => (string) $row['internal_status'],
				'square_status'            => (string) $row['square_status'],
				'initial_payment_status'   => (string) $row['initial_payment_status'],
				'approval_status'          => 'missing',
				'approved_period_start_at' => null,
				'approved_period_end_at'   => null,
				'current_period_start_at'  => $this->to_rfc3339_utc( $row['current_period_start'] ),
				'paid_through_at'          => $this->to_rfc3339_utc( $row['current_period_end'] ),
				'unresolved_invoice_count' => 0,
				'review_pending_invoice_count' => 0,
				'confirmed_failure_invoice_count' => 0,
				'first_failure_at'         => $this->to_rfc3339_utc( $row['first_payment_failed_at'] ),
				'grace_ends_at'            => $this->to_rfc3339_utc( $row['grace_ends_at'] ),
				'review_grace_ends_at'    => $this->to_rfc3339_utc( $row['review_grace_ends_at'] ),
				'admin_control_status'     => 'ok',
				'admin_suspended'          => false,
			);
		}

		$latest_approvals = $this->get_latest_reviews( $subscription_ids, array( 'approve', 'correct' ) );
		if ( is_wp_error( $latest_approvals ) ) {
			return $latest_approvals;
		}
		foreach ( $latest_approvals as $review ) {
			$contract_id = isset( $review['contract_id'] ) ? (int) $review['contract_id'] : 0;
			if ( ! isset( $contracts_by_id[ $contract_id ] ) ) {
				return new WP_Error( 'entitlement_data_inconsistent', '承認履歴と契約の対応が一致しません。' );
			}
			$contract = $contracts_by_id[ $contract_id ];
			if ( ! $this->review_matches_contract( $review, $contract_id, $contract ) ) {
				$contracts_by_id[ $contract_id ]['approval_status'] = 'invalid';
				continue;
			}
			$start = $this->review_boundary_to_utc( $review['approved_period_start'], $review['subscription_timezone'] );
			$end   = $this->review_boundary_to_utc( $review['approved_period_end'], $review['subscription_timezone'] );
			if ( null === $start || null === $end || $start >= $end ) {
				$contracts_by_id[ $contract_id ]['approval_status'] = 'invalid';
				continue;
			}
			$contracts_by_id[ $contract_id ]['approval_status'] = 'valid';
			$contracts_by_id[ $contract_id ]['approved_period_start_at'] = $start;
			$contracts_by_id[ $contract_id ]['approved_period_end_at'] = $end;
		}

		$latest_controls = $this->get_latest_reviews( $subscription_ids, array( 'suspend', 'resume' ) );
		if ( is_wp_error( $latest_controls ) ) {
			return $latest_controls;
		}
		foreach ( $latest_controls as $review ) {
			$contract_id = isset( $review['contract_id'] ) ? (int) $review['contract_id'] : 0;
			if ( ! isset( $contracts_by_id[ $contract_id ] ) || ! $this->review_matches_contract( $review, $contract_id, $contracts_by_id[ $contract_id ] ) ) {
				if ( isset( $contracts_by_id[ $contract_id ] ) ) {
					$contracts_by_id[ $contract_id ]['admin_control_status'] = 'invalid';
				}
				continue;
			}
			$contracts_by_id[ $contract_id ]['admin_suspended'] = 'suspend' === $review['action_type'];
		}

		$settled_statuses = array( 'paid', 'succeeded', 'completed' );
		foreach ( $invoice_rows as $invoice ) {
			$subscription_id = (int) $invoice['subscription_id'];
			if ( ! isset( $contracts_by_id[ $subscription_id ] ) ) {
				return new WP_Error( 'entitlement_data_inconsistent', '契約に紐づかない請求情報が見つかりました。' );
			}
			$contract = $contracts_by_id[ $subscription_id ];
			if (
				(int) $invoice['user_id'] !== $contract['user_id']
				|| $invoice['environment'] !== $contract['environment']
				|| $invoice['merchant_id'] !== $contract['merchant_id']
			) {
				return new WP_Error( 'entitlement_data_inconsistent', '請求情報と契約のユーザー・環境・加盟店が一致しません。' );
			}
			$payment_status = strtolower( (string) $invoice['payment_status'] );
			if ( ! in_array( $payment_status, $settled_statuses, true ) ) {
				++$contracts_by_id[ $subscription_id ]['unresolved_invoice_count'];
			}
			if ( in_array( $payment_status, array( 'failed', 'invoice_failed' ), true ) ) {
				++$contracts_by_id[ $subscription_id ]['confirmed_failure_invoice_count'];
			}
			if ( in_array( $payment_status, array( 'unpaid', 'processing' ), true ) ) {
				$due_at = $this->to_rfc3339_utc( $invoice['due_at'] );
				$paid_through = egov_law_monitor_parse_entitlement_time( $contract['paid_through_at'] );
				$due = egov_law_monitor_parse_entitlement_time( $due_at );
				if ( null !== $paid_through && null !== $due && $due <= $paid_through ) {
					++$contracts_by_id[ $subscription_id ]['review_pending_invoice_count'];
				}
			}
		}

		return array(
			'sync_status' => $this->get_snapshot_sync_status( $rows ),
			'contracts'   => array_values( $contracts_by_id ),
		);
	}

	/**
	 * Read only the newest approval/correction or suspend/resume event per contract.
	 * Holds do not change an already-approved period; they cannot create one either.
	 *
	 * @param int[]    $contract_ids Local contract IDs.
	 * @param string[] $actions      Relevant append-only review actions.
	 * @return array|WP_Error
	 */
	private function get_latest_reviews( $contract_ids, $actions ) {
		if ( empty( $contract_ids ) ) {
			return array();
		}
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$id_placeholders = implode( ', ', array_fill( 0, count( $contract_ids ), '%d' ) );
		$action_placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
		$sql = "SELECT review.* FROM {$table} AS review INNER JOIN (
			SELECT contract_id, MAX(id) AS latest_id FROM {$table}
			WHERE contract_id IN ({$id_placeholders}) AND action_type IN ({$action_placeholders})
			GROUP BY contract_id
		) AS latest ON review.id = latest.latest_id";
		$args = array_merge( $contract_ids, $actions );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->wpdb->last_error ) {
			return new WP_Error( 'entitlement_database_error', '承認履歴を読み取れませんでした。' );
		}
		return $rows;
	}

	private function review_matches_contract( $review, $contract_id, $contract ) {
		if (
			! isset( $review['id'], $review['contract_id'], $review['user_id'], $review['environment'], $review['merchant_id'], $review['reviewer_user_id'], $review['action_type'] )
			|| (int) $review['id'] < 1
			|| (int) $review['contract_id'] !== $contract_id
			|| (int) $review['user_id'] !== $contract['user_id']
			|| $review['environment'] !== $contract['environment']
			|| $review['merchant_id'] !== $contract['merchant_id']
			|| (int) $review['reviewer_user_id'] < 1
		) {
			return false;
		}
		if ( in_array( $review['action_type'], array( 'approve', 'correct' ), true ) ) {
			return isset( $review['approved_period_start'], $review['approved_period_end'], $review['subscription_timezone'], $review['monthly_billing_anchor_date'] )
				&& ( ! empty( $review['square_payment_id'] ) || ! empty( $review['square_invoice_id'] ) )
				&& '00:00:00' === substr( (string) $review['approved_period_start'], 11 )
				&& '00:00:00' === substr( (string) $review['approved_period_end'], 11 )
				&& $this->is_strict_datetime( $review['approved_period_start'] )
				&& $this->is_strict_datetime( $review['approved_period_end'] )
				&& $this->is_valid_timezone( $review['subscription_timezone'] )
				&& ctype_digit( (string) $review['monthly_billing_anchor_date'] )
				&& (int) $review['monthly_billing_anchor_date'] >= 1
				&& (int) $review['monthly_billing_anchor_date'] <= 31;
		}
		return in_array( $review['action_type'], array( 'suspend', 'resume' ), true );
	}

	private function review_boundary_to_utc( $value, $timezone ) {
		if ( ! $this->is_strict_datetime( $value ) || ! $this->is_valid_timezone( $timezone ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( $timezone ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	private function is_strict_datetime( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
		$errors = DateTimeImmutable::getLastErrors();
		return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function is_valid_timezone( $value ) {
		return is_string( $value ) && ( 'UTC' === $value || in_array( $value, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) );
	}

	/**
	 * Derive the policy-level sync state from current contract rows only.
	 *
	 * The policy accepts `ok` and `error`. A known `unknown` value, an empty
	 * value, or any unrecognized value is passed as `unknown`, which the policy
	 * rejects as invalid input (Free plus recheck). Multiple current contracts
	 * are left for the policy's dedicated fail-closed check.
	 *
	 * @param array $rows Subscription rows for one user and environment.
	 * @return string
	 */
	private function get_snapshot_sync_status( $rows ) {
		$current_rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return isset( $row['current_slot'] ) && null !== $row['current_slot'] && '' !== $row['current_slot'];
				}
			)
		);

		if ( 1 !== count( $current_rows ) ) {
			return 'ok';
		}

		$sync_state = isset( $current_rows[0]['sync_state'] ) ? (string) $current_rows[0]['sync_state'] : '';
		if ( 'ok' === $sync_state || 'error' === $sync_state ) {
			return $sync_state;
		}

		return 'unknown';
	}

	private function to_rfc3339_utc( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $date->format( 'Y-m-d\TH:i:s\Z' );
	}
}

/** Resolve effective entitlement using a normalized contract snapshot. */
class Egov_Law_Monitor_Entitlement_Service {
	private $repository;
	private $environment;

	public function __construct( Egov_Law_Monitor_Subscription_Repository $repository, $environment ) {
		$this->repository  = $repository;
		$this->environment = $environment;
	}

	/**
	 * @param int                 $user_id WordPress user ID.
	 * @param DateTimeImmutable|null $as_of Explicit decision time, mainly for deterministic tests.
	 * @return array|WP_Error Policy result, or an error distinct from no contract.
	 */
	public function resolve_for_user( $user_id, DateTimeImmutable $as_of = null ) {
		if ( ! in_array( $this->environment, array( 'production', 'sandbox' ), true ) ) {
			return new WP_Error( 'entitlement_environment_invalid', '契約環境の設定が不正です。' );
		}
		$snapshot = $this->repository->get_entitlement_snapshot( $user_id, $this->environment );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$as_of = $as_of ?: new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		return egov_law_monitor_resolve_entitlement( $snapshot, $as_of );
	}
}

/** Build the current site service using the same environment setting as usage records. */
function egov_law_monitor_entitlement_service() {
	global $wpdb;
	$environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : 'production';
	return new Egov_Law_Monitor_Entitlement_Service(
		new Egov_Law_Monitor_Subscription_Repository( $wpdb ),
		$environment
	);
}
