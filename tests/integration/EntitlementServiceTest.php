<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/ai-summary-page.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/ai-summary-management.php';

final class EntitlementServiceTest extends TestCase {
	private $user_id;
	private $subscription_ids = array();

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$suffix = wp_generate_password( 12, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'entitlement-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'entitlement-' . $suffix . '@example.org',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->user_id = (int) $user_id;
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$table = $wpdb->prefix . 'egov_law_subscriptions';
			foreach ( array( $wpdb->prefix . 'egov_law_ai_summary_usage', $wpdb->prefix . 'egov_law_ai_summary_history' ) as $user_data_table ) {
				$existing_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $user_data_table ) ) );
				if ( $existing_table === $user_data_table && $this->user_id ) {
					$wpdb->delete( $user_data_table, array( 'user_id' => $this->user_id ), array( '%d' ) );
				}
			}
			foreach ( $this->subscription_ids as $subscription_id ) {
				$wpdb->delete( $wpdb->prefix . 'egov_law_square_admin_reviews', array( 'contract_id' => $subscription_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
				$wpdb->delete( $table, array( 'id' => $subscription_id ), array( '%d' ) );
			}
			if ( $this->user_id ) {
				$removed_plugin_delete_hook = function_exists( 'egov_law_monitor_delete_user_data' )
					&& remove_action( 'delete_user', 'egov_law_monitor_delete_user_data' );
				wp_delete_user( $this->user_id );
				if ( $removed_plugin_delete_hook ) {
					add_action( 'delete_user', 'egov_law_monitor_delete_user_data' );
				}
			}
		}
		parent::tearDown();
	}

	public function test_no_contract_is_free_even_when_legacy_meta_says_standard(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'no_current_contract', $result['reason_code'] );
	}

	public function test_active_and_cancel_scheduled_contracts_use_policy(): void {
		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$active = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'standard', $active['effective_plan'] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'internal_status' => 'cancel_scheduled' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		$cancel_scheduled = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'standard', $cancel_scheduled['effective_plan'] );
		$this->assertSame( 'cancel_scheduled_paid_period', $cancel_scheduled['reason_code'] );
	}

	public function test_contract_without_administrator_approval_is_free_even_with_successful_payment_fields(): void {
		$this->add_subscription( 'production', array( 'with_approval' => false, 'current_period_end' => '2026-11-01 00:00:00' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'administrator_approval_required', $result['reason_code'] );
	}

	public function test_future_approved_period_does_not_grant_standard_before_its_start_boundary(): void {
		$this->add_subscription( 'production', array(
			'current_period_start' => '2026-12-01 00:00:00',
			'current_period_end' => '2027-01-01 00:00:00',
			'approval_period_start' => '2026-12-01 00:00:00',
			'approval_period_end' => '2027-01-01 00:00:00',
		) );
		$before = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-11-30T23:59:59Z' ) );
		$at_boundary = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-12-01T00:00:00Z' ) );
		$this->assertSame( 'free', $before['effective_plan'] );
		$this->assertSame( 'approved_period_not_started', $before['reason_code'] );
		$this->assertSame( 'standard', $at_boundary['effective_plan'] );
	}

	public function test_approval_period_must_match_contract_utc_boundaries(): void {
		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'current_period_end' => '2026-12-01 00:00:00' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'administrator_approval_period_mismatch', $result['reason_code'] );
	}

	public function test_latest_valid_correction_replaces_approved_period_and_identity_mismatch_fails_closed(): void {
		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		global $wpdb;
		$prior_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}egov_law_square_admin_reviews WHERE contract_id = %d AND action_type = %s ORDER BY id DESC LIMIT 1", $id, 'approve' ) );
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'current_period_start' => '2026-10-15 00:00:00', 'current_period_end' => '2026-12-01 00:00:00' ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		$this->append_admin_review( $id, 'correct', '2026-10-16 00:00:00', array(
			'square_payment_id' => 'entitlement-corrected-payment-' . $id,
			'approved_period_start' => '2026-10-15 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'UTC',
			'monthly_billing_anchor_date' => 1,
			'supersedes_review_id' => $prior_id,
		) );
		$corrected = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-11-15T00:00:00Z' ) );
		$this->assertSame( 'standard', $corrected['effective_plan'] );

		$wpdb->update( $wpdb->prefix . 'egov_law_square_admin_reviews', array( 'merchant_id' => 'other-merchant' ), array( 'contract_id' => $id, 'action_type' => 'correct' ), array( '%s' ), array( '%d', '%s' ) );
		$mismatched = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-11-15T00:00:00Z' ) );
		$this->assertSame( 'free', $mismatched['effective_plan'] );
		$this->assertSame( 'administrator_approval_inconsistent', $mismatched['reason_code'] );
	}

	public function test_timezone_aware_review_period_matches_contract_utc_period(): void {
		$this->add_subscription( 'production', array(
			'current_period_start' => '2026-10-31 15:00:00',
			'current_period_end' => '2026-11-30 15:00:00',
			'approval_period_start' => '2026-11-01 00:00:00',
			'approval_period_end' => '2026-12-01 00:00:00',
			'approval_timezone' => 'Asia/Tokyo',
		) );
		$before_boundary = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-11-30T14:59:59Z' ) );
		$at_boundary = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-11-30T15:00:00Z' ) );
		$this->assertSame( 'standard', $before_boundary['effective_plan'] );
		$this->assertSame( 'free', $at_boundary['effective_plan'] );
	}

	public function test_canceled_contract_keeps_approved_period_without_current_slot_and_never_gets_review_grace(): void {
		$id = $this->add_subscription( 'production', array(
			'internal_status' => 'canceled', 'square_status' => 'CANCELED', 'current_slot' => null,
			'current_period_end' => '2026-11-01 00:00:00',
		) );
		$this->assertSame( 'standard', $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) )['effective_plan'] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'current_period_start' => '2026-09-01 00:00:00', 'current_period_end' => '2026-10-01 00:00:00', 'review_grace_ends_at' => '2026-10-08 00:00:00' ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'egov_law_square_admin_reviews', array( 'approved_period_start' => '2026-09-01 00:00:00', 'approved_period_end' => '2026-10-01 00:00:00' ), array( 'contract_id' => $id, 'action_type' => 'approve' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$expired = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $expired['effective_plan'] );
		$this->assertSame( 'no_current_contract', $expired['reason_code'] );
	}

	public function test_admin_suspend_is_immediate_and_resume_requires_an_existing_approved_period(): void {
		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$this->append_admin_review( $id, 'suspend', '2026-10-10 00:00:00' );
		$suspended = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $suspended['effective_plan'] );
		$this->assertSame( 'administrator_suspended', $suspended['reason_code'] );
		$this->append_admin_review( $id, 'resume', '2026-10-11 00:00:00' );
		$resumed = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'standard', $resumed['effective_plan'] );
	}

	public function test_review_delay_grace_requires_a_due_unpaid_invoice_and_expires_at_boundary(): void {
		$id = $this->add_subscription( 'production', array(
			'current_period_start' => '2026-09-22 00:00:00',
			'current_period_end' => '2026-10-22 00:00:00',
			'review_grace_ends_at' => '2026-10-29 00:00:00',
		) );
		$this->add_invoice( $id, 'production', array( 'payment_status' => 'unpaid', 'due_at' => '2026-10-22 00:00:00' ) );
		$inside = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-28T23:59:59Z' ) );
		$at_boundary = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T00:00:00Z' ) );
		$this->assertSame( 'standard', $inside['effective_plan'] );
		$this->assertSame( 'review_confirmation_grace', $inside['reason_code'] );
		$this->assertSame( 'free', $at_boundary['effective_plan'] );
		$this->assertSame( 'review_confirmation_grace_expired', $at_boundary['reason_code'] );
	}

	public function test_review_delay_grace_does_not_apply_to_unknown_or_failed_invoice_data(): void {
		$unknown_id = $this->add_subscription( 'production', array(
			'current_period_start' => '2026-09-22 00:00:00', 'current_period_end' => '2026-10-22 00:00:00', 'review_grace_ends_at' => '2026-10-29 00:00:00',
		) );
		$this->add_invoice( $unknown_id, 'production', array( 'payment_status' => 'unknown', 'due_at' => '2026-10-22 00:00:00' ) );
		$unknown = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-25T00:00:00Z' ) );
		global $wpdb;
		$this->assertSame( 'free', $unknown['effective_plan'] );
		$this->assertSame( 'review_grace_without_eligible_invoice', $unknown['reason_code'] );

		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'current_slot' => null ), array( 'id' => $unknown_id ), array( '%d' ), array( '%d' ) );
		$failed_id = $this->add_subscription( 'production', array(
			'current_period_start' => '2026-09-22 00:00:00', 'current_period_end' => '2026-10-22 00:00:00',
			'first_payment_failed_at' => '2026-10-20 00:00:00', 'grace_ends_at' => '2026-10-27 00:00:00', 'review_grace_ends_at' => '2026-10-29 00:00:00',
		) );
		$this->add_invoice( $failed_id, 'production', array( 'payment_status' => 'failed', 'due_at' => '2026-10-22 00:00:00' ) );
		$after_failure_grace = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-28T00:00:00Z' ) );
		$this->assertSame( 'free', $after_failure_grace['effective_plan'] );
		$this->assertSame( 'payment_grace_expired', $after_failure_grace['reason_code'] );
	}

	public function test_failed_invoice_without_verified_failure_timestamp_never_gets_either_grace(): void {
		$id = $this->add_subscription( 'production', array(
			'current_period_start' => '2026-09-22 00:00:00', 'current_period_end' => '2026-10-22 00:00:00', 'review_grace_ends_at' => '2026-10-29 00:00:00',
		) );
		$this->add_invoice( $id, 'production', array( 'payment_status' => 'failed', 'due_at' => '2026-10-22 00:00:00' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-25T00:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'payment_failure_time_unavailable', $result['reason_code'] );
	}

	public function test_square_paused_is_not_treated_as_administrator_stop_during_approved_period(): void {
		$id = $this->add_subscription( 'production', array( 'square_status' => 'PAUSED', 'current_period_end' => '2026-11-01 00:00:00' ) );
		$within_period = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'standard', $within_period['effective_plan'] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'current_period_start' => '2026-09-01 00:00:00', 'current_period_end' => '2026-10-01 00:00:00' ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		$wpdb->update( $wpdb->prefix . 'egov_law_square_admin_reviews', array( 'approved_period_start' => '2026-09-01 00:00:00', 'approved_period_end' => '2026-10-01 00:00:00' ), array( 'contract_id' => $id, 'action_type' => 'approve' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		$after_period = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $after_period['effective_plan'] );
		$this->assertSame( 'square_subscription_paused', $after_period['reason_code'] );
	}

	public function test_failure_grace_does_not_truncate_an_unexpired_approved_period(): void {
		$id = $this->add_subscription( 'production', array(
			'current_period_end' => '2026-11-01 00:00:00',
			'first_payment_failed_at' => '2026-10-20 00:00:00',
			'grace_ends_at' => '2026-10-27 00:00:00',
		) );
		$this->add_invoice( $id, 'production', array( 'payment_status' => 'failed' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'standard', $result['effective_plan'] );
		$this->assertSame( 'payment_recovered', $result['reason_code'] );
	}

	public function test_current_contract_sync_state_is_reflected_in_entitlement_result(): void {
		global $wpdb;
		$id    = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$cases = array(
			'ok'      => array( 'standard', 'active_paid_period', false ),
			'error'   => array( 'free', 'sync_unavailable', true ),
			'unknown' => array( 'free', 'invalid_input', true ),
			''        => array( 'free', 'invalid_input', true ),
			'invalid' => array( 'free', 'invalid_input', true ),
		);

		foreach ( $cases as $sync_state => $expected ) {
			$this->assertNotFalse( $wpdb->update( $table, array( 'sync_state' => $sync_state ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ) );
			$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
			$this->assertIsArray( $result );
			$this->assertSame( $expected[0], $result['effective_plan'], 'sync_state=' . var_export( $sync_state, true ) . ' plan' );
			$this->assertSame( $expected[1], $result['reason_code'], 'sync_state=' . var_export( $sync_state, true ) . ' reason' );
			$this->assertSame( $expected[2], $result['recheck_required'], 'sync_state=' . var_export( $sync_state, true ) . ' recheck' );
		}

		$wpdb->update(
			$table,
			array(
				'sync_state'              => 'error',
				'first_payment_failed_at' => '2026-10-28 12:00:00',
				'grace_ends_at'           => '2026-11-04 12:00:00',
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		$this->add_invoice( $id, 'production', array( 'payment_status' => 'failed' ) );
		$grace_result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $grace_result['effective_plan'] );
		$this->assertSame( 'sync_unavailable', $grace_result['reason_code'] );
		$this->assertTrue( $grace_result['recheck_required'] );
	}

	public function test_payment_grace_boundary_is_passed_to_the_pure_policy(): void {
		$this->add_subscription(
			'production',
			array(
				'current_period_end' => '2026-10-22 00:00:00',
				'first_payment_failed_at' => '2026-10-22 00:00:00',
				'grace_ends_at'           => '2026-10-29 00:00:00',
			)
		);
		$this->add_invoice( $this->subscription_ids[0], 'production', array( 'payment_status' => 'failed' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T00:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'payment_grace_expired', $result['reason_code'] );
	}

	public function test_paid_past_invoice_does_not_reactivate_an_inactive_contract(): void {
		$id = $this->add_subscription(
			'production',
			array(
				'internal_status'           => 'canceled',
				'current_period_end'        => '2026-10-01 00:00:00',
				'current_slot'              => null,
			)
		);
		$this->add_invoice( $id, 'production', array( 'payment_status' => 'paid' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'no_current_contract', $result['reason_code'] );
	}

	public function test_sandbox_and_production_rows_are_isolated(): void {
		$this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$this->add_subscription( 'sandbox', array( 'internal_status' => 'pending', 'initial_payment_status' => 'pending', 'current_period_end' => null ) );
		$as_of = $this->fixed_time( '2026-10-29T12:00:00Z' );
		$this->assertSame( 'standard', $this->service( 'production' )->resolve_for_user( $this->user_id, $as_of )['effective_plan'] );
		$this->assertSame( 'free', $this->service( 'sandbox' )->resolve_for_user( $this->user_id, $as_of )['effective_plan'] );
	}

	public function test_multiple_current_contracts_fail_closed(): void {
		$this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$this->add_subscription( 'production', array( 'merchant_id' => 'merchant-other', 'square_subscription_id' => 'square-sub-other', 'sync_state' => 'error', 'current_period_end' => '2026-11-01 00:00:00' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertSame( 'free', $result['effective_plan'] );
		$this->assertSame( 'multiple_current_contracts', $result['reason_code'] );
		$this->assertTrue( $result['recheck_required'] );
	}

	public function test_invoice_link_mismatch_is_an_error_and_does_not_grant_standard(): void {
		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$this->add_invoice( $id, 'production', array( 'user_id' => $this->user_id + 1 ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'entitlement_data_inconsistent', $result->get_error_code() );
	}

	public function test_database_error_is_distinct_from_no_contract(): void {
		global $wpdb;
		$this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$invoice_table = $wpdb->prefix . 'egov_law_subscription_invoices';
		$wpdb->query( "DROP TABLE `{$invoice_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );
		try {
			$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'entitlement_database_error', $result->get_error_code() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}

	public function test_missing_admin_review_table_never_grants_standard(): void {
		global $wpdb;
		$this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$review_table = $wpdb->prefix . 'egov_law_square_admin_reviews';
		$wpdb->query( "DROP TABLE `{$review_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );
		try {
			$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'entitlement_database_error', $result->get_error_code() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}

	public function test_invalid_environment_is_rejected(): void {
		$result = $this->service( 'staging' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'entitlement_environment_invalid', $result->get_error_code() );
	}

	public function test_common_adapter_preserves_free_standard_and_unknown_contract_results(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$as_of = $this->fixed_time( '2026-10-29T12:00:00Z' );
		$free = egov_law_monitor_get_effective_entitlement( $this->user_id, $as_of );
		$this->assertIsArray( $free );
		$this->assertSame( 'free', $free['effective_plan'] );
		$this->assertSame( 5, $free['watch_limit'] );
		$this->assertSame( 3, $free['ai_summary_limit'] );
		$this->assertSame( 'no_current_contract', $free['reason_code'] );

		$id = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$standard = egov_law_monitor_get_effective_entitlement( $this->user_id, $as_of );
		$this->assertIsArray( $standard );
		$this->assertSame( 'standard', $standard['effective_plan'] );
		$this->assertSame( 10, $standard['watch_limit'] );
		$this->assertSame( 100, $standard['ai_summary_limit'] );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'egov_law_subscriptions', array( 'internal_status' => 'unknown' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		$unknown = egov_law_monitor_get_effective_entitlement( $this->user_id, $as_of );
		$this->assertIsArray( $unknown );
		$this->assertSame( 'free', $unknown['effective_plan'] );
		$this->assertSame( 'contract_state_unknown', $unknown['reason_code'] );
		$this->assertTrue( $unknown['recheck_required'] );
	}

	public function test_common_adapter_returns_database_error_instead_of_a_plan_string(): void {
		global $wpdb;
		$this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );
		try {
			$result = egov_law_monitor_get_effective_entitlement( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'entitlement_database_error', $result->get_error_code() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}

	public function test_ai_summary_page_uses_free_entitlement_when_legacy_meta_says_standard(): void {
		global $wpdb;
		egov_law_monitor_create_tables();
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$GLOBALS['egov_law_monitor_test_summary_generator_calls'] = 0;
		$previous_user = get_current_user_id();
		$previous_get  = $_GET;
		wp_set_current_user( $this->user_id );
		$_GET = array(
			'law_id'         => 'test-law-ai-entitlement',
			'effective_date' => '2026-01-01',
			'law_data_id'    => 'test-law-data',
			'sub_revision'   => 'test-revision',
		);
		try {
			$html = egov_law_monitor_ai_summary_shortcode();
		} finally {
			$_GET = $previous_get;
			wp_set_current_user( $previous_user );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT plan_code, usage_status FROM {$wpdb->prefix}egov_law_ai_summary_usage WHERE user_id = %d AND environment = %s",
				$this->user_id,
				'production'
			),
			ARRAY_A
		);
		$this->assertSame( 1, $GLOBALS['egov_law_monitor_test_summary_generator_calls'] );
		$this->assertStringContainsString( 'テスト要約', $html );
		$this->assertIsArray( $row );
		$this->assertSame( 'free', $row['plan_code'] );
		$this->assertSame( 'committed', $row['usage_status'] );
	}

	public function test_ai_summary_page_rejects_generation_on_entitlement_database_error(): void {
		global $wpdb;
		egov_law_monitor_create_tables();
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );
		$previous_user = get_current_user_id();
		$previous_get  = $_GET;
		wp_set_current_user( $this->user_id );
		$_GET = array(
			'law_id'         => 'test-law-ai-error',
			'effective_date' => '2026-01-01',
			'law_data_id'    => 'test-law-data',
			'sub_revision'   => 'test-revision',
		);
		$GLOBALS['egov_law_monitor_test_summary_generator_calls'] = 0;
		try {
			$html = egov_law_monitor_ai_summary_shortcode();
		} finally {
			$_GET = $previous_get;
			wp_set_current_user( $previous_user );
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$usage_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_ai_summary_usage WHERE user_id = %d",
				$this->user_id
			)
		);
		$this->assertSame( 0, $GLOBALS['egov_law_monitor_test_summary_generator_calls'] );
		$this->assertStringContainsString( 'プラン情報を確認できません。時間をおいて再度お試しください。', $html );
		$this->assertStringNotContainsString( 'entitlement_database_error', $html );
		$this->assertSame( 0, (int) $usage_count );
	}

	public function test_ai_summary_management_uses_free_entitlement_usage_ledger_and_keeps_history(): void {
		global $wpdb;
		egov_law_monitor_create_tables();
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		update_user_meta( $this->user_id, 'egov_law_monitor_ai_summary_trial_usage', 1 );
		$this->reserve_summary_usage( 'free', 'free-management-summary' );
		$this->assertNotFalse(
			egov_law_monitor_save_ai_summary_history(
				$this->user_id,
				'test-law-management',
				'管理画面テスト法令',
				'2026-01-01',
				'test-data',
				'test-revision',
				str_repeat( 'a', 64 ),
				'保存済み要約',
				'履歴表示のテスト'
			)
		);

		$html = $this->render_ai_summary_management();
		$this->assertStringContainsString( 'AI要約のお試し利用：', $html );
		$this->assertStringContainsString( '残り 1', $html );
		$this->assertStringContainsString( '/ 3 回', $html );
		$this->assertStringContainsString( '管理画面テスト法令', $html );
		$this->assertStringContainsString( 'AI要約の履歴', $html );
	}

	public function test_ai_summary_management_uses_standard_entitlement_and_monthly_usage_ledger(): void {
		egov_law_monitor_create_tables();
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		$this->add_subscription( 'production', array( 'current_period_end' => '2030-01-01 00:00:00' ) );
		$this->reserve_summary_usage( 'standard', 'standard-management-summary' );

		$html = $this->render_ai_summary_management();
		$this->assertStringContainsString( '今月のAI要約利用：', $html );
		$this->assertStringContainsString( '残り 99', $html );
		$this->assertStringContainsString( '/ 100 回', $html );
	}

	public function test_ai_summary_management_reports_entitlement_error_without_quota_values(): void {
		global $wpdb;
		egov_law_monitor_create_tables();
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$html = $this->render_ai_summary_management();
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$this->assertStringContainsString( 'AI要約の利用状況を取得できません。', $html );
		$this->assertStringNotContainsString( '残り', $html );
	}

	public function test_ai_summary_management_reports_usage_ledger_error_without_quota_values(): void {
		global $wpdb;
		egov_law_monitor_create_tables();
		$table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$html = $this->render_ai_summary_management();
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$this->assertStringContainsString( 'AI要約の利用状況を取得できません。', $html );
		$this->assertStringNotContainsString( '残り', $html );
	}

	private function reserve_summary_usage( $plan, $key ) {
		$result = egov_law_monitor_ai_usage_service()->reserve(
			$this->user_id,
			$plan,
			hash( 'sha256', $this->user_id . '|' . $key )
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'reserved', $result['status'] );
	}

	private function render_ai_summary_management() {
		$previous_user = get_current_user_id();
		wp_set_current_user( $this->user_id );
		try {
			return egov_law_monitor_ai_summary_management_shortcode();
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	private function service( $environment ) {
		global $wpdb;
		return new Egov_Law_Monitor_Entitlement_Service( new Egov_Law_Monitor_Subscription_Repository( $wpdb ), $environment );
	}

	private function add_subscription( $environment, array $overrides = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$with_approval = array_key_exists( 'with_approval', $overrides ) ? (bool) $overrides['with_approval'] : true;
		$approval_timezone = $overrides['approval_timezone'] ?? 'UTC';
		$approval_period_start = $overrides['approval_period_start'] ?? null;
		$approval_period_end = $overrides['approval_period_end'] ?? null;
		unset( $overrides['with_approval'], $overrides['approval_timezone'], $overrides['approval_period_start'], $overrides['approval_period_end'] );
		$data = array_merge(
			array(
				'user_id'                  => $this->user_id,
				'environment'              => $environment,
				'merchant_id'              => 'merchant-test',
				'square_customer_id'       => 'customer-' . count( $this->subscription_ids ),
				'square_subscription_id'   => 'subscription-' . count( $this->subscription_ids ),
				'plan_code'                => 'standard',
				'internal_status'          => 'active',
				'square_status'            => 'ACTIVE',
				'initial_payment_status'   => 'succeeded',
				'sync_state'               => 'ok',
				'current_slot'             => 1,
				'current_period_start'     => '2026-10-01 00:00:00',
				'current_period_end'       => '2026-11-01 00:00:00',
				'created_at'               => '2026-01-01 00:00:00',
				'updated_at'               => '2026-01-01 00:00:00',
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $table, $data ) );
		$id = (int) $wpdb->insert_id;
		$this->subscription_ids[] = $id;
		if ( $with_approval && is_string( $data['current_period_start'] ) && is_string( $data['current_period_end'] ) ) {
			$this->append_admin_review(
				$id,
				'approve',
				current_time( 'mysql', true ),
				array(
					'subscription_timezone' => $approval_timezone,
					'approved_period_start' => $approval_period_start ?: $data['current_period_start'],
					'approved_period_end' => $approval_period_end ?: $data['current_period_end'],
				)
			);
		}
		return $id;
	}

	private function append_admin_review( $contract_id, $action, $recorded_at, array $overrides = array() ) {
		global $wpdb;
		$contract = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_law_subscriptions WHERE id = %d", $contract_id ), ARRAY_A );
		$defaults = array(
			'user_id' => $this->user_id,
			'environment' => $contract['environment'],
			'merchant_id' => $contract['merchant_id'],
			'contract_id' => $contract_id,
			'action_type' => $action,
			'approved_period_start' => null,
			'approved_period_end' => null,
			'subscription_timezone' => null,
			'monthly_billing_anchor_date' => null,
			'square_payment_id' => null,
			'evidence_code' => 'entitlement_policy_test',
			'reviewer_user_id' => 1,
			'recorded_at' => $recorded_at,
		);
		if ( 'approve' === $action ) {
			$defaults['approved_period_start'] = $contract['current_period_start'];
			$defaults['approved_period_end'] = $contract['current_period_end'];
			$defaults['subscription_timezone'] = 'UTC';
			$defaults['monthly_billing_anchor_date'] = 1;
			$defaults['square_payment_id'] = 'entitlement-payment-' . $contract_id;
		}
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_square_admin_reviews', array_merge( $defaults, $overrides ) ) );
	}

	private function add_invoice( $subscription_id, $environment, array $overrides = array() ): void {
		global $wpdb;
		$subscription = $wpdb->get_row( $wpdb->prepare( "SELECT merchant_id FROM {$wpdb->prefix}egov_law_subscriptions WHERE id = %d", $subscription_id ), ARRAY_A );
		$data = array_merge(
			array(
				'subscription_id' => $subscription_id,
				'user_id'         => $this->user_id,
				'environment'     => $environment,
				'merchant_id'     => $subscription['merchant_id'],
				'square_invoice_id' => 'invoice-' . $subscription_id,
				'payment_status'  => 'paid',
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscription_invoices', $data ) );
	}

	private function fixed_time( $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value );
	}
}
