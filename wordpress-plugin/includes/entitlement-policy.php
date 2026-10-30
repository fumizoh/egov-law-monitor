<?php
/**
 * Pure policy for resolving the effective e-Gov Law Monitor plan.
 *
 * This file deliberately does not load WordPress, access the database, or
 * contact Square. Callers pass a normalized, previously retrieved snapshot.
 */

/**
 * Resolve a user's effective plan from normalized contract data.
 *
 * Input shape:
 * [
 *     'sync_status' => 'ok'|'error',
 *     'contracts' => [
 *         [
 *             'is_current' => bool,
 *             'plan_code' => 'standard'|other known local plan code,
 *             'status' => 'pending'|'active'|'cancel_scheduled'|'canceled'|'paused'|'unknown',
 *             'initial_payment_status' => 'succeeded'|'pending'|'failed'|'unknown',
 *             'paid_through_at' => RFC3339 string|null,
 *             'unresolved_invoice_count' => non-negative int,
 *             'first_failure_at' => RFC3339 string|null,
 *             'grace_ends_at' => RFC3339 string|null,
 *         ],
 *     ],
 * ]
 *
 * `first_failure_at` must be the earliest still-relevant failure recorded for
 * the current unpaid period. `grace_ends_at` must equal that time plus seven
 * days; repeated events must not move either timestamp forward.
 *
 * @param array             $snapshot Normalized contract and invoice snapshot.
 * @param DateTimeImmutable $as_of    Explicit decision time, including timezone.
 * @return array{effective_plan:string,watch_limit:int,ai_summary_limit:int,ai_summary_period:string,reason_code:string,recheck_required:bool}
 */
function egov_law_monitor_resolve_entitlement( $snapshot, $as_of ) {
	$free = array(
		'effective_plan'     => 'free',
		'watch_limit'        => 5,
		'ai_summary_limit'   => 3,
		'ai_summary_period'  => 'lifetime',
	);

	if ( ! is_array( $snapshot ) || ! $as_of instanceof DateTimeImmutable ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'invalid_input',
			true
		);
	}

	if (
		! isset( $snapshot['sync_status'] )
		|| ! in_array( $snapshot['sync_status'], array( 'ok', 'error' ), true )
		|| ! isset( $snapshot['contracts'] )
		|| ! is_array( $snapshot['contracts'] )
	) {
		return egov_law_monitor_entitlement_result(
			$free,
			'invalid_input',
			true
		);
	}

	$sync_failed = 'error' === $snapshot['sync_status'];
	$current_contracts = array();

	foreach ( $snapshot['contracts'] as $contract ) {
		if ( ! is_array( $contract ) || ! array_key_exists( 'is_current', $contract ) || ! is_bool( $contract['is_current'] ) ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'inconsistent_contract_data',
				true
			);
		}

		if ( $contract['is_current'] ) {
			$current_contracts[] = $contract;
		}
	}

	if ( count( $current_contracts ) > 1 ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'multiple_current_contracts',
			true
		);
	}

	if ( empty( $current_contracts ) ) {
		return egov_law_monitor_entitlement_result(
			$free,
			$sync_failed ? 'sync_unavailable' : 'no_current_contract',
			$sync_failed
		);
	}

	$contract = $current_contracts[0];
	$required = array(
		'plan_code',
		'status',
		'initial_payment_status',
		'paid_through_at',
		'unresolved_invoice_count',
		'first_failure_at',
		'grace_ends_at',
	);

	foreach ( $required as $key ) {
		if ( ! array_key_exists( $key, $contract ) ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'incomplete_contract_data',
				true
			);
		}
	}

	if ( ! is_string( $contract['plan_code'] ) || '' === $contract['plan_code'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'incomplete_contract_data',
			true
		);
	}

	if ( 'unknown' === $contract['plan_code'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'contract_state_unknown',
			true
		);
	}

	if ( 'standard' !== $contract['plan_code'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'non_standard_contract',
			false
		);
	}

	$known_statuses = array(
		'pending',
		'active',
		'cancel_scheduled',
		'canceled',
		'paused',
		'unknown',
	);
	$known_initial_payment_statuses = array(
		'succeeded',
		'pending',
		'failed',
		'unknown',
	);

	if (
		! in_array( $contract['status'], $known_statuses, true )
		|| ! in_array( $contract['initial_payment_status'], $known_initial_payment_statuses, true )
		|| ! is_int( $contract['unresolved_invoice_count'] )
		|| $contract['unresolved_invoice_count'] < 0
	) {
		return egov_law_monitor_entitlement_result(
			$free,
			'inconsistent_contract_data',
			true
		);
	}

	if ( 'unknown' === $contract['status'] || 'unknown' === $contract['initial_payment_status'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'contract_state_unknown',
			true
		);
	}

	if ( 'pending' === $contract['status'] || 'pending' === $contract['initial_payment_status'] || 'failed' === $contract['initial_payment_status'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			$sync_failed ? 'sync_unavailable' : 'initial_payment_not_confirmed',
			$sync_failed
		);
	}

	if ( 'canceled' === $contract['status'] || 'paused' === $contract['status'] ) {
		$inactive_paid_through = egov_law_monitor_parse_entitlement_time(
			$contract['paid_through_at']
		);

		if ( null === $inactive_paid_through ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'incomplete_contract_data',
				true
			);
		}

		if ( $inactive_paid_through > $as_of ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'inconsistent_contract_data',
				true
			);
		}

		return egov_law_monitor_entitlement_result(
			$free,
			'contract_not_active',
			$sync_failed
		);
	}

	if ( ! in_array( $contract['status'], array( 'active', 'cancel_scheduled' ), true ) ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'inconsistent_contract_data',
			true
		);
	}

	$paid_through = egov_law_monitor_parse_entitlement_time(
		$contract['paid_through_at']
	);

	if ( null === $paid_through ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'incomplete_contract_data',
			true
		);
	}

	if ( $contract['unresolved_invoice_count'] > 0 ) {
		$first_failure = egov_law_monitor_parse_entitlement_time(
			$contract['first_failure_at']
		);
		$grace_ends = egov_law_monitor_parse_entitlement_time(
			$contract['grace_ends_at']
		);

		if ( null === $first_failure || null === $grace_ends ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'incomplete_payment_data',
				true
			);
		}

		$expected_grace_ends_timestamp = $first_failure->getTimestamp() + ( 7 * 24 * 60 * 60 );

		if (
			$first_failure > $as_of
			|| $grace_ends->getTimestamp() !== $expected_grace_ends_timestamp
			|| $grace_ends->format( 'u' ) !== $first_failure->format( 'u' )
		) {
			return egov_law_monitor_entitlement_result(
				$free,
				'inconsistent_payment_data',
				true
			);
		}

		if ( $as_of < $grace_ends ) {
			return egov_law_monitor_entitlement_result(
				egov_law_monitor_standard_entitlement(),
				$sync_failed ? 'sync_error_within_payment_grace' : 'payment_grace_period',
				$sync_failed
			);
		}

		return egov_law_monitor_entitlement_result(
			$free,
			$sync_failed ? 'sync_unavailable' : 'payment_grace_expired',
			$sync_failed
		);
	}

	if ( $paid_through <= $as_of ) {
		return egov_law_monitor_entitlement_result(
			$free,
			$sync_failed ? 'sync_unavailable' : 'paid_period_ended',
			$sync_failed
		);
	}

	$reason = 'cancel_scheduled' === $contract['status']
		? 'cancel_scheduled_paid_period'
		: ( null !== $contract['first_failure_at'] ? 'payment_recovered' : 'active_paid_period' );

	if ( $sync_failed ) {
		$reason = 'sync_error_within_verified_period';
	}

	return egov_law_monitor_entitlement_result(
		egov_law_monitor_standard_entitlement(),
		$reason,
		$sync_failed
	);
}

/**
 * Return Standard plan limits.
 *
 * @return array{effective_plan:string,watch_limit:int,ai_summary_limit:int,ai_summary_period:string}
 */
function egov_law_monitor_standard_entitlement() {
	return array(
		'effective_plan'    => 'standard',
		'watch_limit'       => 10,
		'ai_summary_limit'  => 100,
		'ai_summary_period' => 'calendar_month',
	);
}

/**
 * Add stable machine-readable resolution metadata.
 *
 * @param array  $entitlement Plan and limits.
 * @param string $reason_code Stable reason code.
 * @param bool   $recheck_required Whether the caller should reconcile state.
 * @return array
 */
function egov_law_monitor_entitlement_result( $entitlement, $reason_code, $recheck_required ) {
	return array_merge(
		$entitlement,
		array(
			'reason_code'      => $reason_code,
			'recheck_required' => (bool) $recheck_required,
		)
	);
}

/**
 * Parse a strict RFC3339 timestamp into an immutable value.
 *
 * @param mixed $value Timestamp or null.
 * @return DateTimeImmutable|null
 */
function egov_law_monitor_parse_entitlement_time( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) {
		return null;
	}

	try {
		$parsed = new DateTimeImmutable( $value );
	} catch ( Exception $exception ) {
		return null;
	}

	$errors = DateTimeImmutable::getLastErrors();

	if ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
		return null;
	}

	return $parsed;
}
