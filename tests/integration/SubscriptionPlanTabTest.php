<?php

use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTabTest extends TestCase {
	private $user_id;
	private $checkout_service_filter;
	private $checkout_environment = 'sandbox';
	private $unallowed_user_id;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$this->user_id = 1;
		$this->assertNotFalse( get_userdata( $this->user_id ), "The WordPress test site's seeded administrator should be present." );
		wp_set_current_user( $this->user_id );

		$this->checkout_service_filter = function ( $service, $wpdb_instance ) {
			return new Egov_Law_Monitor_Square_Checkout_Service(
				new Egov_Law_Monitor_Checkout_Repository( $wpdb_instance ),
				new Egov_Law_Monitor_Square_Api_Client(
					array(
						'environment'                    => $this->checkout_environment,
						'access_token'                   => 'test-token',
						'merchant_id'                    => 'test-merchant',
						'location_id'                    => 'test-location',
						'catalog_item_variation_id'       => 'test-item-variation',
						'subscription_plan_variation_id'  => 'test-plan-variation',
					)
				)
			);
		};
		add_filter( 'egov_law_monitor_square_checkout_service_override', $this->checkout_service_filter, 10, 2 );
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_filter( 'egov_law_monitor_square_checkout_service_override', $this->checkout_service_filter, 10 );
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' && $this->user_id ) {
			$wpdb->delete( $wpdb->prefix . 'egov_law_checkout_attempts', array( 'user_id' => $this->user_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'egov_law_subscriptions', array( 'user_id' => $this->user_id ), array( '%d' ) );
			delete_user_meta( $this->user_id, 'egov_law_monitor_plan' );
			if ( $this->unallowed_user_id ) {
				$removed_plugin_delete_hook = function_exists( 'egov_law_monitor_delete_user_data' )
					&& remove_action( 'delete_user', 'egov_law_monitor_delete_user_data' );
				wp_delete_user( $this->unallowed_user_id );
				if ( $removed_plugin_delete_hook ) {
					add_action( 'delete_user', 'egov_law_monitor_delete_user_data' );
				}
			}
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_shortcode_renders_free_offer_despite_legacy_standard_meta_without_adding_userswp_tab(): void {
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		$this->assertTrue( shortcode_exists( 'egov_law_subscription_plan' ) );
		$tabs = apply_filters( 'uwp_account_available_tabs', array( 'existing-tab' => array( 'title' => '既存タブ' ) ) );
		$this->assertSame( array( 'existing-tab' => array( 'title' => '既存タブ' ) ), $tabs );

		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( '>Free</p>', $html );
		$this->assertStringContainsString( '月額500円（税込）', $html );
		$this->assertStringContainsString( '法令ウォッチ：キーワード10件', $html );
		$this->assertStringContainsString( 'AI要約：月100回', $html );
		$this->assertStringContainsString( 'Standardプランに申し込む', $html );

		$twice = do_shortcode( '[egov_law_subscription_plan][egov_law_subscription_plan]' );
		$this->assertSame( 2, substr_count( $twice, 'egov-subscription-checkout-button' ) );
		$this->assertStringNotContainsString( ' id=', $twice );
	}

	public function test_effective_standard_contract_is_shown_without_checkout_button(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'egov_law_subscriptions',
			array(
				'user_id'                    => $this->user_id,
				'environment'                => 'production',
				'merchant_id'                => 'test-merchant',
				'square_location_id'          => 'test-location',
				'square_customer_id'          => 'test-customer-' . $this->user_id,
				'square_subscription_id'      => 'test-subscription-' . $this->user_id,
				'plan_code'                   => 'standard',
				'internal_status'             => 'active',
				'square_status'               => 'ACTIVE',
				'initial_payment_status'       => 'succeeded',
				'current_slot'                => 1,
				'current_period_start'         => $now,
				'current_period_end'           => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 30 ),
				'sync_state'                  => 'ok',
				'created_at'                  => $now,
				'updated_at'                  => $now,
			)
		);
		$this->assertSame( 1, $inserted );

		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( '>Standard</p>', $html );
		$this->assertStringNotContainsString( 'Standardプランに申し込む', $html );
	}

	public function test_shortcode_shows_login_guidance_when_visitor_is_logged_out(): void {
		wp_set_current_user( 0 );
		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( 'ログインするとご利用プランを確認できます。', $html );
		$this->assertStringNotContainsString( 'egov-subscription-checkout-button', $html );
	}

	public function test_production_environment_keeps_plan_visible_but_hides_checkout_button(): void {
		$this->checkout_environment = 'production';
		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( '>Free</p>', $html );
		$this->assertStringContainsString( 'Sandbox環境の許可されたテストユーザーのみ', $html );
		$this->assertStringNotContainsString( 'egov-subscription-checkout-button', $html );
	}

	public function test_sandbox_user_outside_allowlist_sees_plan_but_not_checkout_button(): void {
		$suffix = wp_generate_password( 8, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'subscription-tab-denied-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'subscription-tab-denied-' . $suffix . '@example.org',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->unallowed_user_id = (int) $user_id;
		wp_set_current_user( $this->unallowed_user_id );
		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( '>Free</p>', $html );
		$this->assertStringNotContainsString( 'egov-subscription-checkout-button', $html );
	}

	public function test_reconciliation_required_state_is_rendered_from_server_and_hides_checkout_button(): void {
		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'egov_law_checkout_attempts',
			array(
				'attempt_uuid'                  => wp_generate_uuid4(),
				'user_id'                       => $this->user_id,
				'environment'                   => 'sandbox',
				'merchant_id'                   => 'test-merchant',
				'square_location_id'             => 'test-location',
				'plan_code'                      => 'standard',
				'catalog_item_variation_id'       => 'test-item-variation',
				'subscription_plan_variation_id'  => 'test-plan-variation',
				'status'                         => 'reconciliation_required',
				'idempotency_key'                => wp_generate_uuid4(),
				'created_at'                     => current_time( 'mysql', true ),
				'updated_at'                     => current_time( 'mysql', true ),
			)
		);
		$this->assertSame( 1, $inserted );

		$html = do_shortcode( '[egov_law_subscription_plan]' );
		$this->assertStringContainsString( '決済状況を確認中です。確認が完了するまで、新たなお申し込みはできません。', $html );
		$this->assertStringNotContainsString( 'egov-subscription-checkout-button', $html );
	}

	public function test_unavailable_checkout_state_does_not_render_a_button(): void {
		$error_filter = static function ( $service ) {
			return new WP_Error( 'test_checkout_unavailable', 'internal details' );
		};
		add_filter( 'egov_law_monitor_square_checkout_service_override', $error_filter, 20 );
		$html = do_shortcode( '[egov_law_subscription_plan]' );
		remove_filter( 'egov_law_monitor_square_checkout_service_override', $error_filter, 20 );

		$this->assertStringContainsString( '申込状況を確認できないため、現在お申し込みいただけません。', $html );
		$this->assertStringNotContainsString( 'internal details', $html );
		$this->assertStringNotContainsString( 'egov-subscription-checkout-button', $html );
	}
}
