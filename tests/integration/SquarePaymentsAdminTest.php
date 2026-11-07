<?php

use PHPUnit\Framework\TestCase;

final class Egov_Law_Monitor_Admin_Square_Read_Service_Stub {
	public $attempt_calls = array();
	public $contract_calls = array();
	public $result;

	public function inspect_attempt( $id ) {
		$this->attempt_calls[] = $id;
		return $this->result;
	}

	public function inspect_contract( $id ) {
		$this->contract_calls[] = $id;
		return $this->result;
	}
}

final class Egov_Law_Monitor_Admin_Square_Approval_Validation_Stub {
	public $initial_calls = array();
	public $recurring_calls = array();
	public $result;
	public function validate_initial_approval( $id ) { $this->initial_calls[] = $id; return $this->result; }
	public function validate_recurring_approval( $id ) { $this->recurring_calls[] = $id; return $this->result; }
}

final class Egov_Law_Monitor_Admin_Square_Approval_Service_Stub {
	public $initial_calls = array();
	public $recurring_calls = array();
	public $reconcile_calls = array();
	public $approve_result;
	public $reconcile_result;
	public function approve_initial( $id, $admin_id, $note ) { $this->initial_calls[] = array( $id, $admin_id, $note ); return $this->approve_result; }
	public function approve_recurring( $id, $admin_id, $note ) { $this->recurring_calls[] = array( $id, $admin_id, $note ); return $this->approve_result; }
	public function reconcile_commit_outcome( $target, $admin_id ) { $this->reconcile_calls[] = array( $target, $admin_id ); return $this->reconcile_result; }
}

final class SquarePaymentsAdminTest extends TestCase {
	private $wpdb;
	private $attempt_ids = array();
	private $attempt_uuids = array();
	private $contract_ids = array();
	private $non_admin_user_id;
	private $stub;
	private $initial_service_filter;
	private $recurring_service_filter;
	private $die_handler_filter;
	private $approval_validation_stub;
	private $approval_service_stub;
	private $approval_validation_filter;
	private $approval_service_filter;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		wp_set_current_user( 1 );
		$this->non_admin_user_id = wp_insert_user(
			array(
				'user_login' => 'square-admin-test-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => 'square-admin-test-' . wp_generate_password( 8, false ) . '@example.test',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $this->non_admin_user_id ) );
		$this->stub = new Egov_Law_Monitor_Admin_Square_Read_Service_Stub();
		$this->stub->result = array(
			'status' => 'reconciliation_required', 'reason_code' => 'billing_period_provenance_unavailable',
			'payment_evidence' => array( 'status' => 'confirmed', 'confirmed' => true, 'payment_id' => 'mock-payment-id', 'order_id' => 'mock-order-id', 'amount' => 500, 'currency' => 'JPY' ),
			'review_status' => 'required', 'invoice_scope' => array( 'selection' => 'latest_invoice_only', 'invoice_total_count' => 2, 'checked_invoice_count' => 1, 'unchecked_invoice_count' => 1 ),
			'billing_period' => array( 'start' => null, 'end' => null, 'verified' => false, 'evidence_code' => 'period_provenance_not_recorded' ),
			'subscription' => array( 'id' => 'mock-subscription-id' ), 'invoice' => array( 'id' => 'mock-invoice-id' ),
		);
		$this->initial_service_filter = function ( $service ) { return $this->stub; };
		$this->recurring_service_filter = function ( $service ) { return $this->stub; };
		$this->approval_validation_stub = new Egov_Law_Monitor_Admin_Square_Approval_Validation_Stub();
		$this->approval_service_stub = new Egov_Law_Monitor_Admin_Square_Approval_Service_Stub();
		$this->approval_validation_filter = function ( $service ) { return $this->approval_validation_stub; };
		$this->approval_service_filter = function ( $service ) { return $this->approval_service_stub; };
		add_filter( 'egov_law_monitor_admin_square_initial_read_service', $this->initial_service_filter );
		add_filter( 'egov_law_monitor_admin_square_recurring_read_service', $this->recurring_service_filter );
		add_filter( 'egov_law_monitor_admin_square_approval_validation_service', $this->approval_validation_filter );
		add_filter( 'egov_law_monitor_admin_square_approval_service', $this->approval_service_filter );
		$this->insert_attempt();
		$this->insert_contract();
		$this->approval_validation_stub->result = $this->eligible_initial_validation();
		do_action( 'admin_menu' );
	}

	protected function tearDown(): void {
		remove_filter( 'egov_law_monitor_admin_square_initial_read_service', $this->initial_service_filter );
		remove_filter( 'egov_law_monitor_admin_square_recurring_read_service', $this->recurring_service_filter );
		remove_filter( 'egov_law_monitor_admin_square_approval_validation_service', $this->approval_validation_filter );
		remove_filter( 'egov_law_monitor_admin_square_approval_service', $this->approval_service_filter );
		if ( null !== $this->die_handler_filter ) {
			remove_filter( 'wp_die_handler', $this->die_handler_filter );
		}
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			foreach ( $this->contract_ids as $id ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $id ), array( '%d' ) );
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $id ), array( '%d' ) );
			}
			foreach ( $this->attempt_uuids as $uuid ) {
				$this->wpdb->delete( $this->wpdb->prefix . 'egov_law_checkout_attempts', array( 'attempt_uuid' => $uuid ), array( '%s' ) );
			}
		}
		$_POST = array();
		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );
		if ( ! empty( $this->non_admin_user_id ) && ! is_wp_error( $this->non_admin_user_id ) ) {
			wp_delete_user( (int) $this->non_admin_user_id );
		}
		parent::tearDown();
	}

	public function test_initial_list_contains_initial_and_recurring_rows_without_square_calls(): void {
		$factory_calls = 0;
		$counter = function ( $service ) use ( &$factory_calls ) { ++$factory_calls; return $this->stub; };
		add_filter( 'egov_law_monitor_admin_square_initial_read_service', $counter );
		add_filter( 'egov_law_monitor_admin_square_recurring_read_service', $counter );
		$html = $this->render_page();
		remove_filter( 'egov_law_monitor_admin_square_initial_read_service', $counter );
		remove_filter( 'egov_law_monitor_admin_square_recurring_read_service', $counter );

		$this->assertStringContainsString( '初回', $html );
		$this->assertStringContainsString( '継続', $html );
		$this->assertStringContainsString( 'Square決済管理', $html );
		$this->assertSame( 0, $factory_calls );
		$this->assertSame( array(), $this->stub->attempt_calls );
		$this->assertSame( array(), $this->stub->contract_calls );
	}

	public function test_creating_attempt_without_order_is_listed_but_never_sent_to_read_service(): void {
		$attempt_id = $this->insert_attempt( 'creating', false );
		$html = $this->render_page();

		$this->assertStringContainsString( 'Square Order ID未記録のため照会できません。', $html );
		$this->assertStringContainsString( '初回', $html );
		$this->assertSame( 1, substr_count( $html, 'Square Order ID未記録のため照会できません。' ) );
		$this->assertSame( array(), $this->stub->attempt_calls );

		// A hand-crafted valid POST must be blocked by the server-side row check too.
		$this->post_inspection( 'initial', $attempt_id );
		$html = $this->render_page();
		$this->assertStringContainsString( 'Square Order IDが記録されていないため、決済情報を照会できません。', $html );
		$this->assertSame( array(), $this->stub->attempt_calls );
	}

	public function test_all_subscription_states_are_listed_and_only_exact_attempt_uuid_creates_relation(): void {
		$linked_attempt_id = $this->insert_attempt();
		$linked_uuid = end( $this->attempt_uuids );
		$active_contract_id = $this->insert_contract( $linked_uuid, 'active' );
		$paused_contract_id = $this->insert_contract( null, 'paused' );
		$canceled_contract_id = $this->insert_contract( null, 'canceled' );
		// An identical UUID alone is insufficient if the user/scope does not agree.
		$mismatched_attempt_id = $this->insert_attempt();
		$mismatched_uuid = end( $this->attempt_uuids );
		$mismatched_contract_id = $this->insert_contract( $mismatched_uuid, 'paused', 2 );

		$html = $this->render_page();
		$this->assertStringContainsString( '関連契約ID #' . $active_contract_id, $html );
		$this->assertStringContainsString( '関連申込ID #' . $linked_attempt_id, $html );
		$this->assertStringContainsString( 'paused', $html );
		$this->assertStringContainsString( 'canceled', $html );
		$this->assertStringNotContainsString( '関連契約ID #' . $mismatched_contract_id, $html );
		$this->assertStringNotContainsString( '関連申込ID #' . $mismatched_attempt_id, $html );
		$this->assertSame( array(), $this->stub->attempt_calls );
		$this->assertSame( array(), $this->stub->contract_calls );
	}

	public function test_square_payment_menu_is_registered_for_manage_options(): void {
		global $submenu;
		$pages = isset( $submenu['egov-law-monitor'] ) ? $submenu['egov-law-monitor'] : array();
		$slugs = array_map( static function ( $item ) { return $item[2] ?? ''; }, $pages );
		$this->assertContains( 'egov-law-monitor-square-payments', $slugs );
	}

	public function test_post_with_nonce_calls_only_the_requested_read_service_and_renders_safe_result(): void {
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array( $this->attempt_ids[0] ), $this->stub->attempt_calls );
		$this->assertSame( array(), $this->stub->contract_calls );
		$this->assertStringContainsString( '決済完了を確認', $html );
		$this->assertStringContainsString( '決済完了の確認とStandard利用期間・権限の承認は別の判断です。', $html );
		$this->assertStringContainsString( '利用期間候補', $html );
		$this->assertStringContainsString( '要確認', $html );
		$this->assertStringContainsString( '未確認: 1', $html );
	}

	public function test_initial_billing_inspection_displays_payment_link_period_candidate_and_change_evidence_separately(): void {
		$this->stub->result = array(
			'status' => 'review_required', 'reason_code' => 'initial_invoice_payment_link_unproven', 'review_status' => 'required',
			'initial_payment' => array( 'confirmed' => true, 'status' => 'confirmed', 'payment_id' => 'first-payment-id', 'order_id' => 'first-order-id', 'amount' => 500, 'currency' => 'JPY', 'payment_status' => 'COMPLETED', 'refund_status' => 'none_reported_at_lookup' ),
			'subscription_link' => array( 'status' => 'confirmed', 'subscription_id' => 'first-subscription-id', 'method' => 'local_contract_and_attempt_reference_chain' ),
			'initial_invoice' => array( 'id' => 'first-invoice-id', 'order_id' => 'first-order-id', 'status' => 'PAID' ),
			'billing_conditions' => array(
				'status' => 'confirmed',
				'subscription' => array( 'id' => 'first-subscription-id', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 31 ),
				'variation' => array( 'id' => 'standard-monthly-variation', 'phases' => array( array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ),
				'events' => array( 'complete' => true ),
			),
			'period_candidate' => array( 'status' => 'candidate', 'basis' => array( 'monthly_anchor', 'schedule_changes_confirmed_absent' ), 'billing_period' => array( 'start_date' => '2026-01-31', 'end_date_inclusive' => '2026-02-27', 'end_exclusive_date' => '2026-02-28', 'next_start_date' => '2026-02-28', 'timezone' => 'Asia/Tokyo' ) ),
			'automatic_approval_candidate' => false,
		);
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '初回決済', $html );
		$this->assertStringContainsString( 'first-payment-id', $html );
		$this->assertStringContainsString( 'first-invoice-id', $html );
		$this->assertStringContainsString( '照合済み', $html );
		$this->assertStringContainsString( 'MONTHLY / 500 JPY', $html );
		$this->assertStringContainsString( '変更なしを確認', $html );
		$this->assertStringContainsString( '2026-02-27（最終利用日）', $html );
		$this->assertStringContainsString( '2026-02-28', $html );
		$this->assertStringContainsString( 'Asia/Tokyo', $html );
		$this->assertStringContainsString( '31', $html );
		$this->assertStringContainsString( '候補を算出（未承認）', $html );
		$this->assertStringContainsString( '初回決済とSubscription Invoiceの関連付けを確認できません。', $html );
		$this->assertStringNotContainsString( '承認済み利用期間', $html );
	}

	public function test_recurring_inspection_displays_invoice_scope_candidates_and_not_found_period_as_review(): void {
		$this->stub->result = array(
			'status' => 'review_required', 'reason_code' => 'subscription_events_incomplete', 'review_status' => 'required',
			'payment_evidence' => array( 'status' => 'not_confirmed', 'confirmed' => false, 'invoice_results' => array(
				array( 'status' => 'payment_confirmed', 'reason_code' => 'invoice_order_payment_verified', 'payment_evidence' => 'confirmed', 'invoice' => array( 'id' => 'renewal-invoice-id', 'order_id' => 'renewal-order-id', 'amount' => 500, 'completed_amount' => 500, 'currency' => 'JPY' ), 'payment' => array( 'id' => 'renewal-payment-id', 'status' => 'COMPLETED' ) ),
			) ),
			'invoice_scope' => array( 'selection' => 'all_invoice_ids_newer_than_last_approved_invoice', 'baseline_invoice_id' => 'previous-approved-invoice', 'invoice_list_completeness' => 'square_subscription_invoice_ids_field_newest_first', 'invoice_total_count' => 3, 'requested_invoice_count' => 2, 'checked_invoice_count' => 1, 'unchecked_invoice_count' => 1 ),
			'contract' => array( 'id' => 7, 'subscription_id' => 'renewal-subscription-id' ),
			'subscription' => array( 'id' => 'renewal-subscription-id', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 15 ),
			'variation' => array( 'id' => 'monthly-plan', 'single_monthly_phase' => true, 'phases' => array( array( 'cadence' => 'MONTHLY', 'recurring_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ),
			'schedule_review' => array( 'state' => 'unknown', 'reason' => 'subscription_events_incomplete' ),
			'event_scope' => array( 'complete' => false ),
			'period_candidate' => array( 'status' => 'review_required', 'reason_code' => 'subscription_schedule_provenance_incomplete', 'billing_period' => array( 'start_date' => null, 'end_date_inclusive' => null ) ),
		);
		$this->post_inspection( 'recurring', $this->contract_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '継続決済', $html );
		$this->assertStringContainsString( 'previous-approved-invoice', $html );
		$this->assertStringContainsString( 'renewal-invoice-id', $html );
		$this->assertStringContainsString( 'renewal-payment-id', $html );
		$this->assertStringContainsString( '全体／確認／未確認', $html );
		$this->assertStringContainsString( 'Invoice範囲の完全性', $html );
		$this->assertStringContainsString( '未確認Invoiceあり（不完全）', $html );
		$this->assertStringContainsString( '不完全・要確認', $html );
		$this->assertStringContainsString( 'Subscriptionの変更履歴が不完全です。', $html );
		$this->assertStringContainsString( '利用期間候補', $html );
		$this->assertGreaterThanOrEqual( 1, substr_count( $html, '要確認' ) );
		$this->assertStringNotContainsString( '候補を算出（未承認）', $html );
	}

	public function test_unknown_reason_code_is_shown_as_review_required_and_all_result_values_are_escaped(): void {
		$this->stub->result = array(
			'status' => 'review_required', 'reason_code' => '<script>unknown_reason</script>', 'review_status' => 'required',
			'initial_payment' => array( 'confirmed' => false, 'payment_id' => '<img src=x onerror=alert(1)>', 'order_id' => 'local-order' ),
			'subscription_link' => array( 'status' => 'unconfirmed' ),
			'period_candidate' => array( 'status' => 'review_required', 'reason_code' => 'candidate_not_calculated', 'billing_period' => array() ),
		);
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '要確認（理由コード:', $html );
		$this->assertStringContainsString( '&lt;script&gt;unknown_reason&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<script>unknown_reason</script>', $html );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $html );
	}

	public function test_recurring_post_uses_contract_id_and_api_error_or_pending_is_not_success(): void {
		$this->stub->result['status'] = 'square_api_error';
		$this->stub->result['payment_evidence'] = array( 'status' => 'not_confirmed', 'confirmed' => false );
		$this->stub->result['reason_code'] = 'invoice_retrieval_failed';
		$this->post_inspection( 'recurring', $this->contract_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array( $this->contract_ids[0] ), $this->stub->contract_calls );
		$this->assertStringContainsString( '確認できず', $html );
		$this->assertStringContainsString( 'invoice_retrieval_failed', $html );
		$this->assertStringContainsString( '決済失敗を意味しません', $html );
		$this->assertStringNotContainsString( '決済完了を確認', $html );

		$this->stub->result['status'] = 'payment_pending';
		$this->stub->result['payment_evidence'] = array( 'status' => 'pending', 'confirmed' => false );
		$this->stub->result['reason_code'] = 'payment_not_completed';
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '決済処理中または未完了', $html );
		$this->assertStringNotContainsString( '決済完了を確認', $html );
	}

	public function test_nonce_and_positive_id_validation_prevent_service_calls(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'egov_square_payment_inspect' => '1', 'record_type' => 'initial', 'local_id' => (string) $this->attempt_ids[0], 'egov_square_payment_nonce' => 'bad' );
		$html = $this->render_page();
		$this->assertStringContainsString( 'リクエストを確認できませんでした', $html );
		$this->assertCount( 0, $this->stub->attempt_calls );

		$this->post_inspection( 'initial', '01' );
		$html = $this->render_page();
		$this->assertStringContainsString( 'リクエストを確認できませんでした', $html );
		$this->assertCount( 0, $this->stub->attempt_calls );
	}

	public function test_non_admin_is_denied(): void {
		$denied_messages = array();
		$this->die_handler_filter = function ( $handler ) use ( &$denied_messages ) {
			return function ( $message ) use ( &$denied_messages ) {
				$denied_messages[] = $message;
			};
		};
		add_filter( 'wp_die_handler', $this->die_handler_filter );

		// Confirm a real subscriber has no capability and cannot open the page.
		wp_set_current_user( (int) $this->non_admin_user_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->render_page();
		$this->assertCount( 1, $denied_messages );
		$this->assertCount( 0, $this->stub->attempt_calls );
		$this->assertCount( 0, $this->stub->contract_calls );

		// A valid nonce for the subscriber must not bypass the server-side capability check.
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$this->render_page();
		$this->assertCount( 2, $denied_messages );
		$this->assertCount( 0, $this->stub->attempt_calls );
		$this->assertCount( 0, $this->stub->contract_calls );

		$this->post_admin_action( 'approve', 'initial', $this->attempt_ids[0] );
		$this->render_page();
		$this->assertCount( 3, $denied_messages );
		$this->assertCount( 0, $this->approval_validation_stub->initial_calls );
		$this->assertCount( 0, $this->approval_service_stub->initial_calls );
		$this->assertCount( 0, $this->stub->attempt_calls );
		$this->assertCount( 0, $this->stub->contract_calls );
	}

	public function test_pagination_limits_rows_and_reports_multiple_pages(): void {
		for ( $i = 0; $i < 21; ++$i ) {
			$this->insert_attempt();
		}
		$html = $this->render_page();
		$this->assertStringContainsString( 'tablenav-pages', $html );
		$this->assertSame( 20, substr_count( $html, '決済情報を取得</button>' ) );
		$_GET['paged'] = '2';
		$second_page = $this->render_page();
		$this->assertSame( 3, substr_count( $second_page, '決済情報を取得</button>' ) );
	}

	public function test_inspection_does_not_change_attempt_contract_invoice_or_entitlement(): void {
		$attempt_before = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_ids[0] ), ARRAY_A );
		$contract_before = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_ids[0] ), ARRAY_A );
		$invoice_count_before = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" );
		$entitlement_before = egov_law_monitor_get_effective_entitlement( 1 );
		$this->post_inspection( 'recurring', $this->contract_ids[0] );
		$this->render_page();
		$this->assertSame( $attempt_before, $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_ids[0] ), ARRAY_A ) );
		$this->assertSame( $contract_before, $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_ids[0] ), ARRAY_A ) );
		$this->assertSame( $invoice_count_before, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" ) );
		$this->assertSame( $entitlement_before, egov_law_monitor_get_effective_entitlement( 1 ) );
		$this->post_inspection( 'initial', $this->attempt_ids[0] );
		$this->render_page();
		$this->assertSame( $attempt_before, $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_checkout_attempts WHERE id = %d", $this->attempt_ids[0] ), ARRAY_A ) );
		$this->assertSame( $contract_before, $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_ids[0] ), ARRAY_A ) );
		$this->assertSame( $invoice_count_before, (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}egov_law_subscription_invoices" ) );
		$this->assertSame( $entitlement_before, egov_law_monitor_get_effective_entitlement( 1 ) );
	}

	public function test_approval_validation_requires_explicit_post_and_only_eligible_results_show_final_action(): void {
		$_POST = array( 'square_admin_action' => 'validate', 'record_type' => 'initial', 'local_id' => (string) $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array(), $this->approval_validation_stub->initial_calls );
		$this->assertStringNotContainsString( 'この内容で承認する', $html );

		$this->post_admin_action( 'validate', 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array( $this->attempt_ids[0] ), $this->approval_validation_stub->initial_calls );
		$this->assertStringContainsString( '対象ユーザー', $html );
		$this->assertStringContainsString( 'candidate-payment', $html );
		$this->assertStringContainsString( '2026-12-30（最終利用日）', $html );
		$this->assertStringContainsString( 'Asia/Tokyo', $html );
		$this->assertStringContainsString( '月次課金アンカー', $html );
		$this->assertStringContainsString( 'この内容で承認する', $html );
		$this->assertSame( array(), $this->approval_service_stub->initial_calls );
	}

	public function test_ineligible_validation_has_no_approval_form_and_does_not_write(): void {
		$this->approval_validation_stub->result = array_merge( $this->eligible_initial_validation(), array( 'approval_eligible' => false, 'verification_status' => 'review_required', 'reason_codes' => array( 'initial_invoice_payment_link_unproven' ) ) );
		$before = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_ids[0] ), ARRAY_A );
		$this->post_admin_action( 'validate', 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '通常承認できません', $html );
		$this->assertStringContainsString( '初回決済とSubscription Invoiceの関連付けを確認できません。', $html );
		$this->assertStringNotContainsString( 'この内容で承認する', $html );
		$this->assertSame( $before, $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->wpdb->prefix}egov_law_subscriptions WHERE id = %d", $this->contract_ids[0] ), ARRAY_A ) );
	}

	public function test_final_approval_post_sends_only_local_target_and_sanitized_optional_note(): void {
		$this->approval_service_stub->approve_result = array( 'status' => 'approved', 'contract_id' => $this->contract_ids[0], 'period_start_utc' => '2026-11-07 00:00:00', 'period_end_utc_exclusive' => '2026-12-07 00:00:00' );
		$this->post_admin_action( 'approve', 'initial', $this->attempt_ids[0], array( 'admin_note' => "確認済み <script>alert(1)</script>\n根拠" ) );
		$html = $this->render_page();
		$this->assertSame( array( array( $this->attempt_ids[0], 1, "確認済み \n根拠" ) ), $this->approval_service_stub->initial_calls );
		$this->assertStringContainsString( '管理者承認が完了しました', $html );
		$this->assertStringContainsString( '現在の権限', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Free', $html );
	}

	public function test_recurring_validation_and_approval_use_only_local_contract_id(): void {
		$this->approval_validation_stub->result = array(
			'target_type' => 'recurring', 'local_attempt_id' => null, 'local_contract_id' => $this->contract_ids[0],
			'verification_status' => 'validation_passed', 'approval_eligible' => true, 'approved' => false,
			'payment_evidence' => array( 'confirmed' => true, 'payment_id' => 'recurring-payment', 'invoice_id' => 'recurring-invoice', 'amount' => 500, 'currency' => 'JPY' ),
			'subscription_link' => array( 'status' => 'identified_from_local_contract', 'subscription_id' => 'local-subscription' ),
			'invoice_verification' => array( 'verified' => true, 'invoice_id' => 'recurring-invoice' ),
			'period_candidate' => array( 'status' => 'candidate', 'period_type' => 'renewal', 'start_date' => '2026-12-01', 'end_date_inclusive' => '2026-12-30', 'end_exclusive_date' => '2026-12-31', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1 ),
			'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1,
		);
		$this->post_admin_action( 'validate', 'recurring', $this->contract_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array( $this->contract_ids[0] ), $this->approval_validation_stub->recurring_calls );
		$this->assertStringContainsString( '継続決済', $html );
		$this->assertStringContainsString( 'この内容で承認する', $html );
		$this->assertSame( array(), $this->approval_service_stub->recurring_calls );

		$this->approval_service_stub->approve_result = array( 'status' => 'approved', 'contract_id' => $this->contract_ids[0], 'period_start_utc' => '2026-12-01 00:00:00', 'period_end_utc_exclusive' => '2027-01-01 00:00:00' );
		$this->post_admin_action( 'approve', 'recurring', $this->contract_ids[0] );
		$html = $this->render_page();
		$this->assertSame( array( array( $this->contract_ids[0], 1, '' ) ), $this->approval_service_stub->recurring_calls );
		$this->assertStringContainsString( '管理者承認が完了しました', $html );
	}

	public function test_invalid_nonce_and_get_request_cannot_approve(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'square_admin_action' => 'approve', 'record_type' => 'initial', 'local_id' => (string) $this->attempt_ids[0], 'square_admin_approval_nonce' => 'invalid' );
		$html = $this->render_page();
		$this->assertStringContainsString( 'リクエストを確認できませんでした', $html );
		$this->assertSame( array(), $this->approval_service_stub->initial_calls );
		$this->post_admin_action( 'approve', 'initial', $this->attempt_ids[0] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->render_page();
		$this->assertSame( array(), $this->approval_service_stub->initial_calls );
	}

	public function test_commit_unknown_reconciliation_never_automatically_reapproves(): void {
		$target = array( 'type' => 'initial', 'local_id' => $this->attempt_ids[0], 'contract_id' => $this->contract_ids[0], 'invoice_row_id' => 81, 'review_id' => 82 );
		$this->approval_service_stub->approve_result = new WP_Error( 'square_admin_approval_commit_outcome_unknown', 'unknown', array( 'reconciliation_target' => $target ) );
		$this->post_admin_action( 'approve', 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '承認結果を確認できません。再確認してください。', $html );
		$this->assertStringContainsString( '承認結果を再確認', $html );
		$this->assertSame( 1, count( $this->approval_service_stub->initial_calls ) );

		$this->approval_service_stub->reconcile_result = array( 'status' => 'inconsistent', 'reason_code' => 'approval_records_do_not_match' );
		$this->post_admin_action( 'reconcile', 'initial', $this->attempt_ids[0] );
		$html = $this->render_page();
		$this->assertStringContainsString( '再照合結果：不整合があります', $html );
		$this->assertStringContainsString( '同じ承認結果を再確認', $html );
		$this->assertSame( 1, count( $this->approval_service_stub->initial_calls ) );
		$this->assertSame( 1, count( $this->approval_service_stub->reconcile_calls ) );
	}

	public function test_reconciliation_statuses_are_displayed_without_approval_retry(): void {
		$target = array( 'type' => 'recurring', 'local_id' => $this->contract_ids[0], 'contract_id' => $this->contract_ids[0], 'invoice_row_id' => 81, 'review_id' => 82 );
		set_transient( egov_law_monitor_admin_square_reconciliation_transient_key( 'recurring', $this->contract_ids[0], 1 ), $target, HOUR_IN_SECONDS );
		foreach ( array( 'approved' => '再照合結果：承認済み', 'not_approved' => '再照合結果：未承認', 'unavailable' => '再照合結果：確認不能' ) as $status => $label ) {
			set_transient( egov_law_monitor_admin_square_reconciliation_transient_key( 'recurring', $this->contract_ids[0], 1 ), $target, HOUR_IN_SECONDS );
			$this->approval_service_stub->reconcile_result = array( 'status' => $status, 'reason_code' => 'test_reason' );
			$this->post_admin_action( 'reconcile', 'recurring', $this->contract_ids[0] );
			$html = $this->render_page();
			$this->assertStringContainsString( $label, $html );
		}
		$this->assertCount( 3, $this->approval_service_stub->reconcile_calls );
		$this->assertCount( 0, $this->approval_service_stub->recurring_calls );
	}

	private function insert_attempt( $status = 'pending', $include_order = true ): int {
		$uuid = wp_generate_uuid4();
		$this->attempt_uuids[] = $uuid;
		$data = array(
			'attempt_uuid' => $uuid, 'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'admin-square-merchant',
			'square_location_id' => 'admin-square-location', 'plan_code' => 'standard', 'catalog_item_variation_id' => 'admin-item',
			'subscription_plan_variation_id' => 'admin-plan', 'status' => $status, 'idempotency_key' => wp_generate_uuid4(),
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		);
		if ( $include_order ) {
			$data['square_order_id'] = 'admin-order-' . wp_generate_uuid4();
		}
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_checkout_attempts', $data ) );
		$this->attempt_ids[] = (int) $this->wpdb->insert_id;
		return (int) $this->wpdb->insert_id;
	}

	private function insert_contract( $attempt_uuid = null, $status = 'pending', $user_id = 1 ): int {
		$this->assertSame( 1, $this->wpdb->insert( $this->wpdb->prefix . 'egov_law_subscriptions', array(
			'user_id' => $user_id, 'environment' => 'sandbox', 'merchant_id' => 'admin-square-merchant', 'square_location_id' => 'admin-square-location',
			'square_customer_id' => 'admin-square-customer', 'square_subscription_id' => 'admin-square-subscription-' . wp_generate_uuid4(), 'square_plan_variation_id' => 'admin-plan',
			'checkout_attempt_uuid' => $attempt_uuid, 'plan_code' => 'standard', 'internal_status' => $status, 'square_status' => 'PENDING', 'initial_payment_status' => 'unknown',
			'current_slot' => null, 'sync_state' => 'unknown', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
		) ) );
		$this->contract_ids[] = (int) $this->wpdb->insert_id;
		return (int) $this->wpdb->insert_id;
	}

	private function post_inspection( $type, $id ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array(
			'egov_square_payment_inspect' => '1',
			'record_type' => $type,
			'local_id' => (string) $id,
			'egov_square_payment_nonce' => wp_create_nonce( 'egov_square_payment_inspect_' . $type . '_' . $id ),
		);
	}

	private function post_admin_action( $action, $type, $id, $extra = array() ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array_merge( array(
			'square_admin_action' => $action,
			'record_type' => $type,
			'local_id' => (string) $id,
			'square_admin_approval_nonce' => wp_create_nonce( 'egov_square_approval_' . $action . '_' . $type . '_' . $id ),
		), $extra );
	}

	private function eligible_initial_validation(): array {
		return array(
			'target_type' => 'initial', 'local_attempt_id' => $this->attempt_ids[0], 'local_contract_id' => $this->contract_ids[0],
			'verification_status' => 'validation_passed', 'approval_eligible' => true, 'approved' => false,
			'payment_evidence' => array( 'confirmed' => true, 'payment_id' => 'candidate-payment', 'invoice_id' => 'candidate-invoice', 'amount' => 500, 'currency' => 'JPY' ),
			'subscription_link' => array( 'status' => 'confirmed', 'subscription_id' => 'candidate-subscription' ),
			'invoice_verification' => array( 'verified' => true, 'invoice_id' => 'candidate-invoice' ),
			'period_candidate' => array( 'status' => 'candidate', 'start_date' => '2026-12-01', 'end_date_inclusive' => '2026-12-30', 'end_exclusive_date' => '2026-12-31', 'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1 ),
			'timezone' => 'Asia/Tokyo', 'monthly_billing_anchor_date' => 1,
			'verified_subscription' => array( 'id' => 'candidate-subscription', 'status' => 'ACTIVE' ),
			'verified_invoice' => array( 'id' => 'candidate-invoice' ),
		);
	}

	private function render_page(): string {
		ob_start();
		egov_law_monitor_render_admin_square_payments();
		return (string) ob_get_clean();
	}
}
