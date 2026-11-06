<?php

use PHPUnit\Framework\TestCase;

final class SquareBillingPeriodCandidateTest extends TestCase {
	private $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new Egov_Law_Monitor_Square_Billing_Period_Candidate_Service();
	}

	public function test_initial_monthly_period_returns_candidate_without_claiming_payment_or_approval(): void {
		$input = $this->input( '2026-01-15', 15 );
		$before = $input;
		$result = $this->service->calculate( $input );

		$this->assertSame( 'candidate', $result['status'] );
		$this->assertSame( 'candidate_supported', $result['certainty'] );
		$this->assertSame( '2026-01-15', $result['billing_period']['start_date'] );
		$this->assertSame( '2026-02-14', $result['billing_period']['end_date_inclusive'] );
		$this->assertSame( '2026-02-15', $result['billing_period']['end_exclusive_date'] );
		$this->assertSame( '2026-02-15', $result['billing_period']['next_start_date'] );
		$this->assertSame( 'half_open', $result['billing_period']['interval'] );
		$this->assertFalse( $result['payment_period_confirmed'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertSame( $before, $input );
	}

	public function test_monthly_anchor_clamps_to_each_month_without_drifting(): void {
		$cases = array(
			'29th in leap February' => array( '2024-02-29', 29, '2024-03-29' ),
			'30th in February' => array( '2026-01-30', 30, '2026-02-28' ),
			'31st in nonleap February' => array( '2027-01-31', 31, '2027-02-28' ),
			'31st in leap February' => array( '2028-01-31', 31, '2028-02-29' ),
			'year boundary' => array( '2026-12-31', 31, '2027-01-31' ),
		);
		foreach ( $cases as $label => list( $start, $anchor, $expected_boundary ) ) {
			$result = $this->service->calculate( $this->input( $start, $anchor ) );
			$this->assertSame( 'candidate', $result['status'], $label );
			$this->assertSame( $expected_boundary, $result['billing_period']['end_exclusive_date'], $label );
			$this->assertSame( $expected_boundary, $result['billing_period']['next_start_date'], $label );
		}
	}

	public function test_renewal_starts_at_previous_approved_exclusive_end_and_remains_contiguous(): void {
		$input = $this->input( '2027-01-31', 31 );
		$input['period_type'] = 'renewal';
		$input['previous_approved_period'] = array(
			'approved' => true,
			'start_date' => '2027-01-31',
			'end_exclusive_date' => '2027-02-28',
			'timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 31,
		);
		$result = $this->service->calculate( $input );

		$this->assertSame( 'candidate', $result['status'] );
		$this->assertSame( '2027-02-28', $result['billing_period']['start_date'] );
		$this->assertSame( '2027-03-30', $result['billing_period']['end_date_inclusive'] );
		$this->assertSame( '2027-03-31', $result['billing_period']['end_exclusive_date'] );

		$gap = $input;
		$gap['previous_approved_period']['end_exclusive_date'] = '2027-03-01';
		$this->assertSame( 'review_required', $this->service->calculate( $gap )['status'] );
		$this->assertSame( 'previous_period_not_anchor_aligned', $this->service->calculate( $gap )['reason_code'] );
	}

	public function test_timezone_preserves_square_local_calendar_date_and_returns_offset_boundaries(): void {
		$input = $this->input( '2027-01-31', 31, 'Pacific/Kiritimati' );
		$result = $this->service->calculate( $input );

		$this->assertSame( '2027-01-31', $result['billing_period']['start_date'] );
		$this->assertSame( 'Pacific/Kiritimati', $result['billing_period']['timezone'] );
		$this->assertSame( '2027-01-31T00:00:00+14:00', $result['billing_period']['start_at'] );
		$this->assertSame( '2027-02-28T00:00:00+14:00', $result['billing_period']['end_at_exclusive'] );
	}

	public function test_invalid_dates_timezones_cadence_and_plan_variation_require_review(): void {
		$cases = array();
		$invalid_date = $this->input( '2026-02-30', 30 );
		$cases['invalid date'] = array( $invalid_date, 'subscription_start_date_invalid' );
		$invalid_timezone = $this->input( '2026-01-15', 15, 'Not/A_Timezone' );
		$cases['unknown timezone'] = array( $invalid_timezone, 'subscription_timezone_invalid_or_missing' );
		$unknown_cadence = $this->input( '2026-01-15', 15 );
		$unknown_cadence['plan_variation']['cadence'] = 'UNKNOWN';
		$cases['unsupported cadence'] = array( $unknown_cadence, 'plan_cadence_unsupported' );
		$missing_cadence = $this->input( '2026-01-15', 15 );
		unset( $missing_cadence['plan_variation']['cadence'] );
		$cases['missing cadence'] = array( $missing_cadence, 'monthly_cadence_unconfirmed' );
		$mismatched_plan = $this->input( '2026-01-15', 15 );
		$mismatched_plan['plan_variation']['id'] = 'another-variation';
		$cases['plan variation mismatch'] = array( $mismatched_plan, 'plan_variation_mismatch' );
		foreach ( $cases as $label => list( $input, $reason ) ) {
			$result = $this->service->calculate( $input );
			$this->assertSame( 'review_required', $result['status'], $label );
			$this->assertSame( $reason, $result['reason_code'], $label );
			$this->assertNull( $result['billing_period']['start_date'], $label );
		}
	}

	public function test_phase_schedule_and_anchor_changes_must_be_confirmed_absent(): void {
		foreach ( array( 'phase_change_state', 'schedule_change_state', 'anchor_change_state' ) as $key ) {
			$missing = $this->input( '2026-01-15', 15 );
			unset( $missing[ $key ] );
			$this->assertSame( 'schedule_change_state_unconfirmed', $this->service->calculate( $missing )['reason_code'], $key );

			$unknown = $this->input( '2026-01-15', 15 );
			$unknown[ $key ] = 'unknown';
			$this->assertSame( 'schedule_change_state_unconfirmed', $this->service->calculate( $unknown )['reason_code'], $key );

			$changed = $this->input( '2026-01-15', 15 );
			$changed[ $key ] = 'changed';
			$this->assertSame( 'subscription_schedule_changed', $this->service->calculate( $changed )['reason_code'], $key );
		}
	}

	public function test_renewal_requires_an_approved_contiguous_period_with_same_anchor_and_timezone(): void {
		$input = $this->input( '2026-01-15', 15 );
		$input['period_type'] = 'renewal';
		$input['previous_approved_period'] = array(
			'approved' => false,
			'start_date' => '2026-01-15',
			'end_exclusive_date' => '2026-02-15',
			'timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 15,
		);
		$this->assertSame( 'previous_approved_period_missing', $this->service->calculate( $input )['reason_code'] );

		$input['previous_approved_period']['approved'] = true;
		$input['previous_approved_period']['timezone'] = 'UTC';
		$this->assertSame( 'previous_period_schedule_mismatch', $this->service->calculate( $input )['reason_code'] );
	}

	private function input( $start_date, $anchor, $timezone = 'Asia/Tokyo' ) {
		return array(
			'period_type' => 'initial',
			'subscription' => array(
				'start_date' => $start_date,
				'timezone' => $timezone,
				'monthly_billing_anchor_date' => $anchor,
				'plan_variation_id' => 'plan-variation-standard',
			),
			'plan_variation' => array(
				'id' => 'plan-variation-standard',
				'cadence' => 'MONTHLY',
			),
			'phase_change_state' => 'none',
			'schedule_change_state' => 'none',
			'anchor_change_state' => 'none',
		);
	}
}
