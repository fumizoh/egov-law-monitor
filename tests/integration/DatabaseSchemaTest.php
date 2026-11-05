<?php

use PHPUnit\Framework\TestCase;

final class DatabaseSchemaTest extends TestCase {
	private $tables = array();
	private const SCHEMA_VERSION_OPTION = 'egov_law_monitor_square_schema_version';
	private const CURRENT_SCHEMA_VERSION = 4;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->tables = array(
			$wpdb->prefix . 'law_watch_settings',
			$wpdb->prefix . 'egov_law_ai_summaries',
			$wpdb->prefix . 'egov_law_ai_summary_history',
			$wpdb->prefix . 'egov_law_subscriptions',
			$wpdb->prefix . 'egov_law_subscription_invoices',
			$wpdb->prefix . 'egov_law_square_events',
			$wpdb->prefix . 'egov_law_ai_summary_usage',
			$wpdb->prefix . 'egov_law_checkout_attempts',
		);
	}

	protected function tearDown(): void {
		global $wpdb;

		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			delete_option( self::SCHEMA_VERSION_OPTION );

			foreach ( $this->tables as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		parent::tearDown();
	}

	public function test_initial_migration_and_rerun_preserve_existing_watch_data(): void {
		global $wpdb;

		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );

		egov_law_monitor_create_tables();
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		foreach ( $this->tables as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
			);
		}

		$watch_table = $wpdb->prefix . 'law_watch_settings';
		$inserted     = $wpdb->insert(
			$watch_table,
			array(
				'user_id'    => 1,
				'keyword'    => 'test-law-keyword',
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
				'is_active'  => 1,
			)
		);

		$this->assertSame( 1, $inserted );

		$history_table = $wpdb->prefix . 'egov_law_ai_summary_history';
		$history_added = $wpdb->insert(
			$history_table,
			array(
				'user_id'       => 1,
				'law_id'        => 'test-law',
				'law_name'      => 'Test Law',
				'effective_date' => '2026-01-01',
				'law_data_id'   => '',
				'sub_revision'  => '',
				'revision_hash' => str_repeat( 'a', 64 ),
				'summary_title' => 'Existing summary',
				'summary_body'  => 'Existing summary history body',
				'used_at'       => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( 1, $history_added );

		delete_option( self::SCHEMA_VERSION_OPTION );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		egov_law_monitor_create_tables();

		$this->assertSame(
			'test-law-keyword',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT keyword FROM {$watch_table} WHERE user_id = %d",
					1
				)
			)
		);
		$this->assertSame(
			'Existing summary history body',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT summary_body FROM {$history_table} WHERE user_id = %d AND law_id = %s",
					1,
				'test-law'
				)
			)
		);
	}

	public function test_version_one_migration_adds_checkout_attempts_and_preserves_existing_data(): void {
		global $wpdb;

		// The data-preservation assertion uses the plugin's existing watch table.
		egov_law_monitor_create_tables();
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$watch_table    = $wpdb->prefix . 'law_watch_settings';
		$attempts_table = $wpdb->prefix . 'egov_law_checkout_attempts';
		$this->assertSame(
			1,
			$wpdb->insert(
				$watch_table,
				array(
					'user_id'    => 845,
					'keyword'    => 'preserve-on-v2-upgrade',
					'created_at' => '2026-02-01 00:00:00',
					'updated_at' => '2026-02-01 00:00:00',
					'is_active'  => 1,
				)
			)
		);
		$wpdb->query( "DROP TABLE `{$attempts_table}`" );
		update_option( self::SCHEMA_VERSION_OPTION, 1, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertTableExists( $attempts_table );
		$this->assertSame( 'last_error_code', $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$attempts_table}` LIKE %s", 'last_error_code' ) ) );
		$this->assertSame(
			'preserve-on-v2-upgrade',
			$wpdb->get_var( $wpdb->prepare( "SELECT keyword FROM {$watch_table} WHERE user_id = %d", 845 ) )
		);
	}

	public function test_version_two_migration_adds_checkout_error_tracking_without_losing_attempts(): void {
		global $wpdb;
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$table = $wpdb->prefix . 'egov_law_checkout_attempts';
		$attempt_uuid = wp_generate_uuid4();
		$inserted = $wpdb->insert(
			$table,
			array(
				'attempt_uuid' => $attempt_uuid,
				'user_id' => 856,
				'environment' => 'sandbox',
				'merchant_id' => 'schema-migration-merchant',
				'square_location_id' => 'schema-location',
				'plan_code' => 'standard',
				'catalog_item_variation_id' => 'schema-item',
				'subscription_plan_variation_id' => 'schema-plan',
				'status' => 'creating',
				'idempotency_key' => wp_generate_uuid4(),
				'created_at' => '2026-03-01 00:00:00',
				'updated_at' => '2026-03-01 00:00:00',
			)
		);
		$this->assertSame( 1, $inserted );
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN last_error_code" );
		update_option( self::SCHEMA_VERSION_OPTION, 2, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attempt_uuid, status, last_error_code FROM {$table} WHERE attempt_uuid = %s", $attempt_uuid ), ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertSame( 'creating', $row['status'] );
		$this->assertSame( '', $row['last_error_code'] );
	}

	public function test_version_three_migration_adds_webhook_claim_generation_and_preserves_events(): void {
		global $wpdb;
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$table = $wpdb->prefix . 'egov_law_square_events';
		$event_id = wp_generate_uuid4();
		$payload = wp_json_encode( array( 'event_id' => $event_id, 'type' => 'subscription.created' ) );
		$this->assertSame(
			1,
			$wpdb->insert(
				$table,
				array(
					'environment' => 'sandbox',
					'merchant_id' => 'schema-migration-merchant',
					'square_event_id' => $event_id,
					'event_type' => 'subscription.created',
					'received_at' => '2026-05-01 00:00:00',
					'processing_status' => 'received',
					'payload_hash' => hash( 'sha256', $payload ),
					'normalized_payload' => $payload,
					'created_at' => '2026-05-01 00:00:00',
					'updated_at' => '2026-05-01 00:00:00',
				)
			)
		);

		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$table}` MODIFY processing_status VARCHAR(20) NOT NULL DEFAULT 'received', DROP COLUMN claim_generation" ) );
		update_option( self::SCHEMA_VERSION_OPTION, 3, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'claim_generation' ), ARRAY_A );
		$this->assertIsArray( $column );
		$this->assertSame( 'bigint unsigned', strtolower( $column['Type'] ) );
		$this->assertSame( '0', (string) $column['Default'] );
		$this->assertSame( 'varchar(32)', strtolower( $wpdb->get_var( $wpdb->prepare( "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'processing_status'", $table ) ) ) );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT event_type, processing_status, claim_generation, normalized_payload FROM {$table} WHERE square_event_id = %s", $event_id ), ARRAY_A );
		$this->assertSame( 'subscription.created', $row['event_type'] );
		$this->assertSame( 'received', $row['processing_status'] );
		$this->assertSame( '0', (string) $row['claim_generation'] );
		$this->assertSame( $payload, $row['normalized_payload'] );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( $payload, $wpdb->get_var( $wpdb->prepare( "SELECT normalized_payload FROM {$table} WHERE square_event_id = %s", $event_id ) ) );
	}

	public function test_subscription_migration_recovers_after_a_table_was_skipped(): void {
		global $wpdb;

		$invoice_table = $wpdb->prefix . 'egov_law_subscription_invoices';
		$skip_invoice  = static function ( $queries ) use ( $invoice_table ) {
			unset( $queries[ $invoice_table ] );

			return $queries;
		};

		add_filter( 'dbdelta_create_queries', $skip_invoice );
		try {
			$this->assertFalse( egov_law_monitor_maybe_upgrade_subscription_schema() );
		} finally {
			remove_filter( 'dbdelta_create_queries', $skip_invoice );
		}

		$this->assertSame( 0, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertTableExists( $wpdb->prefix . 'egov_law_subscriptions' );
		$this->assertTableDoesNotExist( $invoice_table );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertTableExists( $invoice_table );
	}

	public function test_subscription_tables_use_innodb_and_expected_unique_indexes(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		foreach ( array_slice( $this->tables, 3 ) as $table ) {
			$this->assertSame( 'InnoDB', $this->getTableEngine( $table ) );
		}

		$this->assertIndex(
			$wpdb->prefix . 'egov_law_subscriptions',
			'square_subscription',
			array( 'environment', 'merchant_id', 'square_subscription_id' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_subscription_invoices',
			'square_invoice',
			array( 'environment', 'merchant_id', 'square_invoice_id' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_square_events',
			'square_event',
			array( 'environment', 'merchant_id', 'square_event_id' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_ai_summary_usage',
			'user_request',
			array( 'environment', 'user_id', 'request_key' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_checkout_attempts',
			'attempt_uuid',
			array( 'attempt_uuid' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_checkout_attempts',
			'attempt_idempotency',
			array( 'environment', 'merchant_id', 'idempotency_key' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_checkout_attempts',
			'payment_link',
			array( 'environment', 'merchant_id', 'square_payment_link_id' ),
			true
		);
		$this->assertIndex(
			$wpdb->prefix . 'egov_law_checkout_attempts',
			'order_lookup',
			array( 'environment', 'merchant_id', 'square_order_id' ),
			true
		);
		$this->assertSame( 'InnoDB', $this->getTableEngine( $wpdb->users ) );
	}

	public function test_current_schema_is_verified_without_repeating_dbdelta(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$delta_calls = 0;
		$count_delta = static function ( $queries ) use ( &$delta_calls ) {
			++$delta_calls;
			return $queries;
		};
		add_filter( 'dbdelta_create_queries', $count_delta );
		try {
			$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		} finally {
			remove_filter( 'dbdelta_create_queries', $count_delta );
		}

		$this->assertSame( 0, $delta_calls );
	}

	public function test_current_schema_repairs_a_missing_table_and_preserves_other_table_data(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$usage_table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$event_table = $wpdb->prefix . 'egov_law_square_events';
		$wpdb->insert(
			$usage_table,
			array(
				'user_id'     => 777,
				'request_key' => hash( 'sha256', 'schema-repair-preserve' ),
				'plan_code'   => 'free',
				'reserved_at' => '2026-01-01 00:00:00',
				'created_at'  => '2026-01-01 00:00:00',
				'updated_at'  => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( '', (string) $wpdb->last_error );
		$wpdb->query( "DROP TABLE `{$event_table}`" );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertTableExists( $event_table );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage_table} WHERE user_id = %d", 777 ) ) );
	}

	public function test_current_schema_rejects_a_missing_required_column(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$table = $wpdb->prefix . 'egov_law_square_events';
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN last_error_message" );
		try {
			$this->assertFalse( egov_law_monitor_maybe_upgrade_subscription_schema() );
			$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		} finally {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN last_error_message TEXT NULL AFTER last_error_code" );
		}
	}

	public function test_current_schema_rejects_an_inconsistent_index_without_replacing_it(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$table = $wpdb->prefix . 'egov_law_square_events';
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX square_event, ADD INDEX square_event (event_type, received_at)" );
		try {
			$this->assertFalse( egov_law_monitor_maybe_upgrade_subscription_schema() );
			$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		} finally {
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX square_event, ADD UNIQUE KEY square_event (environment, merchant_id, square_event_id)" );
		}
	}

	public function test_square_identifiers_are_unique_per_environment_and_merchant(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$subscription_table = $wpdb->prefix . 'egov_law_subscriptions';
		$base_subscription  = array(
			'user_id'                => 101,
			'merchant_id'            => 'merchant-test',
			'square_location_id'     => 'location-test',
			'square_customer_id'     => 'customer-test',
			'square_subscription_id' => 'subscription-shared',
			'current_slot'           => 1,
			'created_at'             => '2026-01-01 00:00:00',
			'updated_at'             => '2026-01-01 00:00:00',
		);

		$sandbox_subscription                = $base_subscription;
		$sandbox_subscription['environment'] = 'sandbox';
		$this->assertSame( 1, $wpdb->insert( $subscription_table, $sandbox_subscription ) );

		$production_subscription                = $base_subscription;
		$production_subscription['environment'] = 'production';
		$this->assertSame( 1, $wpdb->insert( $subscription_table, $production_subscription ) );

		$old_suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $wpdb->insert( $subscription_table, $sandbox_subscription ) );
		} finally {
			$wpdb->suppress_errors( $old_suppress );
		}

		$event_table = $wpdb->prefix . 'egov_law_square_events';
		$base_event  = array(
			'merchant_id'     => 'merchant-test',
			'square_event_id' => 'event-shared',
			'event_type'      => 'subscription.updated',
			'received_at'     => '2026-01-01 00:00:00',
			'created_at'      => '2026-01-01 00:00:00',
			'updated_at'      => '2026-01-01 00:00:00',
		);
		$sandbox_event                = $base_event;
		$sandbox_event['environment'] = 'sandbox';
		$this->assertSame( 1, $wpdb->insert( $event_table, $sandbox_event ) );
		$production_event                = $base_event;
		$production_event['environment'] = 'production';
		$this->assertSame( 1, $wpdb->insert( $event_table, $production_event ) );

		$old_suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $wpdb->insert( $event_table, $sandbox_event ) );
		} finally {
			$wpdb->suppress_errors( $old_suppress );
		}

		$invoice_table = $wpdb->prefix . 'egov_law_subscription_invoices';
		$base_invoice  = array(
			'subscription_id'  => 999,
			'user_id'          => 101,
			'merchant_id'      => 'merchant-test',
			'square_invoice_id' => 'invoice-shared',
			'created_at'       => '2026-01-01 00:00:00',
			'updated_at'       => '2026-01-01 00:00:00',
		);
		$sandbox_invoice                = $base_invoice;
		$sandbox_invoice['environment'] = 'sandbox';
		$this->assertSame( 1, $wpdb->insert( $invoice_table, $sandbox_invoice ) );
		$production_invoice                = $base_invoice;
		$production_invoice['environment'] = 'production';
		$this->assertSame( 1, $wpdb->insert( $invoice_table, $production_invoice ) );

		$old_suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $wpdb->insert( $invoice_table, $sandbox_invoice ) );
		} finally {
			$wpdb->suppress_errors( $old_suppress );
		}

		$usage_table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$base_usage  = array(
			'user_id'     => 101,
			'request_key' => 'request-shared',
			'plan_code'   => 'standard',
			'reserved_at' => '2026-01-01 00:00:00',
			'created_at'  => '2026-01-01 00:00:00',
			'updated_at'  => '2026-01-01 00:00:00',
		);
		$sandbox_usage                = $base_usage;
		$sandbox_usage['environment'] = 'sandbox';
		$this->assertSame( 1, $wpdb->insert( $usage_table, $sandbox_usage ) );
		$production_usage                = $base_usage;
		$production_usage['environment'] = 'production';
		$this->assertSame( 1, $wpdb->insert( $usage_table, $production_usage ) );

		$old_suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $wpdb->insert( $usage_table, $sandbox_usage ) );
		} finally {
			$wpdb->suppress_errors( $old_suppress );
		}
	}

	private function assertTableExists( string $table ): void {
		global $wpdb;

		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
		);
	}

	private function assertTableDoesNotExist( string $table ): void {
		global $wpdb;

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
		);
	}

	private function getTableEngine( string $table ): string {
		global $wpdb;

		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );

		return is_object( $status ) ? (string) $status->Engine : '';
	}

	private function assertIndex( string $table, string $name, array $columns, bool $unique ): void {
		global $wpdb;

		$rows          = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );
		$actual_columns = array();
		$actual_unique  = null;

		foreach ( $rows as $row ) {
			if ( strtolower( $row->Key_name ) !== strtolower( $name ) ) {
				continue;
			}

			$actual_columns[ (int) $row->Seq_in_index ] = $row->Column_name;
			$actual_unique                            = '0' === (string) $row->Non_unique;
		}

		ksort( $actual_columns );
		$this->assertSame( $columns, array_values( $actual_columns ), $name . ' index columns' );
		$this->assertSame( $unique, $actual_unique, $name . ' index uniqueness' );
	}
}
