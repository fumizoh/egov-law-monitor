<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/watch-api.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/admin/admin-users.php';
require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/law-search-page.php';

final class WatchApiEntitlementTest extends TestCase {
	private $user_id;
	private $subscription_ids = array();
	private $previous_user_id;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		egov_law_monitor_create_tables();
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$suffix = wp_generate_password( 12, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'watch-entitlement-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'watch-entitlement-' . $suffix . '@example.org',
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
			$watch_table = $wpdb->prefix . 'law_watch_settings';
			$wpdb->delete( $watch_table, array( 'user_id' => $this->user_id ), array( '%d' ) );
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

	public function test_legacy_standard_meta_without_contract_uses_free_limit(): void {
		global $wpdb;
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->insert_active_watches( 4 );

		$fifth = $this->request( 'POST', '/egov-law-monitor/v1/watches', array( 'keyword' => 'law-five' ) );
		$this->assertSame( 200, $fifth->get_status() );
		$this->assertSame( 5, (int) $wpdb->get_var( $this->active_count_sql() ) );

		$sixth = $this->request( 'POST', '/egov-law-monitor/v1/watches', array( 'keyword' => 'law-six' ) );
		$this->assertSame( 409, $sixth->get_status() );
		$this->assertSame( 'watch_limit_reached', $sixth->get_data()['code'] );
	}

	public function test_search_page_uses_standard_entitlement_when_legacy_meta_is_free(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		$this->add_active_standard_subscription();
		$this->insert_active_watches( 9 );
		$this->insert_watch( 'law-paused', 0 );

		$html = egov_law_monitor_render_search_page();
		$text = $this->search_page_text( $html );

		$this->assertStringContainsString( 'ご利用プラン： Standard', $text );
		$this->assertStringContainsString( '有効：9 / 10件', $text );
		$this->assertStringContainsString( '登録可能：1件', $text );
		$this->assertStringContainsString( 'egov-law-resume-button', $html );
		$this->assertStringContainsString( 'egov-law-watch-form', $html );
	}

	public function test_search_page_uses_free_entitlement_when_legacy_meta_is_standard(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->insert_active_watches( 4 );
		$this->insert_watch( 'law-paused', 0 );

		$html = egov_law_monitor_render_search_page();
		$text = $this->search_page_text( $html );

		$this->assertStringContainsString( 'ご利用プラン： Free', $text );
		$this->assertStringContainsString( '有効：4 / 5件', $text );
		$this->assertStringContainsString( '登録可能：1件', $text );
		$this->assertStringContainsString( 'egov-law-resume-button', $html );
		$this->assertStringContainsString( 'egov-law-watch-form', $html );
	}

	public function test_search_page_hides_registration_and_resume_over_limit_but_keeps_list_and_delete(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->insert_active_watches( 6 );
		$this->insert_watch( 'law-paused', 0 );

		$html = egov_law_monitor_render_search_page();
		$text = $this->search_page_text( $html );

		$this->assertStringContainsString( 'ご利用プラン： Free', $text );
		$this->assertStringContainsString( '有効：6 / 5件', $text );
		$this->assertStringContainsString( '登録可能：0件', $text );
		$this->assertStringContainsString( 'egov-law-watch-limit-reached', $html );
		$this->assertStringNotContainsString( 'egov-law-resume-button', $html );
		$this->assertStringNotContainsString( 'id="egov-law-watch-form"', $html );
		$this->assertStringContainsString( 'law-1', $html );
		$this->assertStringContainsString( 'egov-law-unwatch-button', $html );
	}

	public function test_search_page_entitlement_error_blocks_add_resume_but_keeps_existing_watch_controls(): void {
		global $wpdb;
		$this->insert_active_watches( 1 );
		$this->insert_watch( 'law-paused', 0 );
		$subscription_table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$subscription_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$html = egov_law_monitor_render_search_page();
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$this->assertStringContainsString( 'プラン情報を確認できません', $html );
		$this->assertStringContainsString( 'egov-law-watch-entitlement-error', $html );
		$this->assertStringNotContainsString( 'egov-law-resume-button', $html );
		$this->assertStringNotContainsString( 'id="egov-law-watch-form"', $html );
		$this->assertStringContainsString( 'law-1', $html );
		$this->assertStringContainsString( 'egov-law-unwatch-button', $html );

		$delete = $this->request( 'DELETE', '/egov-law-monitor/v1/watches/law-1' );
		$this->assertSame( 200, $delete->get_status() );
		$this->assertSame( 0, $this->active_count() );
	}

	private function search_page_text( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
	}

	public function test_standard_contract_allows_ten_watches_and_rejects_the_eleventh(): void {
		global $wpdb;
		$this->add_active_standard_subscription();
		$this->insert_active_watches( 9 );

		$tenth = $this->request( 'POST', '/egov-law-monitor/v1/watches', array( 'keyword' => 'law-ten' ) );
		$this->assertSame( 200, $tenth->get_status() );
		$this->assertSame( 10, (int) $wpdb->get_var( $this->active_count_sql() ) );

		$eleventh = $this->request( 'POST', '/egov-law-monitor/v1/watches', array( 'keyword' => 'law-eleven' ) );
		$this->assertSame( 409, $eleventh->get_status() );
		$this->assertSame( 'watch_limit_reached', $eleventh->get_data()['code'] );
	}

	public function test_entitlement_error_rejects_registration_and_resume_without_leaking_details(): void {
		global $wpdb;
		$watch_table = $wpdb->prefix . 'law_watch_settings';
		$this->insert_watch( 'law-paused', 0 );
		$subscription_table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$subscription_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$registration = $this->request( 'POST', '/egov-law-monitor/v1/watches', array( 'keyword' => 'law-new' ) );
			$this->assertSame( 503, $registration->get_status() );
			$this->assertSame( 'watch_entitlement_unavailable', $registration->get_data()['code'] );
			$this->assertSame( 'プラン情報を確認できません。時間をおいて再度お試しください。', $registration->get_data()['message'] );
			$this->assertStringNotContainsString( 'entitlement_database_error', $registration->get_data()['message'] );

			$resume = $this->request( 'POST', '/egov-law-monitor/v1/watches/law-paused/resume' );
			$this->assertSame( 503, $resume->get_status() );
			$this->assertSame( 'watch_entitlement_unavailable', $resume->get_data()['code'] );
			$this->assertSame(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare( "SELECT is_active FROM {$watch_table} WHERE user_id = %d AND keyword = %s", $this->user_id, 'law-paused' )
				)
			);
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}
	}

	public function test_list_and_delete_routes_do_not_apply_plan_limit_or_change_remaining_watches(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		$this->insert_active_watches( 7 );

		$list = $this->request( 'GET', '/egov-law-monitor/v1/watches' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 7, $list->get_data() );
		$this->assertSame( 7, $this->active_count() );

		$delete = $this->request( 'DELETE', '/egov-law-monitor/v1/watches/law-1' );
		$this->assertSame( 200, $delete->get_status() );
		$this->assertSame( 6, $this->active_count() );
	}

	public function test_admin_save_does_not_stop_watches_when_legacy_free_meta_has_standard_entitlement(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		$this->add_active_standard_subscription();
		$this->insert_active_watches( 6 );

		$entitlement = egov_law_monitor_get_effective_entitlement( $this->user_id );
		$this->assertFalse( is_wp_error( $entitlement ) );
		$this->assertSame( 'standard', $entitlement['effective_plan'] );

		$this->save_admin_user_settings();

		$this->assertSame( 6, $this->active_count() );
		$this->assertSame( 1, (int) get_user_meta( $this->user_id, 'egov_law_monitor_notifications', true ) );
		$this->assertSame( 'free', get_user_meta( $this->user_id, 'egov_law_monitor_plan', true ) );
	}

	public function test_admin_save_does_not_change_effective_plan_or_stop_existing_watches(): void {
		global $wpdb;
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->add_active_standard_subscription();
		$this->insert_active_watches( 6 );
		$standard = egov_law_monitor_get_effective_entitlement( $this->user_id );
		$this->assertFalse( is_wp_error( $standard ) );
		$this->assertSame( 'standard', $standard['effective_plan'] );

		$wpdb->update(
			$wpdb->prefix . 'egov_law_subscriptions',
			array(
				'internal_status'    => 'canceled',
				'square_status'       => 'CANCELED',
				'current_period_end' => '2020-01-01 00:00:00',
			),
			array( 'id' => $this->subscription_ids[0] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		$free = egov_law_monitor_get_effective_entitlement( $this->user_id );
		$this->assertFalse( is_wp_error( $free ) );
		$this->assertSame( 'free', $free['effective_plan'] );

		$this->save_admin_user_settings();

		$this->assertSame( 6, $this->active_count() );
		$this->assertSame( 'standard', get_user_meta( $this->user_id, 'egov_law_monitor_plan', true ) );
		$after_save = egov_law_monitor_get_effective_entitlement( $this->user_id );
		$this->assertFalse( is_wp_error( $after_save ) );
		$this->assertSame( 'free', $after_save['effective_plan'] );
	}

	public function test_admin_display_uses_standard_entitlement_and_standard_usage_ledger(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'free' );
		$this->add_active_standard_subscription();
		$this->insert_usage_record( 'standard', 'admin-standard-usage' );

		$html = $this->render_admin_users_as_admin( array() );
		$row  = $this->admin_user_row( $html );

		$this->assertStringContainsString( 'Standard', $row );
		$this->assertStringContainsString( '今月 1 / 100回', $this->admin_row_text( $row ) );
		$this->assertStringNotContainsString( '<select', $row );
		$this->assertStringNotContainsString( 'name="plan"', $row );
	}

	public function test_admin_display_uses_free_entitlement_and_lifetime_usage_despite_legacy_standard_meta(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		update_user_meta( $this->user_id, 'egov_law_monitor_ai_summary_trial_usage', 1 );
		$this->insert_usage_record( 'free', 'admin-free-usage' );

		$html = $this->render_admin_users_as_admin( array() );
		$row  = $this->admin_user_row( $html );

		$this->assertStringContainsString( 'Free', $row );
		$this->assertStringContainsString( '生涯 2 / 3回', $this->admin_row_text( $row ) );
		$this->assertStringNotContainsString( '<select', $row );
	}

	public function test_admin_display_does_not_fall_back_to_free_when_entitlement_lookup_fails(): void {
		global $wpdb;
		$subscription_table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$subscription_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$html = $this->render_admin_users_as_admin( array() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$row = $this->admin_user_row( $html );
		$this->assertStringContainsString( '取得できません', $row );
		$this->assertStringContainsString( '利用状況を取得できません', $row );
		$this->assertStringNotContainsString( 'Free', $row );
		$this->assertStringNotContainsString( 'Standard', $row );
	}

	public function test_admin_display_does_not_show_zero_usage_when_ledger_lookup_fails(): void {
		global $wpdb;
		$this->add_active_standard_subscription();
		$usage_table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$wpdb->query( "DROP TABLE `{$usage_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$html = $this->render_admin_users_as_admin( array() );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$row = $this->admin_user_row( $html );
		$this->assertStringContainsString( 'Standard', $row );
		$this->assertStringContainsString( '利用状況を取得できません', $row );
		$this->assertStringNotContainsString( '/ 100回', $row );
	}

	public function test_monthly_history_delete_does_not_change_usage_ledger(): void {
		global $wpdb;
		$this->add_active_standard_subscription();
		$this->insert_usage_record( 'standard', 'admin-history-delete' );
		$history_id = egov_law_monitor_save_ai_summary_history(
			$this->user_id,
			'admin-history-delete-law',
			'履歴削除テスト法令',
			'2026-01-01',
			'admin-history-delete-data',
			'1',
			hash( 'sha256', 'admin-history-delete' ),
			'テスト要約',
			'テスト本文'
		);
		$this->assertNotFalse( $history_id );

		$html = $this->render_admin_users_as_admin(
			array(
				'egov_law_monitor_clear_ai_summary' => '1',
				'ai_summary_user_id'                => $this->user_id,
			)
		);

		$this->assertStringContainsString( '今月のAI要約利用履歴を1件クリアしました。', wp_strip_all_tags( $html ) );
		$this->assertSame( 0, $this->history_count() );
		$this->assertSame( 1, $this->usage_record_count() );
	}

	private function request( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function save_admin_user_settings() {
		$this->render_admin_users_as_admin(
			array(
				'egov_law_monitor_save_user' => '1',
				'user_id'                    => $this->user_id,
				'notifications'              => '1',
			)
		);
	}

	private function render_admin_users_as_admin( array $post ) {
		$admin_id = wp_insert_user(
			array(
				'user_login' => 'watch-admin-' . wp_generate_password( 12, false, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'watch-admin-' . wp_generate_password( 12, false, false ) . '@example.org',
				'role'       => 'administrator',
			)
		);
		$this->assertFalse( is_wp_error( $admin_id ) );

		$previous_user_id = get_current_user_id();
		$previous_post = $_POST;
		$previous_request = $_REQUEST;
		wp_set_current_user( (int) $admin_id );
		if ( isset( $post['egov_law_monitor_save_user'] ) ) {
			$post['egov_law_monitor_nonce'] = wp_create_nonce( 'egov_law_monitor_save_user' );
		}
		if ( isset( $post['egov_law_monitor_clear_ai_summary'] ) ) {
			$post['egov_law_monitor_clear_ai_summary_nonce'] = wp_create_nonce( 'egov_law_monitor_clear_ai_summary' );
		}
		$_POST = $post;
		$_REQUEST = $_POST;

		ob_start();
		try {
			egov_law_monitor_render_admin_users();
			return ob_get_contents();
		} finally {
			ob_end_clean();
			$_POST = $previous_post;
			$_REQUEST = $previous_request;
			wp_set_current_user( $previous_user_id );
			wp_delete_user( (int) $admin_id );
		}
	}

	private function admin_user_row( $html ) {
		$pattern = '/<tr>\s*<td>\s*' . preg_quote( (string) $this->user_id, '/' ) . '\s*<\/td>.*?<\/tr>/s';
		$this->assertSame( 1, preg_match( $pattern, $html, $matches ) );
		return $matches[0];
	}

	private function admin_row_text( $row ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $row ) ) );
	}

	private function insert_usage_record( $plan, $key ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$result = $wpdb->insert(
			$wpdb->prefix . 'egov_law_ai_summary_usage',
			array(
				'environment'            => 'production',
				'user_id'                => $this->user_id,
				'request_key'            => hash( 'sha256', $key ),
				'plan_code'              => $plan,
				'usage_month'             => 'standard' === $plan ? wp_date( 'Y-m-01' ) : null,
				'usage_status'           => 'committed',
				'reserved_at'            => $now,
				'committed_at'           => $now,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertSame( 1, $result );
	}

	private function usage_record_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_ai_summary_usage WHERE user_id = %d", $this->user_id ) );
	}

	private function history_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}egov_law_ai_summary_history WHERE user_id = %d", $this->user_id ) );
	}

	private function active_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $this->active_count_sql() );
	}

	private function active_count_sql() {
		global $wpdb;
		return $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}law_watch_settings WHERE user_id = %d AND is_active = 1", $this->user_id );
	}

	private function insert_active_watches( $count ) {
		for ( $index = 1; $index <= $count; ++$index ) {
			$this->insert_watch( 'law-' . $index, 1 );
		}
	}

	private function insert_watch( $keyword, $is_active ) {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'law_watch_settings',
			array(
				'user_id'    => $this->user_id,
				'keyword'    => $keyword,
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
				'is_active'  => $is_active,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
		$this->assertSame( 1, $result );
	}

	private function add_active_standard_subscription() {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'egov_law_subscriptions',
			array(
				'user_id'                => $this->user_id,
				'environment'            => 'production',
				'merchant_id'            => 'watch-test-merchant',
				'square_customer_id'     => 'watch-test-customer-' . $this->user_id,
				'square_subscription_id' => 'watch-test-subscription-' . $this->user_id,
				'plan_code'              => 'standard',
				'internal_status'        => 'active',
				'square_status'           => 'ACTIVE',
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
			'user_id' => $this->user_id, 'environment' => 'production', 'merchant_id' => 'watch-test-merchant', 'contract_id' => $subscription_id,
			'square_payment_id' => 'watch-test-payment-' . $this->user_id, 'action_type' => 'approve',
			'approved_period_start' => '2026-01-01 00:00:00', 'approved_period_end' => '2030-01-01 00:00:00',
			'subscription_timezone' => 'UTC', 'monthly_billing_anchor_date' => 1, 'evidence_code' => 'watch_entitlement_test',
			'reviewer_user_id' => 1, 'recorded_at' => current_time( 'mysql', true ),
		) ) );
	}
}
