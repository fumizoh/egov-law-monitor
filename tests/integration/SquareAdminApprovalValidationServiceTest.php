<?php

use PHPUnit\Framework\TestCase;

final class SquareAdminApprovalValidationServiceTest extends TestCase {
	private $wpdb;
	private $attempt_id;
	private $attempt_uuid;
	private $contract_id;
	private $extra_contract_ids = array();
	private $review_repository;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->attempt_uuid = wp_generate_uuid4();
		$this->insert_attempt();
		$this->review_repository = new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb );
	}

	protected function tearDown(): void {
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			if ( $this->contract_id ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_square_admin_reviews', array( 'contract_id' => $this->contract_id ), array( '%d' ) );
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $this->contract_id ), array( '%d' ) );
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $this->contract_id ), array( '%d' ) );
			}
			foreach ( $this->extra_contract_ids as $extra_contract_id ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $extra_contract_id ), array( '%d' ) );
			}
			if ( $this->attempt_uuid ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_square_admin_reviews', array( 'checkout_attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			}
		}
		parent::tearDown();
	}

	public function test_initial_attempt_returns_candidate_without_approving_or_writing(): void {
		$before = $this->table_counts();
		$service = $this->service( $this->initial_inspection() );
		$result = $service->validate_initial_approval( $this->attempt_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['approval_eligible'], wp_json_encode( $result ) );
		$this->assertFalse( $result['approved'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->assertTrue( $result['contract_creation_candidate'] );
		$this->assertSame( 500, $result['payment_evidence']['amount'] );
		$this->assertSame( 'confirmed', $result['subscription_link']['status'] );
		$this->assertSame( 'inv-initial', $result['invoice_verification']['invoice_id'] );
		$this->assertSame( 'Asia/Tokyo', $result['timezone'] );
		$this->assertSame( 7, $result['monthly_billing_anchor_date'] );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_initial_candidate_is_blocked_when_attempt_payment_is_unconfirmed(): void {
		$inspection = $this->initial_inspection();
		$inspection['initial_payment']['confirmed'] = false;
		$service = $this->service( $inspection );
		$result = $service->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'initial_payment_evidence_not_confirmed', $result['reason_codes'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
	}

	public function test_initial_candidate_requires_proven_attempt_subscription_link(): void {
		$inspection = $this->initial_inspection();
		$inspection['subscription_link']['method'] = 'customer_id_only';
		$service = $this->service( $inspection );
		$result = $service->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'attempt_subscription_link_unproven', $result['reason_codes'] );
		$this->assertSame( 'sub-initial', $result['subscription_link']['subscription_id'] );
	}

	public function test_initial_review_or_refund_evidence_is_not_approvable(): void {
		$inspection = $this->initial_inspection();
		$inspection['status'] = 'review_required';
		$inspection['reason_code'] = 'initial_payment_refund_detected';
		$inspection['initial_payment']['refund_status'] = 'detected';
		$inspection['initial_payment']['confirmed'] = false;
		$result = $this->service( $inspection )->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'initial_payment_refund_detected', $result['reason_codes'] );
		$this->assertTrue( $result['payment_evidence']['refund_detected'] );
	}

	public function test_initial_candidate_is_blocked_for_duplicate_payment_approval(): void {
		$this->insert_review( null, 'approve', 'pay-initial', null, null );
		$result = $this->service( $this->initial_inspection() )->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'initial_payment_already_approved', $result['reason_codes'] );
	}

	public function test_initial_candidate_checks_local_pending_contract_identity(): void {
		$this->insert_initial_contract();
		$inspection = $this->initial_inspection();
		$inspection['subscription_link']['method'] = 'local_contract_and_attempt_reference_chain';
		$result = $this->service( $inspection )->validate_initial_approval( $this->attempt_id );

		$this->assertTrue( $result['approval_eligible'], wp_json_encode( $result ) );
		$this->assertSame( $this->contract_id, $result['local_contract_id'] );
		$this->assertFalse( $result['contract_creation_candidate'] );
	}

	public function test_other_current_contract_blocks_initial_approval(): void {
		$this->insert_competing_current_contract();
		$result = $this->service( $this->initial_inspection() )->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'another_current_contract_exists', $result['reason_codes'] );
	}

	public function test_recurring_candidate_requires_latest_approved_period_and_is_read_only(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$review = $this->review_repository->get_review( $review_id );
		$this->assertSame( '2026-12-07 00:00:00', $review['approved_period_end'] );
		$this->assertSame( 'Asia/Tokyo', $review['subscription_timezone'] );
		$this->assertSame( 7, (int) $review['monthly_billing_anchor_date'] );
		$history = $this->review_repository->get_reviews_for_contract( $this->contract_id, 200 );
		$this->assertSame( $review_id, (int) $history[0]['id'] );
		$this->assertSame( '2026-12-07 00:00:00', $history[0]['approved_period_end'] );
		$before = $this->table_counts();
		$result = $this->service( null, $this->recurring_inspection( $review_id ) )->validate_recurring_approval( $this->contract_id );

		$this->assertTrue( $result['approval_eligible'], wp_json_encode( $result ) );
		$this->assertFalse( $result['approved'] );
		$this->assertSame( 'confirmed', $result['payment_evidence']['status'] );
		$this->assertSame( 'inv-next', $result['invoice_verification']['invoice_id'] );
		$this->assertSame( 0, $result['invoice_verification']['scope']['unchecked_invoice_count'] );
		$this->assertSame( '2026-12-07', $result['period_candidate']['start_date'] );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_recurring_candidate_is_blocked_when_invoice_scope_is_incomplete(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$inspection = $this->recurring_inspection( $review_id );
		$inspection['invoice_scope']['unchecked_invoice_count'] = 1;
		$result = $this->service( null, $inspection )->validate_recurring_approval( $this->contract_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'invoice_or_event_scope_incomplete', $result['reason_codes'] );
		$this->assertFalse( $result['invoice_verification']['scope']['complete'] );
	}

	public function test_recurring_candidate_is_blocked_for_multiple_invoices_or_amount_mismatch(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$multiple = $this->recurring_inspection( $review_id );
		$multiple['payment_evidence']['invoice_results'][] = $multiple['payment_evidence']['invoice_results'][0];
		$multiple['invoice_scope']['requested_invoice_count'] = 2;
		$multiple['invoice_scope']['checked_invoice_count'] = 2;
		$multiple['invoice_scope']['invoice_total_count'] = 3;
		$result = $this->service( null, $multiple )->validate_recurring_approval( $this->contract_id );
		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'invoice_or_event_scope_incomplete', $result['reason_codes'] );

		$amount_mismatch = $this->recurring_inspection( $review_id );
		$amount_mismatch['payment_evidence']['invoice_results'][0]['payment']['total_amount'] = 499;
		$result = $this->service( null, $amount_mismatch )->validate_recurring_approval( $this->contract_id );
		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'invoice_payment_identity_or_amount_unconfirmed', $result['reason_codes'] );
	}

	public function test_recurring_candidate_is_blocked_for_duplicate_invoice_approval(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$this->insert_review( $this->contract_id, 'approve', 'pay-existing', 'inv-next', '2027-01-07 00:00:00' );
		$result = $this->service( null, $this->recurring_inspection( $review_id ) )->validate_recurring_approval( $this->contract_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'invoice_already_approved_or_held', $result['reason_codes'] );
	}

	public function test_recurring_candidate_is_blocked_for_payment_approved_on_another_invoice(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$this->insert_review( $this->contract_id, 'approve', 'pay-next', 'inv-other', '2027-01-07 00:00:00' );
		$result = $this->service( null, $this->recurring_inspection( $review_id ) )->validate_recurring_approval( $this->contract_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'payment_already_approved', $result['reason_codes'] );
	}

	public function test_recurring_candidate_is_blocked_when_period_is_not_contiguous(): void {
		$this->insert_contract();
		$review_id = $this->insert_review( $this->contract_id, 'approve', 'pay-prior', 'inv-prior', '2026-12-07 00:00:00' );
		$inspection = $this->recurring_inspection( $review_id );
		$inspection['period_candidate']['billing_period']['start_date'] = '2026-12-08';
		$result = $this->service( null, $inspection )->validate_recurring_approval( $this->contract_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertContains( 'renewal_period_start_not_contiguous', $result['reason_codes'] );
	}

	public function test_inspection_api_failure_is_not_treated_as_payment_failure_or_success(): void {
		$fake = new Egov_Law_Monitor_Square_Approval_Test_Inspection( new WP_Error( 'square_api_error', 'private detail must not be exposed' ) );
		$result = $this->service( $fake )->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertSame( 'review_required', $result['verification_status'] );
		$this->assertContains( 'initial_inspection_failed', $result['reason_codes'] );
		$this->assertStringNotContainsString( 'private detail', wp_json_encode( $result ) );
	}

	public function test_square_api_error_status_remains_distinct_from_payment_failure(): void {
		$inspection = array( 'status' => 'square_api_error', 'reason_code' => 'subscription_retrieval_failed', 'initial_payment' => array( 'confirmed' => false, 'payment_status' => null ) );
		$result = $this->service( $inspection )->validate_initial_approval( $this->attempt_id );

		$this->assertFalse( $result['approval_eligible'] );
		$this->assertSame( 'square_api_error', $result['inspection_status'] );
		$this->assertContains( 'subscription_retrieval_failed', $result['reason_codes'] );
		$this->assertFalse( $result['payment_evidence']['confirmed'] );
	}

	public function test_invalid_local_identifier_is_rejected_without_square_inspection(): void {
		$fake = new Egov_Law_Monitor_Square_Approval_Test_Inspection( $this->initial_inspection() );
		$result = $this->service( $fake )->validate_initial_approval( '1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $fake->calls );
	}

	public function test_database_read_failure_is_not_treated_as_eligible(): void {
		$wpdb_failure = new class() {
			public $prefix = 'wp_';
			public $last_error = '';

			public function prepare( $query, ...$arguments ) {
				return $query;
			}

			public function get_row( $query, $output ) {
				$this->last_error = 'database failure';
				return null;
			}
		};
		$fake = new Egov_Law_Monitor_Square_Approval_Test_Inspection( $this->initial_inspection() );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Validation_Service( $wpdb_failure, $fake, $fake, $this->review_repository );
		$result = $service->validate_initial_approval( $this->attempt_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_approval_database_unavailable', $result->get_error_code() );
		$this->assertSame( 0, $fake->calls );
	}

	private function service( $initial, $recurring = null ) {
		$initial = is_array( $initial ) ? new Egov_Law_Monitor_Square_Approval_Test_Inspection( $initial ) : $initial;
		$recurring = is_array( $recurring ) ? new Egov_Law_Monitor_Square_Approval_Test_Inspection( $recurring ) : $recurring;
		return new Egov_Law_Monitor_Square_Admin_Approval_Validation_Service(
			$this->wpdb,
			$initial ?: new Egov_Law_Monitor_Square_Approval_Test_Inspection( $this->initial_inspection() ),
			$recurring ?: new Egov_Law_Monitor_Square_Approval_Test_Inspection( array() ),
			$this->review_repository
		);
	}

	private function initial_inspection(): array {
		return array(
			'status' => 'review_required',
			'reason_code' => 'payment_and_period_candidate_require_administrator_approval',
			'initial_payment' => array( 'confirmed' => true, 'status' => 'confirmed', 'payment_id' => 'pay-initial', 'order_id' => 'order-initial', 'amount' => 500, 'currency' => 'JPY', 'payment_status' => 'COMPLETED', 'refund_status' => 'none_reported_at_lookup' ),
			'subscription_link' => array( 'status' => 'confirmed', 'method' => 'attempt_reference_chain', 'subscription_id' => 'sub-initial' ),
			'invoice_payment' => array( 'verified' => true, 'reason_code' => 'initial_invoice_and_checkout_payment_identical', 'invoice_id' => 'inv-initial', 'order_id' => 'order-initial', 'payment_id' => 'pay-initial' ),
			'initial_invoice' => array( 'id' => 'inv-initial', 'order_id' => 'order-initial' ),
			'checkout_order' => array( 'reference_id_matches_attempt' => true ),
			'billing_conditions' => array(
				'status' => 'confirmed',
				'subscription' => array( 'id' => 'sub-initial', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 7, 'start_date' => '2026-11-07' ),
				'variation' => array( 'id' => 'plan-monthly', 'single_monthly_phase' => true ),
			),
			'period_candidate' => $this->candidate( 'initial', '2026-11-07', '2026-12-07' ),
		);
	}

	private function recurring_inspection( $review_id ): array {
		return array(
			'status' => 'payment_confirmed',
			'contract' => array( 'id' => (string) $this->contract_id, 'user_id' => '1', 'environment' => 'sandbox', 'merchant_id' => 'approval-validation-merchant', 'location_id' => 'approval-validation-location', 'customer_id' => 'customer-local', 'subscription_id' => 'sub-local', 'plan_variation_id' => 'plan-monthly' ),
			'variation' => array( 'id' => 'plan-monthly', 'single_monthly_phase' => true ),
			'schedule_review' => array( 'state' => 'none' ),
			'payment_evidence' => array( 'status' => 'confirmed', 'confirmed' => true, 'invoice_results' => array( array(
				'status' => 'payment_confirmed', 'payment_evidence' => 'confirmed',
				'invoice' => array( 'id' => 'inv-next', 'order_id' => 'order-next', 'status' => 'PAID', 'payment_state' => 'paid', 'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY' ),
				'payment' => array( 'id' => 'pay-next', 'status' => 'COMPLETED', 'amount' => 500, 'total_amount' => 500, 'currency' => 'JPY' ),
			) ) ),
			'invoice_scope' => array( 'selection' => 'all_invoice_ids_newer_than_last_approved_invoice', 'baseline_invoice_id' => 'inv-prior', 'invoice_total_count' => 2, 'requested_invoice_count' => 1, 'checked_invoice_count' => 1, 'unchecked_invoice_count' => 0, 'invoice_list_completeness' => 'square_subscription_invoice_ids_field_newest_first' ),
			'event_scope' => array( 'complete' => true ),
			'previous_approval' => array( 'review_id' => $review_id, 'invoice_id' => 'inv-prior', 'approved_period_start' => '2026-11-07 00:00:00', 'approved_period_end_exclusive' => '2026-12-07 00:00:00' ),
			'subscription' => array( 'id' => 'sub-local', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 7 ),
			'period_candidate' => $this->candidate( 'renewal', '2026-12-07', '2027-01-07' ),
		);
	}

	private function candidate( $type, $start, $end ): array {
		return array( 'status' => 'candidate', 'period_type' => $type, 'reason_code' => 'monthly_anchor_candidate', 'basis' => array( 'square_monthly_plan_cadence' ), 'billing_period' => array( 'start_date' => $start, 'end_exclusive_date' => $end, 'end_date_inclusive' => ( new DateTimeImmutable( $end ) )->modify( '-1 day' )->format( 'Y-m-d' ), 'next_start_date' => $end, 'timezone' => 'Asia/Tokyo', 'interval' => 'half_open' ) );
	}

	private function insert_attempt(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'approval-validation-merchant',
			'square_location_id' => 'approval-validation-location', 'plan_code' => 'standard', 'catalog_item_variation_id' => 'item-standard',
			'subscription_plan_variation_id' => 'plan-monthly', 'status' => 'pending', 'idempotency_key' => wp_generate_uuid4(),
			'square_order_id' => 'order-initial', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->attempt_id = (int) $this->wpdb->insert_id;
	}

	private function insert_contract(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'approval-validation-merchant', 'square_location_id' => 'approval-validation-location',
			'square_customer_id' => 'customer-local', 'square_subscription_id' => 'sub-local', 'square_plan_variation_id' => 'plan-monthly', 'checkout_attempt_uuid' => $this->attempt_uuid,
			'plan_code' => 'standard', 'internal_status' => 'active', 'square_status' => 'ACTIVE', 'current_slot' => 1,
			'current_period_start' => '2026-11-06 15:00:00', 'current_period_end' => '2026-12-06 15:00:00', 'sync_state' => 'unknown',
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->contract_id = (int) $this->wpdb->insert_id;
	}

	private function insert_initial_contract(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'approval-validation-merchant', 'square_location_id' => 'approval-validation-location',
			'square_customer_id' => 'customer-initial', 'square_subscription_id' => 'sub-initial', 'square_plan_variation_id' => 'plan-monthly', 'checkout_attempt_uuid' => $this->attempt_uuid,
			'plan_code' => 'standard', 'internal_status' => 'pending', 'square_status' => 'ACTIVE', 'current_slot' => null, 'sync_state' => 'unknown',
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->contract_id = (int) $this->wpdb->insert_id;
	}

	private function insert_competing_current_contract(): void {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'another-merchant', 'square_location_id' => 'other-location',
			'square_customer_id' => 'other-customer', 'square_subscription_id' => 'other-subscription', 'square_plan_variation_id' => 'other-plan',
			'plan_code' => 'standard', 'internal_status' => 'active', 'square_status' => 'ACTIVE', 'current_slot' => 1, 'sync_state' => 'unknown',
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->extra_contract_ids[] = (int) $this->wpdb->insert_id;
	}

	private function insert_review( $contract_id, $action, $payment_id, $invoice_id, $period_end ) {
		$data = array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'approval-validation-merchant', 'contract_id' => $contract_id,
			'checkout_attempt_id' => $contract_id ? null : $this->attempt_id, 'checkout_attempt_uuid' => $this->attempt_uuid,
			'square_invoice_id' => $invoice_id, 'square_payment_id' => $payment_id, 'action_type' => $action,
			'candidate_period_start' => '2026-11-07 00:00:00', 'candidate_period_end' => $period_end,
			'approved_period_start' => '2026-11-07 00:00:00', 'approved_period_end' => $period_end,
			'subscription_timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 7, 'evidence_code' => 'test_verified_payment',
			'reviewer_user_id' => 1, 'recorded_at' => current_time( 'mysql', true ),
		);
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_square_admin_reviews', $data ) );
		return (int) $this->wpdb->insert_id;
	}

	private function table_counts(): array {
		return array(
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_square_admin_reviews" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_checkout_attempts" ),
		);
	}
}

final class Egov_Law_Monitor_Square_Approval_Test_Inspection {
	private $result;
	public $calls = 0;

	public function __construct( $result ) {
		$this->result = $result;
	}

	public function inspect_attempt( $id ) {
		++$this->calls;
		return $this->result;
	}

	public function inspect_contract( $id ) {
		++$this->calls;
		return $this->result;
	}
}
