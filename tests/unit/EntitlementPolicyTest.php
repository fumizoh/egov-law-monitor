<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntitlementPolicyTest extends TestCase {
	private const AS_OF = '2026-10-29T12:00:00+09:00';

	#[DataProvider( 'entitlementCases' )]
	public function test_resolves_effective_plan_from_contract_snapshot( $snapshot, $expected_plan, $expected_reason, $expected_recheck ) {
		$result = egov_law_monitor_resolve_entitlement(
			$snapshot,
			new DateTimeImmutable( self::AS_OF )
		);

		self::assertSame( $expected_plan, $result['effective_plan'] );
		self::assertSame( $expected_reason, $result['reason_code'] );
		self::assertSame( $expected_recheck, $result['recheck_required'] );
		self::assertSame( 'free' === $expected_plan ? 5 : 10, $result['watch_limit'] );
		self::assertSame( 'free' === $expected_plan ? 3 : 100, $result['ai_summary_limit'] );
		self::assertSame( 'free' === $expected_plan ? 'lifetime' : 'calendar_month', $result['ai_summary_period'] );
	}

	public static function entitlementCases(): array {
		$active = self::contract();
		$cancel_scheduled = self::contract(
			array( 'status' => 'cancel_scheduled' )
		);
		$pending = self::contract(
			array(
				'status'                 => 'pending',
				'initial_payment_status' => 'pending',
				'paid_through_at'        => null,
			)
		);
		$failure_under_7_days = self::contract(
			array(
				'unresolved_invoice_count' => 1,
				'first_failure_at'         => '2026-10-23T12:00:00+09:00',
				'grace_ends_at'            => '2026-10-30T12:00:00+09:00',
			)
		);
		$failure_at_7_days = self::contract(
			array(
				'unresolved_invoice_count' => 1,
				'first_failure_at'         => '2026-10-22T12:00:00+09:00',
				'grace_ends_at'            => '2026-10-29T12:00:00+09:00',
			)
		);
		$failure_after_7_days = self::contract(
			array(
				'unresolved_invoice_count' => 2,
				'first_failure_at'         => '2026-10-22T11:59:59+09:00',
				'grace_ends_at'            => '2026-10-29T11:59:59+09:00',
			)
		);
		$recovered = self::contract(
			array(
				'first_failure_at' => '2026-10-22T12:00:00+09:00',
				'grace_ends_at'    => '2026-10-29T12:00:00+09:00',
			)
		);

		return array(
			'no contract' => array( self::snapshot(), 'free', 'no_current_contract', false ),
			'legacy standard metadata is ignored' => array( self::snapshot( array(), array( 'legacy_plan' => 'standard' ) ), 'free', 'no_current_contract', false ),
			'past contracts only' => array( self::snapshot( array( self::contract( array( 'is_current' => false, 'status' => 'canceled', 'paid_through_at' => '2026-10-01T00:00:00+09:00' ) ) ) ), 'free', 'no_current_contract', false ),
			'pending contract' => array( self::snapshot( array( $pending ) ), 'free', 'initial_payment_not_confirmed', false ),
			'non standard product contract' => array( self::snapshot( array( self::contract( array( 'plan_code' => 'free' ) ) ) ), 'free', 'non_standard_contract', false ),
			'initial payment not confirmed' => array( self::snapshot( array( self::contract( array( 'initial_payment_status' => 'pending' ) ) ) ), 'free', 'initial_payment_not_confirmed', false ),
			'failed initial payment' => array( self::snapshot( array( self::contract( array( 'initial_payment_status' => 'failed' ) ) ) ), 'free', 'initial_payment_not_confirmed', false ),
			'paid active contract' => array( self::snapshot( array( $active ) ), 'standard', 'active_paid_period', false ),
			'cancel scheduled during paid period' => array( self::snapshot( array( $cancel_scheduled ) ), 'standard', 'cancel_scheduled_paid_period', false ),
			'paid period ends exactly now' => array( self::snapshot( array( self::contract( array( 'paid_through_at' => self::AS_OF ) ) ) ), 'free', 'paid_period_ended', false ),
			'canceled after paid period' => array( self::snapshot( array( self::contract( array( 'status' => 'canceled', 'paid_through_at' => '2026-10-29T11:59:59+09:00' ) ) ) ), 'free', 'contract_not_active', false ),
			'canceled before paid period ends is inconsistent' => array( self::snapshot( array( self::contract( array( 'status' => 'canceled' ) ) ) ), 'free', 'inconsistent_contract_data', true ),
			'payment failure before seven day deadline' => array( self::snapshot( array( $failure_under_7_days ) ), 'standard', 'payment_grace_period', false ),
			'payment failure exactly at seven day deadline' => array( self::snapshot( array( $failure_at_7_days ) ), 'free', 'payment_grace_expired', false ),
			'multiple unresolved invoices past deadline' => array( self::snapshot( array( $failure_after_7_days ) ), 'free', 'payment_grace_expired', false ),
			'duplicate failure does not extend canonical deadline' => array( self::snapshot( array( $failure_at_7_days + array( 'duplicate_failure_events' => 3 ) ) ), 'free', 'payment_grace_expired', false ),
			'extended deadline inconsistent with first failure' => array( self::snapshot( array( self::contract( array( 'unresolved_invoice_count' => 1, 'first_failure_at' => '2026-10-22T12:00:00+09:00', 'grace_ends_at' => '2026-10-31T12:00:00+09:00' ) ) ) ), 'free', 'inconsistent_payment_data', true ),
			'old invoice paid but current invoice remains unresolved' => array( self::snapshot( array( $failure_at_7_days + array( 'paid_old_invoice_count' => 1 ) ) ), 'free', 'payment_grace_expired', false ),
			'all invoices resolved and current contract active' => array( self::snapshot( array( $recovered ) ), 'standard', 'payment_recovered', false ),
			'unknown contract status' => array( self::snapshot( array( self::contract( array( 'status' => 'unknown' ) ) ) ), 'free', 'contract_state_unknown', true ),
			'missing required contract field' => array( self::snapshot( array( self::without_key( $active, 'paid_through_at' ) ) ), 'free', 'incomplete_contract_data', true ),
			'unknown sync status' => array( self::snapshot( array( $active ), array( 'sync_status' => 'timeout' ) ), 'free', 'invalid_input', true ),
			'multiple current contracts' => array( self::snapshot( array( $active, $cancel_scheduled ) ), 'free', 'multiple_current_contracts', true ),
			'sync failure retains verified paid period' => array( self::snapshot( array( $active ), array( 'sync_status' => 'error' ) ), 'standard', 'sync_error_within_verified_period', true ),
			'sync failure does not extend expired paid period' => array( self::snapshot( array( self::contract( array( 'paid_through_at' => '2026-10-29T11:59:59+09:00' ) ) ), array( 'sync_status' => 'error' ) ), 'free', 'sync_unavailable', true ),
			'missing contract collection' => array( array( 'sync_status' => 'ok' ), 'free', 'invalid_input', true ),
			'bad paid period timestamp' => array( self::snapshot( array( self::contract( array( 'paid_through_at' => 'not-a-date' ) ) ) ), 'free', 'incomplete_contract_data', true ),
			'failure timestamp in future' => array( self::snapshot( array( self::contract( array( 'unresolved_invoice_count' => 1, 'first_failure_at' => '2026-10-30T12:00:00+09:00', 'grace_ends_at' => '2026-11-06T12:00:00+09:00' ) ) ) ), 'free', 'inconsistent_payment_data', true ),
			'limits for standard and free are defined' => array( self::snapshot( array( $active ) ), 'standard', 'active_paid_period', false ),
		);
	}

	private static function snapshot( array $contracts = array(), array $overrides = array() ): array {
		return array_merge(
			array(
				'sync_status' => 'ok',
				'contracts'   => $contracts,
			),
			$overrides
		);
	}

	private static function contract( array $overrides = array() ): array {
		return array_merge(
			array(
				'is_current'               => true,
				'plan_code'                => 'standard',
				'status'                   => 'active',
				'initial_payment_status'   => 'succeeded',
				'paid_through_at'          => '2026-11-01T00:00:00+09:00',
				'unresolved_invoice_count' => 0,
				'first_failure_at'         => null,
				'grace_ends_at'            => null,
			),
			$overrides
		);
	}

	private static function without_key( array $value, $key ): array {
		unset( $value[ $key ] );

		return $value;
	}
}
