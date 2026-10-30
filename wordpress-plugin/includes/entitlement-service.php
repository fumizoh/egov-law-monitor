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
				'initial_payment_status'   => (string) $row['initial_payment_status'],
				'paid_through_at'          => $this->to_rfc3339_utc( $row['current_period_end'] ),
				'unresolved_invoice_count' => 0,
				'first_failure_at'         => $this->to_rfc3339_utc( $row['first_payment_failed_at'] ),
				'grace_ends_at'            => $this->to_rfc3339_utc( $row['grace_ends_at'] ),
			);
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
			if ( ! in_array( strtolower( (string) $invoice['payment_status'] ), $settled_statuses, true ) ) {
				++$contracts_by_id[ $subscription_id ]['unresolved_invoice_count'];
			}
		}

		return array(
			'sync_status' => $this->get_snapshot_sync_status( $rows ),
			'contracts'   => array_values( $contracts_by_id ),
		);
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
