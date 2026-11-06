<?php

/**
 * Pure calculator for administrator-facing monthly Square billing-period candidates.
 *
 * This class does not call Square, WordPress, or the database. A returned period
 * is only a calendar recommendation; it is never proof of payment or entitlement.
 */
class Egov_Law_Monitor_Square_Billing_Period_Candidate_Service {
	/**
	 * Calculate an initial or renewal period from already retrieved, trusted snapshots.
	 *
	 * Required input keys (the caller must verify these against authoritative data):
	 * - period_type: initial|renewal
	 * - subscription: start_date, timezone, monthly_billing_anchor_date, plan_variation_id
	 * - plan_variation: id, cadence (must be MONTHLY)
	 * - phase_change_state, schedule_change_state, anchor_change_state: none|changed|unknown;
	 *   use none only when the relevant Square data was retrieved and confirms no change
	 * - previous_approved_period for renewal: approved=true, start_date, end_exclusive_date,
	 *   timezone, monthly_billing_anchor_date, taken from a persisted administrator approval
	 *
	 * @param array $input Normalized Square and previously approved period data.
	 * @return array Candidate status, reason, and local calendar period values.
	 */
	public function calculate( array $input ) {
		$result = $this->empty_result();
		if ( ! in_array( $input['period_type'] ?? null, array( 'initial', 'renewal' ), true ) ) {
			return $this->review( $result, 'period_type_invalid' );
		}
		if ( ! isset( $input['subscription'] ) || ! is_array( $input['subscription'] ) || ! isset( $input['plan_variation'] ) || ! is_array( $input['plan_variation'] ) ) {
			return $this->review( $result, 'subscription_or_plan_missing' );
		}

		$subscription = $input['subscription'];
		$plan         = $input['plan_variation'];
		if ( ! isset( $subscription['plan_variation_id'], $plan['id'] ) || ! is_string( $subscription['plan_variation_id'] ) || '' === $subscription['plan_variation_id'] || ! is_string( $plan['id'] ) || '' === $plan['id'] || ! hash_equals( $subscription['plan_variation_id'], $plan['id'] ) ) {
			return $this->review( $result, 'plan_variation_mismatch' );
		}
		if ( ! array_key_exists( 'cadence', $plan ) || null === $plan['cadence'] ) {
			return $this->review( $result, 'monthly_cadence_unconfirmed' );
		}
		if ( ! is_string( $plan['cadence'] ) ) {
			return $this->review( $result, 'plan_cadence_invalid' );
		}
		if ( 'MONTHLY' !== $plan['cadence'] ) {
			return $this->review( $result, 'plan_cadence_unsupported' );
		}

		$timezone_name = $subscription['timezone'] ?? null;
		$timezone      = $this->iana_timezone( $timezone_name );
		if ( null === $timezone ) {
			return $this->review( $result, 'subscription_timezone_invalid_or_missing' );
		}

		$anchor = $subscription['monthly_billing_anchor_date'] ?? null;
		if ( ! is_int( $anchor ) || $anchor < 1 || $anchor > 31 ) {
			return $this->review( $result, 'monthly_anchor_invalid_or_missing' );
		}

		foreach ( array( 'phase_change_state', 'schedule_change_state', 'anchor_change_state' ) as $state_key ) {
			if ( ! isset( $input[ $state_key ] ) || ! in_array( $input[ $state_key ], array( 'none', 'changed', 'unknown' ), true ) ) {
				return $this->review( $result, 'schedule_change_state_unconfirmed' );
			}
			if ( 'changed' === $input[ $state_key ] ) {
				return $this->review( $result, 'subscription_schedule_changed' );
			}
			if ( 'unknown' === $input[ $state_key ] ) {
				return $this->review( $result, 'schedule_change_state_unconfirmed' );
			}
		}

		if ( ! isset( $subscription['start_date'] ) || ! is_string( $subscription['start_date'] ) ) {
			return $this->review( $result, 'subscription_start_date_missing' );
		}
		$subscription_start = $this->local_date( $subscription['start_date'], $timezone );
		if ( null === $subscription_start ) {
			return $this->review( $result, 'subscription_start_date_invalid' );
		}

		if ( 'initial' === $input['period_type'] ) {
			$period_start = $subscription_start;
			if ( ! $this->is_anchor_occurrence( $period_start, $anchor ) ) {
				return $this->review( $result, 'initial_start_not_on_monthly_anchor' );
			}
		} else {
			$previous = $input['previous_approved_period'] ?? null;
			if ( ! is_array( $previous ) || true !== ( $previous['approved'] ?? false ) ) {
				return $this->review( $result, 'previous_approved_period_missing' );
			}
			if ( ( $previous['timezone'] ?? null ) !== $timezone_name || ( $previous['monthly_billing_anchor_date'] ?? null ) !== $anchor ) {
				return $this->review( $result, 'previous_period_schedule_mismatch' );
			}
			$previous_start = $this->local_date( $previous['start_date'] ?? null, $timezone );
			$period_start   = $this->local_date( $previous['end_exclusive_date'] ?? null, $timezone );
			if ( null === $previous_start || null === $period_start || $previous_start < $subscription_start || $period_start <= $previous_start || ! $this->is_anchor_occurrence( $previous_start, $anchor ) || ! $this->is_anchor_occurrence( $period_start, $anchor ) || $this->next_anchor( $previous_start, $anchor ) != $period_start ) {
				return $this->review( $result, 'previous_period_not_anchor_aligned' );
			}
		}

		$period_end_exclusive = $this->next_anchor( $period_start, $anchor );
		if ( null === $period_end_exclusive || $period_end_exclusive <= $period_start ) {
			return $this->review( $result, 'period_calculation_failed' );
		}
		$period_end_inclusive = $period_end_exclusive->modify( '-1 day' );

		$result['status']                    = 'candidate';
		$result['certainty']                 = 'candidate_supported';
		$result['reason_code']               = 'monthly_anchor_candidate';
		$result['period_type']               = $input['period_type'];
		$result['basis']                     = array( 'square_monthly_plan_cadence', 'subscription_timezone', 'monthly_billing_anchor', 'schedule_changes_confirmed_absent' );
		$result['payment_period_confirmed']  = false;
		$result['automatic_approval_candidate'] = false;
		$result['billing_period'] = array(
			'start_date'          => $period_start->format( 'Y-m-d' ),
			'end_date_inclusive'  => $period_end_inclusive->format( 'Y-m-d' ),
			'end_exclusive_date'  => $period_end_exclusive->format( 'Y-m-d' ),
			'next_start_date'     => $period_end_exclusive->format( 'Y-m-d' ),
			'timezone'            => $timezone_name,
			'start_at'            => $period_start->format( 'Y-m-d\TH:i:sP' ),
			'end_at_exclusive'    => $period_end_exclusive->format( 'Y-m-d\TH:i:sP' ),
			'interval'            => 'half_open',
		);
		return $result;
	}

	private function empty_result() {
		return array(
			'status'                       => 'review_required',
			'certainty'                    => 'insufficient',
			'reason_code'                  => 'candidate_not_calculated',
			'period_type'                  => null,
			'basis'                        => array(),
			'payment_period_confirmed'     => false,
			'automatic_approval_candidate' => false,
			'billing_period'               => array(
				'start_date' => null,
				'end_date_inclusive' => null,
				'end_exclusive_date' => null,
				'next_start_date' => null,
				'timezone' => null,
				'start_at' => null,
				'end_at_exclusive' => null,
				'interval' => 'half_open',
			),
		);
	}

	private function review( array $result, $reason_code ) {
		$result['reason_code'] = $reason_code;
		return $result;
	}

	private function iana_timezone( $timezone_name ) {
		if ( ! is_string( $timezone_name ) || '' === $timezone_name ) {
			return null;
		}
		$identifiers = DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC );
		if ( 'UTC' !== $timezone_name && ! in_array( $timezone_name, $identifiers, true ) ) {
			return null;
		}
		try {
			return new DateTimeZone( $timezone_name );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private function local_date( $date, DateTimeZone $timezone ) {
		if ( ! is_string( $date ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $date ) ) {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $parsed->format( 'Y-m-d' ) !== $date || '00:00:00' !== $parsed->format( 'H:i:s' ) ) {
			return null;
		}
		return $parsed;
	}

	private function is_anchor_occurrence( DateTimeImmutable $date, $anchor ) {
		return (int) $date->format( 'j' ) === min( $anchor, (int) $date->format( 't' ) );
	}

	private function next_anchor( DateTimeImmutable $date, $anchor ) {
		$first_of_month = $date->modify( 'first day of next month' );
		$day            = min( $anchor, (int) $first_of_month->format( 't' ) );
		$anchor_date    = $first_of_month->setDate( (int) $first_of_month->format( 'Y' ), (int) $first_of_month->format( 'n' ), $day );
		$boundary = $anchor_date->setTime( 0, 0, 0 );
		if ( $boundary->format( 'Y-m-d' ) !== $anchor_date->format( 'Y-m-d' ) || '00:00:00' !== $boundary->format( 'H:i:s' ) ) {
			return null;
		}
		return $boundary;
	}
}
