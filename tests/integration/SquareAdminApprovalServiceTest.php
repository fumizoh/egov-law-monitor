<?php

use PHPUnit\Framework\TestCase;

final class SquareAdminApprovalServiceTest extends TestCase {
	private $wpdb;
	private $user_id;
	private $attempt_id;
	private $attempt_uuid;
	private $contract_id;
	private $validation;
	private $review_repository;
	private $extra_attempt_ids = array();
	private $extra_contract_ids = array();

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->user_id = (int) wp_insert_user( array( 'user_login' => 'approval-' . wp_generate_password( 10, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'approval-' . wp_generate_password( 10, false, false ) . '@example.org', 'role' => 'subscriber' ) );
		$this->assertGreaterThan( 0, $this->user_id );
		$this->review_repository = new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb );
		$this->attempt_uuid = wp_generate_uuid4();
		$this->attempt_id = $this->insert_attempt();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$reviews = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
			$invoices = $this->wpdb->prefix . 'egov_law_subscription_invoices';
			$subscriptions = $this->wpdb->prefix . 'egov_law_subscriptions';
			$attempts = $this->wpdb->prefix . 'egov_law_checkout_attempts';
			foreach ( array_unique( array_filter( array_merge( array( $this->contract_id ), $this->extra_contract_ids ) ) ) as $id ) {
				$this->wpdb->delete( $reviews, array( 'contract_id' => $id ), array( '%d' ) );
				$this->wpdb->delete( $invoices, array( 'subscription_id' => $id ), array( '%d' ) );
				$this->wpdb->delete( $subscriptions, array( 'id' => $id ), array( '%d' ) );
			}
			if ( $this->attempt_uuid ) {
				$this->wpdb->delete( $reviews, array( 'checkout_attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
				$this->wpdb->delete( $attempts, array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			}
			foreach ( $this->extra_attempt_ids as $id ) { $this->wpdb->delete( $attempts, array( 'id' => $id ), array( '%d' ) ); }
			if ( $this->user_id ) { wp_delete_user( $this->user_id ); }
		}
		parent::tearDown();
	}

	public function test_initial_approval_creates_contract_invoice_and_review_atomically_and_entitles_only_after_approval(): void {
		$validation = $this->initial_validation();
		$service = $this->service( $validation );
		$result = $service->approve_initial( $this->attempt_id, 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 'approved', $result['status'] );
		$this->assertFalse( $result['automatic_approval_candidate'] );
		$this->contract_id = $result['contract_id'];
		$contract = $this->contract_row( $this->contract_id );
		$this->assertSame( 'ACTIVE', $contract['square_status'] );
		$this->assertSame( 'active', $contract['internal_status'] );
		$this->assertSame( 1, (int) $contract['current_slot'] );
		$this->assertSame( '2026-11-01 00:00:00', $contract['current_period_start'] );
		$this->assertSame( '2026-12-01 00:00:00', $contract['current_period_end'] );
		$review = $this->review_repository->get_review( $result['review_id'] );
		$this->assertSame( '2026-11-01 00:00:00', $review['approved_period_start'] );
		$this->assertSame( '2026-12-01 00:00:00', $review['approved_period_end'] );
		$this->assertSame( 'UTC', $review['subscription_timezone'] );
		$this->assertSame( 1, (int) $review['reviewer_user_id'] );
		$invoice = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE id = %d", $result['invoice_row_id'] ), ARRAY_A );
		$this->assertSame( 'succeeded', $invoice['payment_status'] );
		$this->assertSame( 'pay-initial-' . $this->attempt_id, $invoice['square_payment_id'] );
		$entitlement = ( new Egov_Law_Monitor_Entitlement_Service( new Egov_Law_Monitor_Subscription_Repository( $this->wpdb ), 'sandbox' ) )->resolve_for_user( $this->user_id, new DateTimeImmutable( '2026-11-07T00:00:00Z' ) );
		$this->assertSame( 'standard', $entitlement['effective_plan'], wp_json_encode( $entitlement ) );
	}

	public function test_recurring_approval_updates_period_invoice_and_append_only_review(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$validation = $this->recurring_validation();
		$result = $this->service( $validation )->approve_recurring( $this->contract_id, 1, '月次決済を確認' );

		$this->assertIsArray( $result );
		$this->assertSame( 'approved', $result['status'] );
		$contract = $this->contract_row( $this->contract_id );
		$this->assertSame( '2026-11-06 15:00:00', $contract['current_period_start'] );
		$this->assertSame( '2026-12-06 15:00:00', $contract['current_period_end'] );
		$this->assertSame( 'cancel_scheduled', $contract['internal_status'] );
		$this->assertSame( 'ACTIVE', $contract['square_status'] );
		$review = $this->review_repository->get_review( $result['review_id'] );
		$this->assertSame( '2026-11-07 00:00:00', $review['approved_period_start'] );
		$this->assertSame( '2026-12-07 00:00:00', $review['approved_period_end'] );
		$this->assertSame( 'Asia/Tokyo', $review['subscription_timezone'] );
		$this->assertSame( '月次決済を確認', $review['admin_note'] );
		$this->assertSame( 2, count( $this->review_repository->get_reviews_for_contract( $this->contract_id ) ) );
		$entitlement = ( new Egov_Law_Monitor_Entitlement_Service( new Egov_Law_Monitor_Subscription_Repository( $this->wpdb ), 'sandbox' ) )->resolve_for_user( $this->user_id, new DateTimeImmutable( '2026-11-07T00:00:00Z' ) );
		$this->assertSame( 'standard', $entitlement['effective_plan'] );
	}

	public function test_repeated_initial_payment_approval_cannot_create_a_second_current_contract(): void {
		$first = $this->service( $this->initial_validation() )->approve_initial( $this->attempt_id, 1 );
		$this->assertIsArray( $first );
		$this->contract_id = $first['contract_id'];
		$after_first = $this->table_counts();
		$second = $this->service( $this->initial_validation() )->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'square_admin_approval_local_state_changed', $second->get_error_code() );
		$this->assertSame( $after_first, $this->table_counts() );
		$this->assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE user_id = %d AND current_slot = 1", $this->user_id ) ) );
	}

	public function test_repeated_recurring_invoice_approval_does_not_append_or_extend_again(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$first = $this->service( $this->recurring_validation() )->approve_recurring( $this->contract_id, 1 );
		$this->assertIsArray( $first );
		$after_first = $this->table_counts();
		$approved_end = $this->contract_row( $this->contract_id )['current_period_end'];
		$second = $this->service( $this->recurring_validation() )->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'square_admin_approval_local_state_changed', $second->get_error_code() );
		$this->assertSame( $after_first, $this->table_counts() );
		$this->assertSame( $approved_end, $this->contract_row( $this->contract_id )['current_period_end'] );
	}

	public function test_validation_rechecks_and_local_state_change_aborts_without_partial_write(): void {
		$before = $this->table_counts();
		$validation = $this->initial_validation();
		$validation->on_initial = function () {
			$this->wpdb->update( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'status' => 'reconciliation_required' ), array( 'id' => $this->attempt_id ) );
		};
		$result = $this->service( $validation )->approve_initial( $this->attempt_id, 1 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_local_state_changed', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_append_failure_rolls_back_contract_invoice_and_review(): void {
		$before = $this->table_counts();
		$validation = $this->initial_validation();
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $this->wpdb, $validation, new Egov_Law_Monitor_Square_Approval_Test_Failing_Review_Repository( $this->wpdb ) );
		$result = $service->approve_initial( $this->attempt_id, 1 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_duplicate', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_non_admin_is_rejected_before_square_validation(): void {
		$validation = $this->initial_validation();
		wp_set_current_user( $this->user_id );
		$result = $this->service( $validation )->approve_initial( $this->attempt_id, $this->user_id );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $validation->initial_calls );
		wp_set_current_user( 1 );
	}

	public function test_recurring_approval_does_not_resume_internal_admin_pause(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$this->wpdb->update( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'internal_status' => 'paused' ), array( 'id' => $this->contract_id ) );
		$before = $this->table_counts();
		$result = $this->service( $this->recurring_validation() )->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_admin_suspended', $result->get_error_code() );
		$this->assertSame( 'paused', $this->contract_row( $this->contract_id )['internal_status'] );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_validation_api_error_does_not_write_contract_invoice_or_review(): void {
		$before = $this->table_counts();
		$validation = new Egov_Law_Monitor_Square_Approval_Test_Validation( $this->wpdb, $this->review_repository, $this->attempt_id, null, array() );
		$validation->set_initial_error( new WP_Error( 'square_resource_transport_error' ) );
		$result = $this->service( $validation )->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_validation_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_transactional_insert_failure_rolls_back_all_initial_rows(): void {
		$before = $this->table_counts();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'insert', $this->wpdb->prefix . 'egov_law_subscriptions' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->initial_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_current_contract_conflict', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_invoice_insert_failure_rolls_back_new_contract_and_review(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$before = $this->table_counts();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'insert', $this->wpdb->prefix . 'egov_law_subscription_invoices' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->recurring_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_database_error', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_contract_update_failure_rolls_back_invoice_and_review(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$before = $this->table_counts();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'update', $this->wpdb->prefix . 'egov_law_subscriptions' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->recurring_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_database_error', $result->get_error_code() );
		$this->assertSame( '2026-11-06 15:00:00', $this->contract_row( $this->contract_id )['current_period_end'] );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_invoice_update_failure_rolls_back_contract_and_review(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-next-' . $this->contract_id, 'payment-next-' . $this->contract_id, 'pending' );
		$before = $this->table_counts();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'update', $this->wpdb->prefix . 'egov_law_subscription_invoices' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->recurring_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_database_error', $result->get_error_code() );
		$this->assertSame( 'pending', $this->wpdb->get_var( $this->wpdb->prepare( "SELECT payment_status FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE square_invoice_id = %s", 'invoice-next-' . $this->contract_id ) ) );
		$this->assertSame( '2026-11-06 15:00:00', $this->contract_row( $this->contract_id )['current_period_end'] );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_review_insert_failure_rolls_back_contract_and_invoice(): void {
		$before = $this->table_counts();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'insert', $this->wpdb->prefix . 'egov_law_square_admin_reviews' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->initial_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_database_error', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_period_inconsistency_is_rejected_before_any_write(): void {
		$before = $this->table_counts();
		$validation = $this->initial_validation();
		$invalid = $this->initial_validation_result();
		$invalid['period_candidate']['end_exclusive_date'] = $invalid['period_candidate']['start_date'];
		$validation->set_initial_result( $invalid );
		$result = $this->service( $validation )->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_period_invalid', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
	}

	public function test_existing_current_contract_for_another_merchant_blocks_initial_approval(): void {
		$this->insert_competing_current_contract();
		$before = $this->table_counts();
		$result = $this->service( $this->initial_validation() )->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_current_contract_conflict', $result->get_error_code() );
		$this->assertSame( $before, $this->table_counts() );
		$this->assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE user_id = %d AND current_slot = 1", $this->user_id ) ) );
	}

	public function test_recurring_commit_outcome_unknown_is_reconciled_against_same_invoice(): void {
		$this->contract_id = $this->insert_existing_contract();
		$this->insert_approved_baseline();
		$this->insert_invoice( $this->contract_id, 'invoice-prior-' . $this->contract_id, 'payment-prior-' . $this->contract_id, 'paid' );
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'commit_after_apply' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $this->recurring_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_recurring( $this->contract_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_commit_outcome_unknown', $result->get_error_code() );
		$target = $result->get_error_data()['reconciliation_target'];
		$this->assertSame( 'invoice-next-' . $this->contract_id, $target['square_invoice_id'] );
		$this->assertSame( 'payment-next-' . $this->contract_id, $target['square_payment_id'] );
		$reconciled = $service->reconcile_commit_outcome( $target, 1 );
		$this->assertSame( 'approved', $reconciled['status'] );
		$this->assertSame( $this->contract_id, $reconciled['contract_id'] );
	}

	public function test_rolled_back_commit_uncertainty_is_reported_as_not_approved_without_retry(): void {
		$validation = $this->initial_validation();
		$database = new Egov_Law_Monitor_Square_Approval_Test_Fault_DB( $this->wpdb, 'rollback_at_commit' );
		$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $database, $validation, new Egov_Law_Monitor_Square_Admin_Review_Repository( $database ) );
		$result = $service->approve_initial( $this->attempt_id, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'square_admin_approval_commit_outcome_unknown', $result->get_error_code() );
		$this->assertSame( 1, $validation->initial_calls );
		$reconciled = $service->reconcile_commit_outcome( $result->get_error_data()['reconciliation_target'], 1 );
		$this->assertSame( 'not_approved', $reconciled['status'] );
		$this->assertSame( 1, $validation->initial_calls );
		$this->assertSame( 0, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE user_id = {$this->user_id}" ) );
	}

	public function test_independent_connection_lock_timeout_fails_closed(): void {
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $this->wpdb->prefix );
		$this->assertSame( 0, (int) $other->query( 'SET SESSION innodb_lock_wait_timeout = 1' ) );
		$this->assertSame( 0, (int) $this->wpdb->query( 'START TRANSACTION' ) );
		$this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM {$this->wpdb->users} WHERE ID = %d FOR UPDATE", $this->user_id ) );
		try {
			$service = new Egov_Law_Monitor_Square_Admin_Approval_Service( $other, $this->initial_validation(), new Egov_Law_Monitor_Square_Admin_Review_Repository( $other ) );
			$result = $service->approve_initial( $this->attempt_id, 1 );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'square_admin_approval_database_error', $result->get_error_code() );
			$this->assertSame( 0, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE user_id = {$this->user_id}" ) );
		} finally {
			$this->wpdb->query( 'ROLLBACK' );
			if ( method_exists( $other, 'close' ) ) { $other->close(); }
		}
	}

	private function service( $validation, $repository = null ) {
		return new Egov_Law_Monitor_Square_Admin_Approval_Service( $this->wpdb, $validation, $repository ?: $this->review_repository );
	}

	private function initial_validation() {
		return new Egov_Law_Monitor_Square_Approval_Test_Validation( $this->wpdb, $this->review_repository, $this->attempt_id, null, $this->initial_validation_result() );
	}

	private function initial_validation_result() {
		return array(
			'target_type' => 'initial', 'local_attempt_id' => $this->attempt_id, 'local_contract_id' => null,
			'approval_eligible' => true, 'approved' => false, 'contract_creation_candidate' => true,
			'payment_evidence' => array( 'confirmed' => true, 'payment_id' => 'pay-initial-' . $this->attempt_id, 'invoice_id' => 'invoice-initial-' . $this->attempt_id, 'amount' => 500, 'currency' => 'JPY' ),
			'subscription_link' => array( 'status' => 'confirmed', 'subscription_id' => 'square-sub-' . $this->attempt_id ),
			'invoice_verification' => array( 'verified' => true, 'invoice_id' => 'invoice-initial-' . $this->attempt_id ),
			'period_candidate' => $this->candidate( 'initial', '2026-11-01', '2026-12-01', 'UTC' ), 'timezone' => 'UTC', 'monthly_billing_anchor_date' => 1,
			'verified_subscription' => array( 'id' => 'square-sub-' . $this->attempt_id, 'status' => 'ACTIVE', 'location_id' => 'location-test', 'customer_id' => 'customer-' . $this->attempt_id, 'plan_variation_id' => 'variation-test', 'version' => 4 ),
			'verified_invoice' => array( 'id' => 'invoice-initial-' . $this->attempt_id, 'order_id' => 'order-test-' . $this->attempt_id, 'status' => 'PAID', 'payment_state' => 'paid', 'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY', 'version' => 2 ),
		);
	}

	private function recurring_validation() {
		return new Egov_Law_Monitor_Square_Approval_Test_Validation( $this->wpdb, $this->review_repository, null, $this->contract_id, array(
			'target_type' => 'recurring', 'local_attempt_id' => null, 'local_contract_id' => $this->contract_id,
			'approval_eligible' => true, 'approved' => false, 'previous_review_id' => $this->baseline_review_id,
			'payment_evidence' => array( 'confirmed' => true, 'payment_id' => 'payment-next-' . $this->contract_id, 'invoice_id' => 'invoice-next-' . $this->contract_id, 'amount' => 500, 'currency' => 'JPY' ),
			'subscription_link' => array( 'status' => 'identified_from_local_contract', 'subscription_id' => 'square-sub-' . $this->contract_id ),
			'invoice_verification' => array( 'verified' => true, 'invoice_id' => 'invoice-next-' . $this->contract_id ),
			'period_candidate' => $this->candidate( 'renewal', '2026-11-07', '2026-12-07', 'Asia/Tokyo' ), 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 7,
			'subscription_verification' => array( 'subscription_id' => 'square-sub-' . $this->contract_id, 'square_status' => 'ACTIVE', 'version' => 5 ),
			'verified_invoice' => array( 'id' => 'invoice-next-' . $this->contract_id, 'order_id' => 'order-next-' . $this->contract_id, 'status' => 'PAID', 'payment_state' => 'paid', 'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY', 'version' => 3 ),
			'verified_payment' => array( 'id' => 'payment-next-' . $this->contract_id, 'status' => 'COMPLETED' ),
		) );
	}

	private function candidate( $type, $start, $end, $timezone ) {
		return array( 'status' => 'candidate', 'period_type' => $type, 'start_date' => $start, 'end_exclusive_date' => $end, 'timezone' => $timezone, 'monthly_billing_anchor_date' => 'Asia/Tokyo' === $timezone ? 7 : 1 );
	}

	private function insert_attempt() {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid, 'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'approval-test-merchant', 'square_location_id' => 'location-test',
			'plan_code' => 'standard', 'catalog_item_variation_id' => 'standard-item', 'subscription_plan_variation_id' => 'variation-test', 'status' => 'pending', 'idempotency_key' => wp_generate_uuid4(),
			'square_order_id' => 'order-test-' . $this->user_id, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		return (int) $this->wpdb->insert_id;
	}

	private function insert_existing_contract() {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'approval-test-merchant', 'square_location_id' => 'location-test', 'square_customer_id' => 'customer-recurring',
			'square_subscription_id' => 'square-sub-recurring-' . $this->user_id, 'square_plan_variation_id' => 'variation-test', 'checkout_attempt_uuid' => $this->attempt_uuid,
			'plan_code' => 'standard', 'internal_status' => 'cancel_scheduled', 'square_status' => 'ACTIVE', 'initial_payment_status' => 'succeeded', 'current_slot' => 1,
			'current_period_start' => '2026-10-06 15:00:00', 'current_period_end' => '2026-11-06 15:00:00', 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		return (int) $this->wpdb->insert_id;
	}

	private function insert_competing_current_contract() {
		$other_attempt_uuid = wp_generate_uuid4();
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $other_attempt_uuid, 'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'other-merchant', 'square_location_id' => 'location-test',
			'plan_code' => 'standard', 'catalog_item_variation_id' => 'other-item', 'subscription_plan_variation_id' => 'variation-test', 'status' => 'pending', 'idempotency_key' => wp_generate_uuid4(),
			'square_order_id' => 'other-order-' . $this->user_id, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->extra_attempt_ids[] = (int) $this->wpdb->insert_id;
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'other-merchant', 'square_location_id' => 'location-test', 'square_customer_id' => 'other-customer',
			'square_subscription_id' => 'other-current-sub-' . $this->user_id, 'square_plan_variation_id' => 'variation-test', 'checkout_attempt_uuid' => $other_attempt_uuid,
			'plan_code' => 'standard', 'internal_status' => 'active', 'square_status' => 'ACTIVE', 'initial_payment_status' => 'succeeded', 'current_slot' => 1,
			'current_period_start' => '2026-10-01 00:00:00', 'current_period_end' => '2026-11-01 00:00:00', 'sync_state' => 'ok', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->extra_contract_ids[] = (int) $this->wpdb->insert_id;
	}

	private $baseline_review_id;
	private function insert_approved_baseline() {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_square_admin_reviews', array(
			'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'approval-test-merchant', 'contract_id' => $this->contract_id,
			'checkout_attempt_id' => $this->attempt_id, 'checkout_attempt_uuid' => $this->attempt_uuid, 'square_invoice_id' => 'invoice-prior-' . $this->contract_id, 'square_payment_id' => 'payment-prior-' . $this->contract_id,
			'action_type' => 'approve', 'candidate_period_start' => '2026-10-07 00:00:00', 'candidate_period_end' => '2026-11-07 00:00:00', 'approved_period_start' => '2026-10-07 00:00:00', 'approved_period_end' => '2026-11-07 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 7, 'evidence_code' => 'verified_prior_payment', 'reviewer_user_id' => 1, 'recorded_at' => '2026-10-07 00:00:00',
		) ) );
		$this->baseline_review_id = (int) $this->wpdb->insert_id;
	}

	private function insert_invoice( $contract_id, $invoice_id, $payment_id, $payment_status ) {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscription_invoices', array(
			'subscription_id' => $contract_id, 'user_id' => $this->user_id, 'environment' => 'sandbox', 'merchant_id' => 'approval-test-merchant',
			'square_invoice_id' => $invoice_id, 'square_payment_id' => $payment_id, 'invoice_status' => 'paid', 'square_status' => 'PAID', 'payment_status' => $payment_status,
			'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function contract_row( $id ) { return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $id ), ARRAY_A ); }

	private function table_counts() {
		return array(
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE user_id = {$this->user_id}" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE user_id = {$this->user_id}" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_square_admin_reviews WHERE user_id = {$this->user_id}" ),
			(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE user_id = {$this->user_id}" ),
		);
	}
}

final class Egov_Law_Monitor_Square_Approval_Test_Validation extends Egov_Law_Monitor_Square_Admin_Approval_Validation_Service {
	private $initial_id;
	private $contract_id;
	private $initial_result;
	private $recurring_result;
	private $initial_error;
	public $initial_calls = 0;
	public $recurring_calls = 0;
	public $on_initial;

	public function __construct( $wpdb, $repository, $initial_id, $contract_id, array $result ) {
		parent::__construct( $wpdb, null, null, $repository );
		$this->initial_id = $initial_id;
		$this->contract_id = $contract_id;
		$this->initial_result = $result;
		$this->recurring_result = $result;
	}

	public function validate_initial_approval( $attempt_id ) {
		++$this->initial_calls;
		if ( is_callable( $this->on_initial ) ) { call_user_func( $this->on_initial ); }
		if ( is_wp_error( $this->initial_error ) ) { return $this->initial_error; }
		return $attempt_id === $this->initial_id ? $this->initial_result : new WP_Error( 'test_target_mismatch' );
	}

	public function set_initial_error( WP_Error $error ) { $this->initial_error = $error; }
	public function set_initial_result( array $result ) { $this->initial_result = $result; }

	public function validate_recurring_approval( $contract_id ) {
		++$this->recurring_calls;
		return $contract_id === $this->contract_id ? $this->recurring_result : new WP_Error( 'test_target_mismatch' );
	}
}

final class Egov_Law_Monitor_Square_Approval_Test_Failing_Review_Repository extends Egov_Law_Monitor_Square_Admin_Review_Repository {
	public function append_review_in_transaction( array $entry ) {
		return new WP_Error( 'square_admin_review_duplicate_approval', 'test-only failure' );
	}
}

/** Fault-injection proxy around the integration-test wpdb connection. */
final class Egov_Law_Monitor_Square_Approval_Test_Fault_DB {
	private $inner;
	private $fault;
	private $table;
	public function __construct( $inner, $fault, $table = null ) { $this->inner = $inner; $this->fault = $fault; $this->table = $table; }
	public function __get( $name ) { return $this->inner->$name; }
	public function __set( $name, $value ) { $this->inner->$name = $value; }
	public function __call( $name, $arguments ) {
		if ( 'query' === $name && isset( $arguments[0] ) && 'COMMIT' === strtoupper( trim( $arguments[0] ) ) ) {
			if ( 'commit_after_apply' === $this->fault ) { $this->inner->query( 'COMMIT' ); $this->inner->last_error = 'simulated connection loss after commit'; return false; }
			if ( 'rollback_at_commit' === $this->fault ) { $this->inner->query( 'ROLLBACK' ); $this->inner->last_error = 'simulated connection loss before commit'; return false; }
		}
		if ( is_string( $this->table ) && in_array( $name, array( 'insert', 'update' ), true ) && isset( $arguments[0] ) && $arguments[0] === $this->table && $this->fault === $name ) {
			$this->inner->last_error = 'simulated SQL ' . $name . ' failure'; return false;
		}
		return $this->inner->$name( ...$arguments );
	}
}
