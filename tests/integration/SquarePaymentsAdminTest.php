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
		add_filter( 'egov_law_monitor_admin_square_initial_read_service', $this->initial_service_filter );
		add_filter( 'egov_law_monitor_admin_square_recurring_read_service', $this->recurring_service_filter );
		$this->insert_attempt();
		$this->insert_contract();
		do_action( 'admin_menu' );
	}

	protected function tearDown(): void {
		remove_filter( 'egov_law_monitor_admin_square_initial_read_service', $this->initial_service_filter );
		remove_filter( 'egov_law_monitor_admin_square_recurring_read_service', $this->recurring_service_filter );
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
		$this->assertStringContainsString( '未確認（期間の根拠なし）', $html );
		$this->assertStringContainsString( '未確認: 1', $html );
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

	private function render_page(): string {
		ob_start();
		egov_law_monitor_render_admin_square_payments();
		return (string) ob_get_clean();
	}
}
