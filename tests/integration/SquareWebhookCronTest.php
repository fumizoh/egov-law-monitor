<?php

use PHPUnit\Framework\TestCase;

final class Egov_Law_Monitor_Test_Spy_Square_Webhook_Worker extends Egov_Law_Monitor_Square_Webhook_Event_Worker {
	public $batch_limits = array();

	public function process_batch( $limit = 25 ) {
		$this->batch_limits[] = $limit;
		return array( 'processed' => 0 );
	}
}

final class SquareWebhookCronTest extends TestCase {
	private const CRON_HOOK = 'egov_law_monitor_square_webhook_worker_cron';
	private const CRON_SCHEDULE = 'egov_law_monitor_five_minutes';
	private $worker_filter_callback;

	protected function tearDown(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'egov_law_monitor_unrelated_test_event' );
		if ( is_callable( $this->worker_filter_callback ) ) {
			remove_filter( 'egov_law_monitor_square_webhook_event_worker', $this->worker_filter_callback );
		}
		parent::tearDown();
	}

	public function test_five_minute_schedule_is_added_without_changing_other_schedules(): void {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( self::CRON_SCHEDULE, $schedules );
		$this->assertSame( 300, $schedules[ self::CRON_SCHEDULE ]['interval'] );
		$this->assertArrayHasKey( 'hourly', $schedules );
	}

	public function test_automation_is_disabled_by_default_and_only_its_event_is_cleared(): void {
		if ( defined( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED' ) ) {
			$this->markTestSkipped( 'The worker automation constant is defined by the test environment.' );
		}
		wp_schedule_single_event( time() + 600, 'egov_law_monitor_unrelated_test_event' );
		wp_schedule_single_event( time() + 600, self::CRON_HOOK );

		$this->assertFalse( egov_law_monitor_square_webhook_worker_automation_enabled() );
		$this->assertFalse( egov_law_monitor_square_webhook_worker_schedule() );
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( 'egov_law_monitor_unrelated_test_event' ) );

		wp_schedule_single_event( time() + 600, self::CRON_HOOK );
		egov_law_monitor_square_webhook_worker_deactivate();
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( 'egov_law_monitor_unrelated_test_event' ) );
		wp_clear_scheduled_hook( 'egov_law_monitor_unrelated_test_event' );
	}

	public function test_activation_value_requires_boolean_true(): void {
		$this->assertTrue( egov_law_monitor_square_webhook_activation_value_enabled( true ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( false ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( 1 ) );
		$this->assertFalse( egov_law_monitor_square_webhook_activation_value_enabled( 'true' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enabled_sandbox_schedules_once_and_cron_calls_batch_of_25_without_network(): void {
		$constants = array(
			'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED',
			'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT',
			'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX',
			'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_SANDBOX',
			'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX',
			'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_SANDBOX',
			'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_SANDBOX',
		);
		foreach ( $constants as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$this->markTestSkipped( 'A Square Sandbox test setting is already defined in this process.' );
			}
		}
		define( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED', true );
		define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'sandbox' );
		define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX', 'mock-sandbox-token' );
		define( 'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_SANDBOX', 'cron-test-merchant' );
		define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX', 'cron-test-location' );
		define( 'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_SANDBOX', 'cron-test-item' );
		define( 'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_SANDBOX', 'cron-test-plan' );

		$this->assertTrue( egov_law_monitor_square_webhook_worker_automation_enabled() );
		$this->assertTrue( egov_law_monitor_square_webhook_worker_schedule() );
		$first_run = wp_next_scheduled( self::CRON_HOOK );
		$this->assertNotFalse( $first_run );
		$schedule = wp_get_schedule( self::CRON_HOOK );
		$this->assertSame( self::CRON_SCHEDULE, $schedule );
		$this->assertSame( 300, wp_get_schedules()[ $schedule ]['interval'] );
		$this->assertTrue( egov_law_monitor_square_webhook_worker_schedule() );
		$this->assertSame( $first_run, wp_next_scheduled( self::CRON_HOOK ) );

		global $wpdb;
		$client = Egov_Law_Monitor_Square_Api_Client::from_wordpress_config();
		$checkout_repository = new Egov_Law_Monitor_Checkout_Repository( $wpdb );
		$payment_matcher = new Egov_Law_Monitor_Square_Payment_Attempt_Matcher( $checkout_repository, $client );
		$confirmation = new Egov_Law_Monitor_Square_Initial_Payment_Confirmation_Service( $checkout_repository, $client, $payment_matcher );
		$matcher = new Egov_Law_Monitor_Square_Subscription_Attempt_Matcher( $checkout_repository, $client, $confirmation );
		$spy = new Egov_Law_Monitor_Test_Spy_Square_Webhook_Worker( new Egov_Law_Monitor_Square_Webhook_Repository( $wpdb ), $client, $matcher );
		$this->worker_filter_callback = static function () use ( $spy ) { return $spy; };
		add_filter( 'egov_law_monitor_square_webhook_event_worker', $this->worker_filter_callback );
		$result = egov_law_monitor_square_webhook_worker_cron_callback();
		$this->assertSame( array( 'processed' => 0 ), $result );
		$this->assertSame( array( 25 ), $spy->batch_limits );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cron_failure_returns_safe_error_without_exception_details(): void {
		foreach ( array( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED', 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) as $constant_name ) {
			if ( defined( $constant_name ) ) {
				$this->markTestSkipped( 'A worker setting is already defined in this process.' );
			}
		}
		define( 'EGOV_LAW_MONITOR_SQUARE_WEBHOOK_WORKER_ENABLED', true );
		define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'sandbox' );
		foreach (
			array(
				'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX' => 'mock-token',
				'EGOV_LAW_MONITOR_SQUARE_MERCHANT_ID_SANDBOX' => 'cron-test-merchant',
				'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX' => 'cron-test-location',
				'EGOV_LAW_MONITOR_SQUARE_CATALOG_ITEM_VARIATION_ID_SANDBOX' => 'cron-test-item',
				'EGOV_LAW_MONITOR_SQUARE_SUBSCRIPTION_PLAN_VARIATION_ID_SANDBOX' => 'cron-test-plan',
			) as $constant_name => $value
		) {
			if ( ! defined( $constant_name ) ) {
				define( $constant_name, $value );
			}
		}
		$this->worker_filter_callback = static function () { throw new RuntimeException( 'secret exception details' ); };
		add_filter( 'egov_law_monitor_square_webhook_event_worker', $this->worker_filter_callback );
		$result = egov_law_monitor_square_webhook_worker_cron_callback();
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'square_webhook_worker_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'secret exception details', $result->get_error_message() );
	}
}
