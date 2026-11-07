<?php

use PHPUnit\Framework\TestCase;

final class AISummaryUsageApiTest extends TestCase {
	private $user_id;
	private $subscription_ids = array();
	private $previous_user_id;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		egov_law_monitor_create_tables();
		foreach ( array( 'law_watch_settings', 'egov_law_ai_summaries', 'egov_law_ai_summary_history' ) as $table_suffix ) {
			$table = $wpdb->prefix . $table_suffix;
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ),
				'Expected test table was not created: ' . $table
			);
		}
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$suffix = wp_generate_password( 12, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'usage-api-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'usage-api-' . $suffix . '@example.org',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->user_id = (int) $user_id;
		$this->previous_user_id = get_current_user_id();
		wp_set_current_user( $this->user_id );
	}

	protected function tearDown(): void {
		global $wpdb;

		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$wpdb->delete( $wpdb->prefix . 'egov_law_ai_summary_usage', array( 'user_id' => $this->user_id ), array( '%d' ) );
			foreach ( $this->subscription_ids as $subscription_id ) {
				$wpdb->delete( $wpdb->prefix . 'egov_law_square_admin_reviews', array( 'contract_id' => $subscription_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'egov_law_subscription_invoices', array( 'subscription_id' => $subscription_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'egov_law_subscriptions', array( 'id' => $subscription_id ), array( '%d' ) );
			}
			if ( $this->user_id ) {
				wp_delete_user( $this->user_id );
			}
		}

		wp_set_current_user( $this->previous_user_id );
		parent::tearDown();
	}

	public function test_legacy_standard_meta_without_contract_returns_free_lifetime_usage(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		update_user_meta( $this->user_id, 'egov_law_monitor_ai_summary_trial_usage', 1 );
		$this->reserve( 'free', 'free-ledger-use' );

		$response = $this->request();
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'free', $data['effectivePlan'] );
		$this->assertSame( 'lifetime', $data['usagePeriod'] );
		$this->assertSame( 3, $data['limit'] );
		$this->assertSame( 2, $data['usageCount'] );
		$this->assertSame( 1, $data['remaining'] );
		$this->assertFalse( $data['alreadyUsed'] );
		$this->assertSame( 0, $data['historyId'] );
	}

	public function test_legacy_free_meta_with_standard_contract_counts_only_current_month_standard_uses(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		update_user_meta( $this->user_id, 'egov_law_monitor_ai_summary_trial_usage', 2 );
		$this->add_active_standard_subscription();
		$this->reserve( 'free', 'free-ledger-before-upgrade' );
		$this->reserve( 'standard', 'standard-current-month-use' );

		$response = $this->request();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'standard', $response->get_data()['effectivePlan'] );
		$this->assertSame( 'calendar_month', $response->get_data()['usagePeriod'] );
		$this->assertSame( 100, $response->get_data()['limit'] );
		$this->assertSame( 1, $response->get_data()['usageCount'] );
		$this->assertSame( 99, $response->get_data()['remaining'] );
	}

	public function test_free_ledger_error_is_not_reported_as_an_exhausted_quota(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->assertUsageLedgerErrorResponse();
	}

	public function test_standard_ledger_error_is_not_reported_as_an_exhausted_quota(): void {
		$this->add_active_standard_subscription();
		$this->assertUsageLedgerErrorResponse();
	}

	public function test_entitlement_database_error_is_distinct_from_usage_error(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$response = $this->request();
			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 'ai_summary_entitlement_unavailable', $response->get_data()['code'] );
			$this->assertSame( 'AI要約の利用状況を確認できません。時間をおいて再度お試しください。', $response->get_data()['message'] );
			$this->assertArrayNotHasKey( 'remaining', $response->get_data() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}

	public function test_unauthenticated_request_is_rejected(): void {
		wp_set_current_user( 0 );
		$response = $this->request();
		$this->assertSame( 401, $response->get_status() );
		wp_set_current_user( $this->user_id );
	}

	public function test_active_reservations_are_included_in_usage_response(): void {
		$this->add_active_standard_subscription();
		$this->reserve( 'standard', 'standard-reserved-use' );

		$response = $this->request();
		$this->assertSame( 1, $response->get_data()['usageCount'] );
		$this->assertSame( 99, $response->get_data()['remaining'] );
	}

	private function request() {
		return rest_do_request( new WP_REST_Request( 'GET', '/egov-law-monitor/v1/ai-summary-usage' ) );
	}

	private function reserve( $plan_code, $key ) {
		$result = egov_law_monitor_ai_usage_service()->reserve(
			$this->user_id,
			$plan_code,
			hash( 'sha256', $key . '-' . $this->user_id )
		);
		$this->assertIsArray( $result );
		return $result;
	}

	private function add_active_standard_subscription(): void {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'egov_law_subscriptions',
			array(
				'user_id'                => $this->user_id,
				'environment'            => 'production',
				'merchant_id'            => 'usage-api-merchant',
				'square_customer_id'     => 'usage-api-customer-' . $this->user_id,
				'square_subscription_id' => 'usage-api-subscription-' . $this->user_id,
				'plan_code'              => 'standard',
				'internal_status'        => 'active',
				'square_status'          => 'ACTIVE',
				'initial_payment_status' => 'succeeded',
				'current_slot'           => 1,
				'current_period_start'   => '2026-01-01 00:00:00',
				'current_period_end'     => '2030-01-01 00:00:00',
				'sync_state'             => 'ok',
				'created_at'             => '2026-01-01 00:00:00',
				'updated_at'             => '2026-01-01 00:00:00',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		$this->assertSame( 1, $result );
		$subscription_id = (int) $wpdb->insert_id;
		$this->subscription_ids[] = $subscription_id;
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'egov_law_square_admin_reviews', array(
			'user_id' => $this->user_id, 'environment' => 'production', 'merchant_id' => 'usage-api-merchant', 'contract_id' => $subscription_id,
			'square_payment_id' => 'usage-api-payment-' . $this->user_id, 'action_type' => 'approve',
			'approved_period_start' => '2026-01-01 00:00:00', 'approved_period_end' => '2030-01-01 00:00:00',
			'subscription_timezone' => 'UTC', 'monthly_billing_anchor_date' => 1, 'evidence_code' => 'usage_api_test',
			'reviewer_user_id' => 1, 'recorded_at' => current_time( 'mysql', true ),
		) ) );
	}

	private function assertUsageLedgerErrorResponse(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$wpdb->query( "DROP TABLE `{$table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$response = $this->request();
			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 'ai_summary_usage_unavailable', $response->get_data()['code'] );
			$this->assertSame( 'AI要約の利用状況を確認できません。時間をおいて再度お試しください。', $response->get_data()['message'] );
			$this->assertArrayNotHasKey( 'remaining', $response->get_data() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}
}
