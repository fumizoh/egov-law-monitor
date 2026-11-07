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
 *             'square_status' => Square's raw status string,
 *             'initial_payment_status' => 'succeeded'|'pending'|'failed'|'unknown',
 *             'approval_status' => 'valid'|'missing'|'invalid',
 *             'approved_period_start_at' => RFC3339 string|null,
 *             'approved_period_end_at' => RFC3339 string|null,
 *             'current_period_start_at' => UTC RFC3339 string|null,
 *             'paid_through_at' => RFC3339 string|null,
 *             'unresolved_invoice_count' => non-negative int,
 *             'review_pending_invoice_count' => non-negative int,
 *             'confirmed_failure_invoice_count' => non-negative int,
 *             'first_failure_at' => RFC3339 string|null,
 *             'grace_ends_at' => RFC3339 string|null,
 *             'review_grace_ends_at' => RFC3339 string|null,
 *             'admin_control_status' => 'ok'|'invalid',
 *             'admin_suspended' => bool,
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
	if ( $sync_failed ) {
		return egov_law_monitor_entitlement_result( $free, 'sync_unavailable', true );
	}
	$current_contracts = array();

	foreach ( $snapshot['contracts'] as $contract ) {
		if ( ! is_array( $contract ) || ! array_key_exists( 'is_current', $contract ) || ! is_bool( $contract['is_current'] ) ) {
			return egov_law_monitor_entitlement_result(
				$free,
				'inconsistent_contract_data',
				true
			);
		}

		$is_terminated = is_array( $contract ) && (
			( isset( $contract['status'] ) && in_array( $contract['status'], array( 'canceled', 'cancel_scheduled' ), true ) )
			|| ( isset( $contract['square_status'] ) && is_string( $contract['square_status'] ) && in_array( strtoupper( $contract['square_status'] ), array( 'CANCELED', 'DEACTIVATED', 'COMPLETED' ), true ) )
		);
		$terminated_paid_period_is_current = false;
		if ( ! $contract['is_current'] && $is_terminated && isset( $contract['approval_status'] ) && 'missing' !== $contract['approval_status'] ) {
			$end = egov_law_monitor_parse_entitlement_time( $contract['paid_through_at'] ?? null );
			$terminated_paid_period_is_current = null === $end || $end > $as_of;
		}
		if ( $contract['is_current'] || $terminated_paid_period_is_current ) {
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
		'square_status',
		'initial_payment_status',
		'approval_status',
		'approved_period_start_at',
		'approved_period_end_at',
		'current_period_start_at',
		'paid_through_at',
		'unresolved_invoice_count',
		'review_pending_invoice_count',
		'confirmed_failure_invoice_count',
		'first_failure_at',
		'grace_ends_at',
		'review_grace_ends_at',
		'admin_control_status',
		'admin_suspended',
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

	$known_square_statuses = array( 'ACTIVE', 'PENDING', 'CANCELED', 'DEACTIVATED', 'PAUSED', 'COMPLETED', 'UNKNOWN' );
	if (
		! in_array( $contract['status'], $known_statuses, true )
		|| ! is_string( $contract['square_status'] )
		|| ! in_array( strtoupper( $contract['square_status'] ), $known_square_statuses, true )
		|| ! in_array( $contract['initial_payment_status'], $known_initial_payment_statuses, true )
		|| ! is_int( $contract['unresolved_invoice_count'] )
		|| $contract['unresolved_invoice_count'] < 0
		|| ! is_int( $contract['review_pending_invoice_count'] )
		|| $contract['review_pending_invoice_count'] < 0
		|| ! is_int( $contract['confirmed_failure_invoice_count'] )
		|| $contract['confirmed_failure_invoice_count'] < 0
		|| ! in_array( $contract['approval_status'], array( 'valid', 'missing', 'invalid' ), true )
		|| ! in_array( $contract['admin_control_status'], array( 'ok', 'invalid' ), true )
		|| ! is_bool( $contract['admin_suspended'] )
	) {
		return egov_law_monitor_entitlement_result(
			$free,
			'inconsistent_contract_data',
			true
		);
	}

	if ( 'unknown' === $contract['status'] || 'unknown' === strtolower( $contract['square_status'] ) || 'unknown' === $contract['initial_payment_status'] ) {
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

	if ( 'missing' === $contract['approval_status'] ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'administrator_approval_required',
			true
		);
	}
	if ( 'valid' !== $contract['approval_status'] || 'invalid' === $contract['admin_control_status'] ) {
		return egov_law_monitor_entitlement_result( $free, 'administrator_approval_inconsistent', true );
	}
	if ( $contract['admin_suspended'] ) {
		return egov_law_monitor_entitlement_result( $free, 'administrator_suspended', false );
	}

	if ( 'paused' === $contract['status'] ) {
		return egov_law_monitor_entitlement_result( $free, 'administrator_suspended', false );
	}

	if ( ! in_array( $contract['status'], array( 'active', 'cancel_scheduled', 'canceled' ), true ) ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'inconsistent_contract_data',
			true
		);
	}

	$approved_start = egov_law_monitor_parse_entitlement_time( $contract['approved_period_start_at'] );
	$approved_end = egov_law_monitor_parse_entitlement_time( $contract['approved_period_end_at'] );
	$current_start = egov_law_monitor_parse_entitlement_time( $contract['current_period_start_at'] );
	$paid_through = egov_law_monitor_parse_entitlement_time( $contract['paid_through_at'] );

	if ( null === $current_start || null === $paid_through ) {
		return egov_law_monitor_entitlement_result( $free, 'incomplete_contract_data', true );
	}
	if ( null === $approved_start || null === $approved_end ) {
		return egov_law_monitor_entitlement_result(
			$free,
			'administrator_approval_inconsistent',
			true
		);
	}
	if ( $approved_start >= $approved_end || $approved_start != $current_start || $approved_end != $paid_through ) {
		return egov_law_monitor_entitlement_result( $free, 'administrator_approval_period_mismatch', true );
	}
	if ( null !== $contract['first_failure_at'] || null !== $contract['grace_ends_at'] ) {
		$stored_failure = egov_law_monitor_parse_entitlement_time( $contract['first_failure_at'] );
		$stored_grace_end = egov_law_monitor_parse_entitlement_time( $contract['grace_ends_at'] );
		if ( null === $stored_failure || null === $stored_grace_end || $stored_failure > $as_of || $stored_grace_end->getTimestamp() !== $stored_failure->getTimestamp() + ( 7 * 24 * 60 * 60 ) || $stored_grace_end->format( 'u' ) !== $stored_failure->format( 'u' ) ) {
			return egov_law_monitor_entitlement_result( $free, 'inconsistent_payment_data', true );
		}
	}

	$canceled = 'canceled' === $contract['status'] || in_array( strtoupper( $contract['square_status'] ), array( 'CANCELED', 'DEACTIVATED', 'COMPLETED' ), true );
	if ( $canceled ) {
		if ( $paid_through > $as_of ) {
			return egov_law_monitor_entitlement_result( egov_law_monitor_standard_entitlement(), 'canceled_approved_period', $sync_failed );
		}
		return egov_law_monitor_entitlement_result( $free, 'canceled_approved_period_ended', $sync_failed );
	}

	if ( 'PAUSED' === strtoupper( $contract['square_status'] ) ) {
		if ( $paid_through > $as_of ) {
			return egov_law_monitor_entitlement_result( egov_law_monitor_standard_entitlement(), 'square_paused_approved_period', $sync_failed );
		}
		return egov_law_monitor_entitlement_result( $free, 'square_subscription_paused', $sync_failed );
	}

	if ( $paid_through <= $as_of ) {
		if ( $contract['unresolved_invoice_count'] > 0 && $contract['confirmed_failure_invoice_count'] > 0 && null === $contract['first_failure_at'] ) {
			return egov_law_monitor_entitlement_result( $free, 'payment_failure_time_unavailable', true );
		}
		if ( $contract['unresolved_invoice_count'] > 0 && null !== $contract['first_failure_at'] && 0 === $contract['confirmed_failure_invoice_count'] ) {
			return egov_law_monitor_entitlement_result( $free, 'payment_failure_unverified', true );
		}
		if ( $contract['unresolved_invoice_count'] > 0 && $contract['confirmed_failure_invoice_count'] > 0 ) {
			$first_failure = egov_law_monitor_parse_entitlement_time( $contract['first_failure_at'] );
			$grace_ends = egov_law_monitor_parse_entitlement_time( $contract['grace_ends_at'] );
			if ( null === $first_failure || null === $grace_ends ) {
				return egov_law_monitor_entitlement_result( $free, 'incomplete_payment_data', true );
			}
			$expected_grace_ends_timestamp = $first_failure->getTimestamp() + ( 7 * 24 * 60 * 60 );
			if ( $first_failure > $as_of || $grace_ends->getTimestamp() !== $expected_grace_ends_timestamp || $grace_ends->format( 'u' ) !== $first_failure->format( 'u' ) ) {
				return egov_law_monitor_entitlement_result( $free, 'inconsistent_payment_data', true );
			}
			if ( $as_of < $grace_ends ) {
				return egov_law_monitor_entitlement_result( egov_law_monitor_standard_entitlement(), $sync_failed ? 'sync_error_within_payment_grace' : 'payment_grace_period', $sync_failed );
			}
			return egov_law_monitor_entitlement_result( $free, $sync_failed ? 'sync_unavailable' : 'payment_grace_expired', $sync_failed );
		}

		$review_grace_ends = egov_law_monitor_parse_entitlement_time( $contract['review_grace_ends_at'] );
		$review_grace_max = $paid_through->modify( '+7 days' );
		$can_review_grace = (
			'active' === $contract['status']
			&& 'ACTIVE' === strtoupper( $contract['square_status'] )
			&& 0 === $contract['confirmed_failure_invoice_count']
			&& null === $contract['first_failure_at']
			&& $contract['unresolved_invoice_count'] > 0
			&& $contract['review_pending_invoice_count'] > 0
		);
		if ( $can_review_grace && null !== $review_grace_ends && $review_grace_ends > $paid_through && $review_grace_ends <= $review_grace_max && $as_of < $review_grace_ends ) {
			return egov_law_monitor_entitlement_result( egov_law_monitor_standard_entitlement(), $sync_failed ? 'sync_error_within_review_grace' : 'review_confirmation_grace', $sync_failed );
		}
		if ( $can_review_grace && null === $review_grace_ends ) {
			return egov_law_monitor_entitlement_result( $free, 'review_confirmation_grace_unset', true );
		}
		if ( $can_review_grace && ( $review_grace_ends <= $paid_through || $review_grace_ends > $review_grace_max ) ) {
			return egov_law_monitor_entitlement_result( $free, 'review_confirmation_grace_invalid', true );
		}
		if ( ! $can_review_grace && null !== $contract['review_grace_ends_at'] ) {
			return egov_law_monitor_entitlement_result( $free, 'review_grace_without_eligible_invoice', true );
		}
		return egov_law_monitor_entitlement_result(
			$free,
			$sync_failed ? 'sync_unavailable' : ( $can_review_grace ? 'review_confirmation_grace_expired' : 'paid_period_ended' ),
			$sync_failed || ( $contract['unresolved_invoice_count'] > 0 && 0 === $contract['review_pending_invoice_count'] )
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
