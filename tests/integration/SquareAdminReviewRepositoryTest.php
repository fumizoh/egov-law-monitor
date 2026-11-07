<?php

use PHPUnit\Framework\TestCase;

final class SquareAdminReviewRepositoryTest extends TestCase {
	private $wpdb;
	private $attempt_id;
	private $attempt_uuid;
	private $contract_id;
	private $invoice_row_id;
	private $review_ids = array();
	private $repository;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->repository = new Egov_Law_Monitor_Square_Admin_Review_Repository( $wpdb );
		$this->attempt_uuid = wp_generate_uuid4();
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_checkout_attempts', array(
			'attempt_uuid' => $this->attempt_uuid, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'review-test-merchant',
			'square_location_id' => 'review-test-location', 'plan_code' => 'standard', 'catalog_item_variation_id' => 'review-item',
			'subscription_plan_variation_id' => 'review-plan', 'status' => 'pending', 'idempotency_key' => wp_generate_uuid4(),
			'square_order_id' => 'review-order-' . wp_generate_uuid4(), 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->attempt_id = (int) $wpdb->insert_id;
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'review-test-merchant', 'square_location_id' => 'review-test-location',
			'square_customer_id' => 'review-customer', 'square_subscription_id' => 'review-subscription-' . wp_generate_uuid4(),
			'square_plan_variation_id' => 'review-plan', 'checkout_attempt_uuid' => $this->attempt_uuid, 'plan_code' => 'standard',
			'internal_status' => 'pending', 'current_slot' => null, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->contract_id = (int) $wpdb->insert_id;
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_subscription_invoices', array(
			'subscription_id' => $this->contract_id, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'review-test-merchant',
			'square_invoice_id' => 'review-invoice-' . wp_generate_uuid4(), 'square_payment_id' => 'review-payment-' . wp_generate_uuid4(),
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->invoice_row_id = (int) $wpdb->insert_id;
	}

	protected function tearDown(): void {
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$reviews = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
			foreach ( $this->review_ids as $review_id ) {
				$this->wpdb->delete( $reviews, array( 'id' => $review_id ), array( '%d' ) );
			}
			if ( $this->invoice_row_id ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'id' => $this->invoice_row_id ), array( '%d' ) );
			}
			if ( $this->contract_id ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $this->contract_id ), array( '%d' ) );
			}
			if ( $this->attempt_uuid ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $this->attempt_uuid ), array( '%s' ) );
			}
		}
		parent::tearDown();
	}

	public function test_initial_approval_record_can_link_attempt_without_invoice_and_is_readable(): void {
		$result = $this->repository->append_review( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'checkout_attempt_uuid' => $this->attempt_uuid,
			'square_payment_id' => 'review-payment-initial-' . wp_generate_uuid4(),
			'action_type' => 'approve',
			'candidate_period_start' => '2026-11-01 00:00:00',
			'candidate_period_end' => '2026-12-01 00:00:00',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) ) );

		$this->assertSame( 'saved', $result['status'] );
		$this->review_ids[] = $result['review_id'];
		$row = $this->repository->get_review( $result['review_id'] );
		$this->assertSame( $this->attempt_uuid, $row['checkout_attempt_uuid'] );
		$this->assertSame( (string) $this->attempt_id, $row['checkout_attempt_id'] );
		$this->assertNull( $row['contract_id'] );
		$this->assertNull( $row['square_invoice_id'] );
		$this->assertSame( 'approve', $row['action_type'] );
		$this->assertSame( 'payment_and_period_checked', $row['evidence_code'] );
		$this->assertSame( '2026-11-01 00:00:00', $row['approved_period_start'] );
		$this->assertSame( 'Asia/Tokyo', $row['subscription_timezone'] );
		$this->assertSame( '1', (string) $row['monthly_billing_anchor_date'] );
		$this->assertSame( '1', (string) $row['reviewer_user_id'] );
		$this->assertSame( 1, count( $this->repository->get_reviews_for_attempt( $this->attempt_uuid ) ) );
		$contract = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT internal_status, current_slot FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_id ), ARRAY_A );
		$attempt = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT status FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_id ), ARRAY_A );
		$this->assertSame( 'pending', $contract['internal_status'] );
		$this->assertNull( $contract['current_slot'] );
		$this->assertSame( 'pending', $attempt['status'] );
	}

	public function test_duplicate_approval_for_payment_is_rejected_but_hold_and_correction_append(): void {
		$payment_id = 'review-payment-dedup-' . wp_generate_uuid4();
		$approval = $this->repository->append_review( $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => $payment_id,
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) ) );
		$this->assertSame( 'saved', $approval['status'] );
		$this->review_ids[] = $approval['review_id'];

		$duplicate = $this->repository->append_review( $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => $payment_id,
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) ) );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'square_admin_review_duplicate_approval', $duplicate->get_error_code() );

		$hold = $this->repository->append_review( $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => $payment_id,
			'action_type' => 'hold',
		) ) );
		$this->assertSame( 'saved', $hold['status'] );
		$this->review_ids[] = $hold['review_id'];

		$correction = $this->repository->append_review( $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => $payment_id,
			'action_type' => 'correct',
			'supersedes_review_id' => $approval['review_id'],
			'approved_period_start' => '2026-11-02 00:00:00',
			'approved_period_end' => '2026-12-02 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) ) );
		$this->assertSame( 'saved', $correction['status'] );
		$this->review_ids[] = $correction['review_id'];
		$this->assertCount( 3, $this->repository->get_reviews_for_contract( $this->contract_id ) );

		$mismatched_correction = $this->repository->append_review( $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => 'another-payment',
			'action_type' => 'correct',
			'supersedes_review_id' => $approval['review_id'],
			'approved_period_start' => '2026-11-02 00:00:00',
			'approved_period_end' => '2026-12-02 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) ) );
		$this->assertInstanceOf( WP_Error::class, $mismatched_correction );
		$this->assertSame( 'square_admin_review_reference_mismatch', $mismatched_correction->get_error_code() );
	}

	public function test_invoice_approval_is_guarded_and_local_invoice_identity_is_checked(): void {
		$invoice = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT square_invoice_id, square_payment_id FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE id = %d", $this->invoice_row_id ), ARRAY_A );
		$entry = $this->entry( array(
			'contract_id' => $this->contract_id,
			'invoice_row_id' => $this->invoice_row_id,
			'square_invoice_id' => $invoice['square_invoice_id'],
			'square_payment_id' => $invoice['square_payment_id'],
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) );
		$saved = $this->repository->append_review( $entry );
		$this->assertSame( 'saved', $saved['status'] );
		$this->review_ids[] = $saved['review_id'];

		$again = $this->repository->append_review( $entry );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'square_admin_review_duplicate_approval', $again->get_error_code() );

		$mismatch = $entry;
		$mismatch['square_payment_id'] = 'different-payment';
		$mismatch_result = $this->repository->append_review( $mismatch );
		$this->assertInstanceOf( WP_Error::class, $mismatch_result );
		$this->assertSame( 'square_admin_review_reference_mismatch', $mismatch_result->get_error_code() );
	}

	public function test_invoice_guard_prevents_second_approval_with_a_different_payment_id(): void {
		$this->assertSame( 1, $this->wpdb->update(
			$this->wpdb->prefix . 'egov_law_subscription_invoices',
			array( 'square_payment_id' => null ),
			array( 'id' => $this->invoice_row_id ),
			array( '%s' ),
			array( '%d' )
		) );
		$invoice_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT square_invoice_id FROM {$this->wpdb->prefix}egov_law_subscription_invoices WHERE id = %d", $this->invoice_row_id ) );
		$base = array(
			'contract_id' => $this->contract_id,
			'invoice_row_id' => $this->invoice_row_id,
			'square_invoice_id' => $invoice_id,
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		);
		$first = $this->repository->append_review( $this->entry( array_merge( $base, array( 'square_payment_id' => 'review-invoice-payment-one' ) ) ) );
		$this->assertSame( 'saved', $first['status'] );
		$this->review_ids[] = $first['review_id'];

		$second = $this->repository->append_review( $this->entry( array_merge( $base, array( 'square_payment_id' => 'review-invoice-payment-two' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'square_admin_review_duplicate_approval', $second->get_error_code() );
	}

	public function test_scope_mismatch_invalid_period_non_admin_and_unapproved_payload_are_rejected(): void {
		$wrong_user = $this->repository->append_review( $this->entry( array( 'user_id' => 2, 'checkout_attempt_id' => $this->attempt_id, 'checkout_attempt_uuid' => $this->attempt_uuid, 'action_type' => 'hold' ) ) );
		$this->assertInstanceOf( WP_Error::class, $wrong_user );
		$this->assertSame( 'square_admin_review_reference_mismatch', $wrong_user->get_error_code() );

		$invalid_period = $this->repository->append_review( $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'action_type' => 'approve', 'square_payment_id' => 'review-payment-invalid-period', 'approved_period_start' => '2026-12-01 00:00:00', 'approved_period_end' => '2026-11-01 00:00:00', 'subscription_timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1 ) ) );
		$this->assertInstanceOf( WP_Error::class, $invalid_period );
		$this->assertSame( 'square_admin_review_arguments_invalid', $invalid_period->get_error_code() );

		wp_set_current_user( 2 );
		$not_admin = $this->repository->append_review( $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'reviewer_user_id' => 2, 'action_type' => 'hold' ) ) );
		$spoofed_admin = $this->repository->append_review( $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'reviewer_user_id' => 1, 'action_type' => 'hold' ) ) );
		wp_set_current_user( 1 );
		$this->assertInstanceOf( WP_Error::class, $not_admin );
		$this->assertSame( 'square_admin_review_forbidden', $not_admin->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $spoofed_admin );
		$this->assertSame( 'square_admin_review_forbidden', $spoofed_admin->get_error_code() );

		$unapproved_payload = $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'action_type' => 'hold', 'raw_square_response' => '{"personal":"must-not-store"}' ) );
		$this->assertInstanceOf( WP_Error::class, $this->repository->append_review( $unapproved_payload ) );
		$this->assertSame( 0, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_square_admin_reviews" ) );
	}

	public function test_read_methods_return_newest_first_and_invalid_filters_fail_closed(): void {
		$one = $this->repository->append_review( $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'action_type' => 'hold' ) ) );
		$two = $this->repository->append_review( $this->entry( array( 'checkout_attempt_id' => $this->attempt_id, 'action_type' => 'hold', 'evidence_code' => 'second_review' ) ) );
		$this->review_ids = array( $one['review_id'], $two['review_id'] );
		$rows = $this->repository->get_reviews_for_attempt( $this->attempt_uuid );
		$this->assertSame( (string) $two['review_id'], $rows[0]['id'] );
		$this->assertSame( (string) $one['review_id'], $rows[1]['id'] );
		$this->assertInstanceOf( WP_Error::class, $this->repository->get_reviews_for_contract( 0 ) );
		$this->assertInstanceOf( WP_Error::class, $this->repository->get_reviews_for_attempt( 'not-a-uuid' ) );
	}

	public function test_approval_requires_timezone_and_anchor_and_stores_valid_anchor_boundaries(): void {
		$base = array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'square_payment_id' => 'review-payment-anchor-one',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		);
		$one = $this->repository->append_review( $this->entry( $base ) );
		$this->assertSame( 'saved', $one['status'] );
		$this->review_ids[] = $one['review_id'];
		$base['square_payment_id'] = 'review-payment-anchor-thirty-one';
		$base['monthly_billing_anchor_date'] = 31;
		$thirty_one = $this->repository->append_review( $this->entry( $base ) );
		$this->assertSame( 'saved', $thirty_one['status'] );
		$this->review_ids[] = $thirty_one['review_id'];
		$this->assertSame( '31', (string) $this->repository->get_review( $thirty_one['review_id'] )['monthly_billing_anchor_date'] );

		foreach ( array(
			array( 'subscription_timezone' => null ),
			array( 'monthly_billing_anchor_date' => null ),
			array( 'subscription_timezone' => 'Mars/Olympus' ),
			array( 'subscription_timezone' => '+09:00' ),
			array( 'monthly_billing_anchor_date' => 0 ),
			array( 'monthly_billing_anchor_date' => 32 ),
			array( 'monthly_billing_anchor_date' => '1' ),
			array( 'approved_period_start' => '2026-11-01 12:00:00' ),
			array( 'approved_period_end' => '2026-12-01 12:00:00' ),
		) as $index => $invalid ) {
			$payload = array_merge( $base, array( 'square_payment_id' => 'invalid-provenance-' . $index ), $invalid );
			$result = $this->repository->append_review( $this->entry( $payload ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'square_admin_review_arguments_invalid', $result->get_error_code() );
		}
	}

	public function test_caller_owned_transaction_controls_review_commit_and_rollback(): void {
		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$before = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertNotFalse( $this->wpdb->query( 'START TRANSACTION' ) );
		$saved = $this->repository->append_review_in_transaction( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'caller_transaction_rollback',
		) ) );
		$this->assertSame( 'saved', $saved['status'] );
		$this->assertSame( $before + 1, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$this->assertNotFalse( $this->wpdb->query( 'ROLLBACK' ) );
		$this->assertSame( $before, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );

		$this->assertNotFalse( $this->wpdb->query( 'START TRANSACTION' ) );
		$committed = $this->repository->append_review_in_transaction( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'caller_transaction_commit',
		) ) );
		$this->assertSame( 'saved', $committed['status'] );
		$this->assertNotFalse( $this->wpdb->query( 'COMMIT' ) );
		$this->review_ids[] = $committed['review_id'];
		$this->assertSame( 'caller_transaction_commit', $this->repository->get_review( $committed['review_id'] )['evidence_code'] );

		$before_invalid = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertNotFalse( $this->wpdb->query( 'START TRANSACTION' ) );
		$invalid = $this->repository->append_review_in_transaction( $this->entry( array(
			'user_id' => 2,
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
		) ) );
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'square_admin_review_reference_mismatch', $invalid->get_error_code() );
		$after_invalid = $this->repository->append_review_in_transaction( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'caller_transaction_after_error',
		) ) );
		$this->assertSame( 'saved', $after_invalid['status'], 'A validation error must leave transaction ownership with the caller.' );
		$this->assertNotFalse( $this->wpdb->query( 'ROLLBACK' ) );
		$this->assertSame( $before_invalid, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	public function test_caller_owned_transaction_keeps_duplicate_approval_protection(): void {
		$entry = $this->entry( array(
			'contract_id' => $this->contract_id,
			'square_payment_id' => 'caller-transaction-duplicate-' . wp_generate_uuid4(),
			'action_type' => 'approve',
			'approved_period_start' => '2026-11-01 00:00:00',
			'approved_period_end' => '2026-12-01 00:00:00',
			'subscription_timezone' => 'Asia/Tokyo',
			'monthly_billing_anchor_date' => 1,
		) );
		$first = $this->repository->append_review( $entry );
		$this->assertSame( 'saved', $first['status'] );
		$this->review_ids[] = $first['review_id'];

		$table = $this->wpdb->prefix . 'egov_law_square_admin_reviews';
		$before_duplicate = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertNotFalse( $this->wpdb->query( 'START TRANSACTION' ) );
		$duplicate = $this->repository->append_review_in_transaction( $entry );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'square_admin_review_duplicate_approval', $duplicate->get_error_code() );
		$after_duplicate = $this->repository->append_review_in_transaction( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'caller_transaction_after_duplicate',
		) ) );
		$this->assertSame( 'saved', $after_duplicate['status'], 'A duplicate approval must not roll back the caller transaction.' );
		$this->assertNotFalse( $this->wpdb->query( 'ROLLBACK' ) );
		$this->assertSame( $before_duplicate, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	public function test_admin_note_is_nullable_normalized_and_length_limited(): void {
		$without_note = $this->repository->append_review( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
		) ) );
		$this->assertSame( 'saved', $without_note['status'] );
		$this->review_ids[] = $without_note['review_id'];
		$this->assertNull( $this->repository->get_review( $without_note['review_id'] )['admin_note'] );

		$with_note = $this->repository->append_review( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'note_normalized',
			'admin_note' => "  <b>管理者確認</b>\n内容  ",
		) ) );
		$this->assertSame( 'saved', $with_note['status'] );
		$this->review_ids[] = $with_note['review_id'];
		$this->assertSame( "管理者確認\n内容", $this->repository->get_review( $with_note['review_id'] )['admin_note'] );

		$maximum = $this->repository->append_review( $this->entry( array(
			'checkout_attempt_id' => $this->attempt_id,
			'action_type' => 'hold',
			'evidence_code' => 'note_max_length',
			'admin_note' => str_repeat( 'あ', 2000 ),
		) ) );
		$this->assertSame( 'saved', $maximum['status'] );
		$this->review_ids[] = $maximum['review_id'];
		$this->assertSame( 2000, preg_match_all( '/./us', $this->repository->get_review( $maximum['review_id'] )['admin_note'] ) );

		foreach ( array( str_repeat( 'あ', 2001 ), array( 'not' => 'text' ) ) as $invalid_note ) {
			$invalid = $this->repository->append_review( $this->entry( array(
				'checkout_attempt_id' => $this->attempt_id,
				'action_type' => 'hold',
				'admin_note' => $invalid_note,
			) ) );
			$this->assertInstanceOf( WP_Error::class, $invalid );
			$this->assertSame( 'square_admin_review_arguments_invalid', $invalid->get_error_code() );
		}
	}

	private function entry( array $overrides = array() ): array {
		return array_merge( array(
			'user_id' => 1,
			'environment' => 'sandbox',
			'merchant_id' => 'review-test-merchant',
			'contract_id' => null,
			'invoice_row_id' => null,
			'checkout_attempt_id' => null,
			'checkout_attempt_uuid' => null,
			'square_invoice_id' => null,
			'square_payment_id' => null,
			'action_type' => 'hold',
			'candidate_period_start' => null,
			'candidate_period_end' => null,
			'approved_period_start' => null,
			'approved_period_end' => null,
			'subscription_timezone' => null,
			'monthly_billing_anchor_date' => null,
			'evidence_code' => 'payment_and_period_checked',
			'admin_note' => null,
			'reviewer_user_id' => 1,
			'supersedes_review_id' => null,
		), $overrides );
	}
}
