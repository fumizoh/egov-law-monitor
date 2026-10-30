<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/wordpress-plugin/includes/internal-api.php';

final class InternalWatchSettingsApiTest extends TestCase {
	private $user_id;
	private $previous_user_id;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		egov_law_monitor_create_tables();
		$this->assertTrue( egov_law_monitor_create_subscription_tables() );

		$suffix = wp_generate_password( 12, false, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'internal-watch-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'internal-watch-' . $suffix . '@example.org',
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

		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' && $this->user_id ) {
			$wpdb->delete( $wpdb->prefix . 'law_watch_settings', array( 'user_id' => $this->user_id ), array( '%d' ) );
			wp_delete_user( $this->user_id );
		}

		wp_set_current_user( $this->previous_user_id );
		parent::tearDown();
	}

	public function test_internal_api_requires_the_existing_capability(): void {
		$response = $this->request();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertFalse( egov_law_monitor_internal_api_permission() );
	}

	public function test_response_omits_plan_and_preserves_active_watch_data_without_entitlement_lookup(): void {
		global $wpdb;
		update_user_meta( $this->user_id, 'egov_law_monitor_plan', 'standard' );
		update_user_meta( $this->user_id, 'egov_law_monitor_notifications', 1 );
		$this->insert_watch( 'active-first', 1, '2026-01-01 00:00:00' );
		$this->insert_watch( 'paused-ignored', 0, '2026-01-02 00:00:00' );
		$this->insert_watch( 'active-second', 1, '2026-01-03 00:00:00' );
		$user = wp_get_current_user();
		$user->add_cap( 'law_watch_internal_api' );
		$this->assertTrue( egov_law_monitor_internal_api_permission() );

		$subscription_table = $wpdb->prefix . 'egov_law_subscriptions';
		$wpdb->query( "DROP TABLE `{$subscription_table}`" );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		try {
			$response = $this->request();
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			$this->assertTrue( egov_law_monitor_create_subscription_tables() );
		}

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'users', $data );
		$this->assertCount( 1, $data['users'] );
		$api_user = $data['users'][0];
		$this->assertArrayNotHasKey( 'plan', $api_user );
		$this->assertSame( $this->user_id, $api_user['user_id'] );
		$this->assertSame( $user->user_email, $api_user['email'] );
		$this->assertTrue( $api_user['notifications'] );
		$this->assertSame(
			array(
				array( 'keyword' => 'active-first' ),
				array( 'keyword' => 'active-second' ),
			),
			$api_user['watches']
		);
	}

	public function test_internal_api_rejects_anonymous_requests(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( egov_law_monitor_internal_api_permission() );
		$this->assertSame( 401, $this->request()->get_status() );
	}

	private function request() {
		return rest_do_request( new WP_REST_Request( 'GET', '/egov-law-monitor/v1/internal/watch-settings' ) );
	}

	private function insert_watch( $keyword, $is_active, $created_at ) {
		global $wpdb;
		$result = $wpdb->insert(
			$wpdb->prefix . 'law_watch_settings',
			array(
				'user_id'    => $this->user_id,
				'keyword'    => $keyword,
				'created_at' => $created_at,
				'updated_at' => $created_at,
				'is_active'  => $is_active,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
		$this->assertSame( 1, $result );
	}
}
