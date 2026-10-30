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

	public function test_current_contract_sync_state_is_reflected_in_entitlement_result(): void {
		global $wpdb;
		$id    = $this->add_subscription( 'production', array( 'current_period_end' => '2026-11-01 00:00:00' ) );
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$cases = array(
			'ok'      => array( 'standard', 'active_paid_period', false ),
			'error'   => array( 'standard', 'sync_error_within_verified_period', true ),
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
		$this->assertSame( 'standard', $grace_result['effective_plan'] );
		$this->assertSame( 'sync_error_within_payment_grace', $grace_result['reason_code'] );
		$this->assertTrue( $grace_result['recheck_required'] );
	}

	public function test_payment_grace_boundary_is_passed_to_the_pure_policy(): void {
		$this->add_subscription(
			'production',
			array(
				'first_payment_failed_at' => '2026-10-22 12:00:00',
				'grace_ends_at'           => '2026-10-29 12:00:00',
			)
		);
		$this->add_invoice( $this->subscription_ids[0], 'production', array( 'payment_status' => 'failed' ) );
		$result = $this->service( 'production' )->resolve_for_user( $this->user_id, $this->fixed_time( '2026-10-29T12:00:00Z' ) );
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
		$data = array_merge(
			array(
				'user_id'                  => $this->user_id,
				'environment'              => $environment,
				'merchant_id'              => 'merchant-test',
				'square_customer_id'       => 'customer-' . count( $this->subscription_ids ),
				'square_subscription_id'   => 'subscription-' . count( $this->subscription_ids ),
				'plan_code'                => 'standard',
				'internal_status'          => 'active',
				'initial_payment_status'   => 'succeeded',
				'sync_state'               => 'ok',
				'current_slot'             => 1,
				'current_period_end'       => '2026-11-01 00:00:00',
				'created_at'               => '2026-01-01 00:00:00',
				'updated_at'               => '2026-01-01 00:00:00',
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $table, $data ) );
		$id = (int) $wpdb->insert_id;
		$this->subscription_ids[] = $id;
		return $id;
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
