<?php

use PHPUnit\Framework\TestCase;

final class DatabaseSchemaTest extends TestCase {
	private $tables = array();
	private const SCHEMA_VERSION_OPTION = 'egov_law_monitor_square_schema_version';
	private const CURRENT_SCHEMA_VERSION = 8;

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
			$wpdb->prefix . 'egov_law_square_admin_reviews',
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
		$this->assertColumn( $wpdb->prefix . 'egov_law_subscriptions', 'review_grace_ends_at', 'datetime', 'YES', '' );
		$this->assertColumn( $wpdb->prefix . 'egov_law_square_admin_reviews', 'admin_note', 'text', 'YES', null );

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

	public function test_version_five_migration_adds_admin_review_history_and_preserves_contract_data(): void {
		global $wpdb;
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
		$reviews = $wpdb->prefix . 'egov_law_square_admin_reviews';
		$subscription_id = 'preserve-v5-before-admin-review';
		$this->assertSame( 1, $wpdb->insert( $subscriptions, array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'schema-v5-merchant',
			'square_customer_id' => 'schema-v5-customer', 'square_subscription_id' => $subscription_id,
			'plan_code' => 'standard', 'internal_status' => 'pending', 'current_slot' => null,
			'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
		) ) );
		$wpdb->query( "DROP TABLE `{$reviews}`" );
		update_option( self::SCHEMA_VERSION_OPTION, 5, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertTableExists( $reviews );
		$this->assertSame( $subscription_id, $wpdb->get_var( $wpdb->prepare( "SELECT square_subscription_id FROM {$subscriptions} WHERE merchant_id = %s", 'schema-v5-merchant' ) ) );
		$this->assertColumn( $reviews, 'square_invoice_id', 'varchar(255)', 'YES', '' );
		$this->assertColumn( $reviews, 'approved_period_start', 'datetime', 'YES', '' );
		$this->assertColumn( $reviews, 'subscription_timezone', 'varchar(64)', 'YES', '' );
		$this->assertColumn( $reviews, 'monthly_billing_anchor_date', 'tinyint unsigned', 'YES', '' );
		$this->assertIndex( $reviews, 'approval_payment_guard', array( 'approval_payment_guard' ), true );
		$this->assertIndex( $reviews, 'approval_invoice_guard', array( 'approval_invoice_guard' ), true );
	}

	public function test_version_six_migration_adds_nullable_review_provenance_without_backfill(): void {
		global $wpdb;
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$reviews = $wpdb->prefix . 'egov_law_square_admin_reviews';
		$wpdb->query( "ALTER TABLE `{$reviews}` DROP COLUMN subscription_timezone, DROP COLUMN monthly_billing_anchor_date" );
		$wpdb->last_error = '';
		$this->assertSame( 1, $wpdb->insert( $reviews, array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'legacy-review-merchant',
			'action_type' => 'hold', 'evidence_code' => 'legacy_review', 'reviewer_user_id' => 1,
			'recorded_at' => '2026-10-01 00:00:00',
		) ) );
		$review_id = (int) $wpdb->insert_id;
		update_option( self::SCHEMA_VERSION_OPTION, 6, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertColumn( $reviews, 'subscription_timezone', 'varchar(64)', 'YES', '' );
		$this->assertColumn( $reviews, 'monthly_billing_anchor_date', 'tinyint unsigned', 'YES', '' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT action_type, evidence_code, subscription_timezone, monthly_billing_anchor_date FROM {$reviews} WHERE id = %d", $review_id ), ARRAY_A );
		$this->assertSame( 'hold', $row['action_type'] );
		$this->assertSame( 'legacy_review', $row['evidence_code'] );
		$this->assertNull( $row['subscription_timezone'] );
		$this->assertNull( $row['monthly_billing_anchor_date'] );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT subscription_timezone FROM {$reviews} WHERE id = %d", $review_id ) ) );
	}

	public function test_version_seven_migration_adds_nullable_manual_review_fields_and_preserves_rows(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
		$reviews        = $wpdb->prefix . 'egov_law_square_admin_reviews';
		$subscription_id = 'preserve-v8-subscription';
		$this->assertSame( 1, $wpdb->insert( $subscriptions, array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'v8-merchant',
			'square_customer_id' => 'v8-customer', 'square_subscription_id' => $subscription_id,
			'plan_code' => 'standard', 'internal_status' => 'pending', 'current_slot' => null,
			'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
		) ) );
		$subscription_row_id = (int) $wpdb->insert_id;
		$this->assertSame( 1, $wpdb->insert( $reviews, array(
			'user_id' => 1, 'environment' => 'sandbox', 'merchant_id' => 'v8-merchant',
			'action_type' => 'hold', 'evidence_code' => 'v7_existing_review', 'reviewer_user_id' => 1,
			'recorded_at' => '2026-11-01 00:00:00',
		) ) );
		$review_id = (int) $wpdb->insert_id;
		$wpdb->query( "ALTER TABLE `{$subscriptions}` DROP COLUMN review_grace_ends_at" );
		$wpdb->query( "ALTER TABLE `{$reviews}` DROP COLUMN admin_note" );
		update_option( self::SCHEMA_VERSION_OPTION, 7, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertColumn( $subscriptions, 'review_grace_ends_at', 'datetime', 'YES', '' );
		$this->assertColumn( $reviews, 'admin_note', 'text', 'YES', null );
		$this->assertSame( $subscription_id, $wpdb->get_var( $wpdb->prepare( "SELECT square_subscription_id FROM {$subscriptions} WHERE id = %d", $subscription_row_id ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT review_grace_ends_at FROM {$subscriptions} WHERE id = %d", $subscription_row_id ) ) );
		$this->assertSame( 'v7_existing_review', $wpdb->get_var( $wpdb->prepare( "SELECT evidence_code FROM {$reviews} WHERE id = %d", $review_id ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT admin_note FROM {$reviews} WHERE id = %d", $review_id ) ) );
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
	}

	public function test_failed_version_seven_migration_does_not_advance_schema_version(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$reviews = $wpdb->prefix . 'egov_law_square_admin_reviews';
		$wpdb->query( "ALTER TABLE `{$reviews}` DROP COLUMN admin_note" );
		update_option( self::SCHEMA_VERSION_OPTION, 7, false );
		$skip_review_table = static function ( $queries ) use ( $reviews ) {
			unset( $queries[ $reviews ] );
			return $queries;
		};
		add_filter( 'dbdelta_create_queries', $skip_review_table );
		try {
			$this->assertFalse( egov_law_monitor_maybe_upgrade_subscription_schema() );
			$this->assertSame( 7, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		} finally {
			remove_filter( 'dbdelta_create_queries', $skip_review_table );
		}
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

	public function test_version_four_migration_adds_contract_reconciliation_columns_and_preserves_data(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$subscriptions         = $wpdb->prefix . 'egov_law_subscriptions';
		$invoices              = $wpdb->prefix . 'egov_law_subscription_invoices';
		$subscription_id = 'preserved-subscription-v4';
		$invoice_id      = 'preserved-invoice-v4';

		$this->assertSame(
			1,
			$wpdb->insert(
				$subscriptions,
				array(
					'user_id'                => 865,
					'environment'            => 'sandbox',
					'merchant_id'            => 'migration-merchant',
					'square_location_id'     => 'migration-location',
					'square_customer_id'     => 'migration-customer',
					'square_subscription_id' => $subscription_id,
					'square_plan_variation_id' => 'migration-plan',
					'internal_status'       => 'active',
					'initial_payment_status' => 'succeeded',
					'current_slot'            => 1,
					'current_period_end'      => '2027-06-01 00:00:00',
					'sync_state'              => 'ok',
					'created_at'              => '2026-06-01 00:00:00',
					'updated_at'              => '2026-06-01 00:00:00',
				)
			)
		);
		$local_subscription_id = (int) $wpdb->insert_id;
		$repository = new Egov_Law_Monitor_Subscription_Repository( $wpdb );
		$snapshot_before = $repository->get_entitlement_snapshot( 865, 'sandbox' );
		$this->assertIsArray( $snapshot_before );
		$entitlement_before = egov_law_monitor_resolve_entitlement( $snapshot_before, new DateTimeImmutable( '2026-06-15 00:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( 'standard', $entitlement_before['effective_plan'] );
		$this->assertSame(
			1,
			$wpdb->insert(
				$invoices,
				array(
					'subscription_id'   => $local_subscription_id,
					'user_id'           => 865,
					'environment'       => 'sandbox',
					'merchant_id'       => 'migration-merchant',
					'square_invoice_id' => $invoice_id,
					'amount'            => 500,
					'currency'          => 'JPY',
					'payment_status'    => 'paid',
					'created_at'        => '2026-06-01 00:00:00',
					'updated_at'        => '2026-06-01 00:00:00',
				)
			)
		);

		// Restore the v4 contract-table shape while retaining the original rows.
		$this->assertNotFalse(
			$wpdb->query(
				"ALTER TABLE `{$subscriptions}` MODIFY square_customer_id VARCHAR(64) NOT NULL, MODIFY square_subscription_id VARCHAR(64) NOT NULL, MODIFY square_plan_variation_id VARCHAR(64) NOT NULL DEFAULT '', DROP INDEX checkout_attempt_uuid, DROP COLUMN checkout_attempt_uuid, DROP COLUMN square_version"
			)
		);
		$this->assertNotFalse(
			$wpdb->query(
				"ALTER TABLE `{$invoices}` MODIFY square_invoice_id VARCHAR(64) NOT NULL, MODIFY square_payment_id VARCHAR(64) NULL DEFAULT NULL, DROP COLUMN square_version, DROP COLUMN completed_amount"
			)
		);
		update_option( self::SCHEMA_VERSION_OPTION, 4, false );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( self::CURRENT_SCHEMA_VERSION, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertColumn( $subscriptions, 'square_subscription_id', 'varchar(255)', 'NO', '' );
		$this->assertColumn( $subscriptions, 'checkout_attempt_uuid', 'char(36)', 'YES', null );
		$this->assertColumn( $subscriptions, 'square_version', 'bigint unsigned', 'YES', null );
		$this->assertColumn( $invoices, 'square_invoice_id', 'varchar(255)', 'NO', '' );
		$this->assertColumn( $invoices, 'square_payment_id', 'varchar(192)', 'YES', null );
		$this->assertColumn( $invoices, 'square_version', 'bigint unsigned', 'YES', null );
		$this->assertColumn( $invoices, 'completed_amount', 'bigint', 'YES', null );

		$subscription = $wpdb->get_row( $wpdb->prepare( "SELECT square_subscription_id, square_customer_id, current_slot, square_version, checkout_attempt_uuid FROM {$subscriptions} WHERE id = %d", $local_subscription_id ), ARRAY_A );
		$this->assertSame( $subscription_id, $subscription['square_subscription_id'] );
		$this->assertSame( 'migration-customer', $subscription['square_customer_id'] );
		$this->assertSame( '1', (string) $subscription['current_slot'] );
		$this->assertNull( $subscription['square_version'] );
		$this->assertNull( $subscription['checkout_attempt_uuid'] );

		$invoice = $wpdb->get_row( $wpdb->prepare( "SELECT square_invoice_id, amount, completed_amount, currency, payment_status FROM {$invoices} WHERE square_invoice_id = %s", $invoice_id ), ARRAY_A );
		$this->assertSame( $invoice_id, $invoice['square_invoice_id'] );
		$this->assertSame( '500', (string) $invoice['amount'] );
		$this->assertNull( $invoice['completed_amount'] );
		$this->assertSame( 'JPY', $invoice['currency'] );
		$this->assertSame( 'paid', $invoice['payment_status'] );
		$snapshot_after = $repository->get_entitlement_snapshot( 865, 'sandbox' );
		$this->assertIsArray( $snapshot_after );
		$entitlement_after = egov_law_monitor_resolve_entitlement( $snapshot_after, new DateTimeImmutable( '2026-06-15 00:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( $entitlement_before, $entitlement_after );

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->assertSame( $subscription_id, $wpdb->get_var( $wpdb->prepare( "SELECT square_subscription_id FROM {$subscriptions} WHERE id = %d", $local_subscription_id ) ) );
	}

	public function test_failed_version_four_migration_does_not_advance_schema_version(): void {
		global $wpdb;

		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
		$invoices      = $wpdb->prefix . 'egov_law_subscription_invoices';
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$subscriptions}` MODIFY square_subscription_id VARCHAR(64) NOT NULL, DROP INDEX checkout_attempt_uuid, DROP COLUMN checkout_attempt_uuid, DROP COLUMN square_version" ) );
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$invoices}` MODIFY square_invoice_id VARCHAR(64) NOT NULL, MODIFY square_payment_id VARCHAR(64) NULL DEFAULT NULL, DROP COLUMN square_version, DROP COLUMN completed_amount" ) );
		update_option( self::SCHEMA_VERSION_OPTION, 4, false );

		$skip_subscription_upgrade = static function ( $queries ) use ( $subscriptions ) {
			unset( $queries[ $subscriptions ] );
			return $queries;
		};
		add_filter( 'dbdelta_create_queries', $skip_subscription_upgrade );
		try {
			$this->assertFalse( egov_law_monitor_maybe_upgrade_subscription_schema() );
		} finally {
			remove_filter( 'dbdelta_create_queries', $skip_subscription_upgrade );
		}

		$this->assertSame( 4, (int) get_option( self::SCHEMA_VERSION_OPTION, 0 ) );
		$this->assertColumn( $subscriptions, 'square_subscription_id', 'varchar(64)', 'NO', '' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$subscriptions}` LIKE %s", 'square_version' ) ) );
	}

	public function test_current_schema_accepts_square_id_lengths_and_keeps_unique_constraints(): void {
		global $wpdb;
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );

		$subscriptions         = $wpdb->prefix . 'egov_law_subscriptions';
		$invoices              = $wpdb->prefix . 'egov_law_subscription_invoices';
		$attempt_uuid          = wp_generate_uuid4();
		$long_subscription_id = str_repeat( 's', 255 );
		$long_plan_id          = str_repeat( 'p', 255 );
		$long_customer_id      = str_repeat( 'c', 191 );
		$this->assertSame(
			1,
			$wpdb->insert(
				$subscriptions,
				array(
					'user_id'                 => 866,
					'environment'             => 'sandbox',
					'merchant_id'             => 'long-id-merchant',
					'square_location_id'      => 'location',
					'square_customer_id'      => $long_customer_id,
					'square_subscription_id'  => $long_subscription_id,
					'square_plan_variation_id' => $long_plan_id,
					'checkout_attempt_uuid'   => $attempt_uuid,
					'square_version'          => '18446744073709551615',
					'current_slot'            => 1,
					'created_at'              => '2026-07-01 00:00:00',
					'updated_at'              => '2026-07-01 00:00:00',
				)
			)
		);
		$local_subscription_id = (int) $wpdb->insert_id;
		$this->assertSame( $long_subscription_id, $wpdb->get_var( $wpdb->prepare( "SELECT square_subscription_id FROM {$subscriptions} WHERE id = %d", $local_subscription_id ) ) );
		$this->assertSame( $attempt_uuid, $wpdb->get_var( $wpdb->prepare( "SELECT checkout_attempt_uuid FROM {$subscriptions} WHERE id = %d", $local_subscription_id ) ) );
		$this->assertSame( '18446744073709551615', (string) $wpdb->get_var( $wpdb->prepare( "SELECT square_version FROM {$subscriptions} WHERE id = %d", $local_subscription_id ) ) );

		$this->assertSame(
			1,
			$wpdb->insert(
				$invoices,
				array(
					'subscription_id'   => $local_subscription_id,
					'user_id'           => 866,
					'environment'       => 'sandbox',
					'merchant_id'       => 'long-id-merchant',
					'square_invoice_id' => str_repeat( 'i', 255 ),
					'square_payment_id' => str_repeat( 'x', 192 ),
					'square_version'    => 2147483647,
					'amount'            => 500,
					'completed_amount'  => 0,
					'currency'          => 'JPY',
					'created_at'        => '2026-07-01 00:00:00',
					'updated_at'        => '2026-07-01 00:00:00',
				)
			)
		);
		$this->assertColumn( $subscriptions, 'square_subscription_id', 'varchar(255)', 'NO', '' );
		$this->assertColumn( $invoices, 'square_invoice_id', 'varchar(255)', 'NO', '' );
		$this->assertColumn( $invoices, 'square_payment_id', 'varchar(192)', 'YES', null );
		$this->assertColumn( $invoices, 'completed_amount', 'bigint', 'YES', null );
		$this->assertIndex( $subscriptions, 'square_subscription', array( 'environment', 'merchant_id', 'square_subscription_id' ), true );
		$this->assertIndex( $subscriptions, 'checkout_attempt_uuid', array( 'checkout_attempt_uuid' ), true );
		$this->assertIndex( $subscriptions, 'current_user_subscription', array( 'user_id', 'environment', 'merchant_id', 'current_slot' ), true );
		$this->assertIndex( $invoices, 'square_invoice', array( 'environment', 'merchant_id', 'square_invoice_id' ), true );
		$this->assertIndex( $invoices, 'square_payment', array( 'environment', 'merchant_id', 'square_payment_id' ), true );

		$old_suppress = $wpdb->suppress_errors( true );
		try {
			$duplicate_subscription = array(
				'user_id'                => 867,
				'environment'            => 'sandbox',
				'merchant_id'            => 'long-id-merchant',
				'square_customer_id'     => 'another-customer',
				'square_subscription_id' => $long_subscription_id,
				'created_at'             => '2026-07-01 00:00:00',
				'updated_at'             => '2026-07-01 00:00:00',
			);
			$this->assertFalse( $wpdb->insert( $subscriptions, $duplicate_subscription ) );
			$duplicate_invoice = array(
				'subscription_id'   => $local_subscription_id,
				'user_id'           => 866,
				'environment'       => 'sandbox',
				'merchant_id'       => 'long-id-merchant',
				'square_invoice_id' => str_repeat( 'i', 255 ),
				'square_payment_id' => 'a-different-payment',
				'created_at'        => '2026-07-01 00:00:00',
				'updated_at'        => '2026-07-01 00:00:00',
			);
			$this->assertFalse( $wpdb->insert( $invoices, $duplicate_invoice ) );
			$duplicate_payment = $duplicate_invoice;
			$duplicate_payment['square_invoice_id'] = 'a-different-invoice';
			$duplicate_payment['square_payment_id'] = str_repeat( 'x', 192 );
			$this->assertFalse( $wpdb->insert( $invoices, $duplicate_payment ) );
			$duplicate_attempt = array(
				'user_id'                => 868,
				'environment'            => 'production',
				'merchant_id'            => 'other-merchant',
				'square_customer_id'     => 'customer',
				'square_subscription_id' => 'different-subscription',
				'checkout_attempt_uuid'  => $attempt_uuid,
				'created_at'             => '2026-07-01 00:00:00',
				'updated_at'             => '2026-07-01 00:00:00',
			);
			$this->assertFalse( $wpdb->insert( $subscriptions, $duplicate_attempt ) );
			$duplicate_slot = array(
				'user_id'                => 866,
				'environment'            => 'sandbox',
				'merchant_id'            => 'long-id-merchant',
				'square_customer_id'     => 'customer-2',
				'square_subscription_id' => 'another-subscription',
				'current_slot'           => 1,
				'created_at'             => '2026-07-01 00:00:00',
				'updated_at'             => '2026-07-01 00:00:00',
			);
			$this->assertFalse( $wpdb->insert( $subscriptions, $duplicate_slot ) );
		} finally {
			$wpdb->suppress_errors( $old_suppress );
		}
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
		$other_merchant_subscription = $base_subscription;
		$other_merchant_subscription['merchant_id'] = 'merchant-test-other';
		$this->assertSame( 1, $wpdb->insert( $subscription_table, $other_merchant_subscription ) );

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
		$other_merchant_invoice = $base_invoice;
		$other_merchant_invoice['merchant_id'] = 'merchant-test-other';
		$this->assertSame( 1, $wpdb->insert( $invoice_table, $other_merchant_invoice ) );

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

	private function assertColumn( string $table, string $column_name, string $type, string $nullable, $default ): void {
		global $wpdb;

		$column = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column_name ),
			ARRAY_A
		);
		$this->assertIsArray( $column, $table . '.' . $column_name . ' exists' );
		$this->assertSame( $type, strtolower( $column['Type'] ), $table . '.' . $column_name . ' type' );
		$this->assertSame( $nullable, $column['Null'], $table . '.' . $column_name . ' nullability' );
		if ( null === $default ) {
			$this->assertNull( $column['Default'], $table . '.' . $column_name . ' default' );
		} else {
			$this->assertSame( (string) $default, (string) $column['Default'], $table . '.' . $column_name . ' default' );
		}
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
