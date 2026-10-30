<?php

use PHPUnit\Framework\TestCase;

final class AISummaryUsageTest extends TestCase {
	private $user_id = 0;
	private $service;
	private $table = '';
	private $old_timezone = null;
	private $timezone_changed = false;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->table = $wpdb->prefix . 'egov_law_ai_summary_usage';
		$this->assertSame( 'egov_law_monitor_test', $wpdb->get_var( 'SELECT DATABASE()' ) );
		egov_law_monitor_create_tables();
		$this->assertTrue( egov_law_monitor_maybe_upgrade_subscription_schema() );
		$this->old_timezone = get_option( 'timezone_string' );
		$this->timezone_changed = true;
		update_option( 'timezone_string', 'America/Los_Angeles' );
		$suffix = wp_generate_password( 12, false, false );
		$created_user_id = wp_insert_user(
			array(
				'user_login' => 'aiusage-' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'aiusage-' . $suffix . '@example.org',
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $created_user_id ), is_wp_error( $created_user_id ) ? $created_user_id->get_error_message() : '' );
		$this->user_id = (int) $created_user_id;
		$this->service = new Egov_Law_Monitor_AI_Summary_Usage_Service( $wpdb, 'sandbox' );
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( DB_NAME === 'egov_law_monitor_test' && DB_HOST === 'wp-test-db' && DB_USER === 'egov_test' ) {
			$expected_table = $wpdb->prefix . 'egov_law_ai_summary_usage';
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $expected_table ) ) );
			if ( $this->table === $expected_table && '' !== $this->table && $table_exists === $expected_table && $this->user_id > 0 ) {
				$wpdb->delete( $this->table, array( 'user_id' => $this->user_id ), array( '%d' ) );
			}
			if ( $this->user_id > 0 ) {
				wp_delete_user( $this->user_id );
			}
			if ( $this->timezone_changed ) {
				if ( null === $this->old_timezone ) { delete_option( 'timezone_string' ); }
				else { update_option( 'timezone_string', $this->old_timezone ); }
			}
		}
		parent::tearDown();
	}

	private function key( $value ) { return hash( 'sha256', $value ); }
	private function fixed_time( $value ) { return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ); }

	public function test_free_quota_counts_reservations_and_commits_and_rejects_fourth(): void {
		$now = $this->fixed_time( '2026-06-15 12:00:00' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$reservation = $this->service->reserve( $this->user_id, 'free', $this->key( 'free-' . $i ), $now );
			$this->assertIsArray( $reservation );
			$this->assertIsArray( $this->service->commit_with_history( $reservation['id'], $this->user_id, function () use ( $i ) { return $i; }, $now ) );
		}
		$denied = $this->service->reserve( $this->user_id, 'free', $this->key( 'free-4' ), $now );
		$this->assertTrue( is_wp_error( $denied ) );
		$this->assertSame( 'usage_limit_reached', $denied->get_error_code() );
	}

	public function test_standard_quota_allows_one_hundred_and_rejects_one_hundred_and_first(): void {
		$now = $this->fixed_time( '2026-06-15 12:00:00' );
		for ( $i = 1; $i <= 100; $i++ ) {
			$reservation = $this->service->reserve( $this->user_id, 'standard', $this->key( 'standard-' . $i ), $now );
			$this->assertIsArray( $reservation );
			$this->assertSame( '2026-06-01', $this->get_usage_month( $reservation['id'] ) );
			$this->assertIsArray( $this->service->commit_with_history( $reservation['id'], $this->user_id, function () use ( $i ) { return $i; }, $now ) );
		}
		$denied = $this->service->reserve( $this->user_id, 'standard', $this->key( 'standard-101' ), $now );
		$this->assertTrue( is_wp_error( $denied ) );
		$this->assertSame( 'usage_limit_reached', $denied->get_error_code() );
	}

	public function test_free_ledger_does_not_count_toward_standard_monthly_quota(): void {
		$now = $this->fixed_time( '2026-06-30 23:30:00' );
		$free = $this->service->reserve( $this->user_id, 'free', $this->key( 'free-before-standard' ), $now );
		$this->assertIsArray( $free );
		$this->assertIsArray( $this->service->commit_with_history( $free['id'], $this->user_id, function () { return 1; }, $now ) );
		$this->assertSame( 0, $this->service->count_usage( $this->user_id, 'standard', $now ) );
		$standard = $this->service->reserve( $this->user_id, 'standard', $this->key( 'standard-after-free' ), $now );
		$this->assertIsArray( $standard );
		$this->assertSame( 1, $this->service->count_usage( $this->user_id, 'standard', $now ) );
	}

	public function test_duplicate_reservation_commit_and_release_are_idempotent(): void {
		$now = $this->fixed_time( '2026-06-15 12:00:00' );
		$key = $this->key( 'same-request' );
		$reservation = $this->service->reserve( $this->user_id, 'free', $key, $now );
		$duplicate = $this->service->reserve( $this->user_id, 'free', $key, $now );
		$this->assertSame( $reservation['id'], $duplicate['id'] );
		$first = $this->service->commit_with_history( $reservation['id'], $this->user_id, function () { return 42; }, $now );
		$second = $this->service->commit_with_history( $reservation['id'], $this->user_id, function () { return 99; }, $now );
		$this->assertSame( 42, $first['history_id'] );
		$this->assertTrue( $second['already_committed'] );
		$this->assertSame( 1, $this->service->count_usage( $this->user_id, 'free', $now ) );

		$released = $this->service->reserve( $this->user_id, 'free', $this->key( 'release' ), $now );
		$this->assertTrue( $this->service->release( $released['id'], $this->user_id, $now ) );
		$this->assertTrue( $this->service->release( $released['id'], $this->user_id, $now ) );
		$this->assertSame( 1, $this->service->count_usage( $this->user_id, 'free', $now ) );
	}

	public function test_expired_reservation_is_reclaimed_and_does_not_count(): void {
		$reservation = $this->service->reserve( $this->user_id, 'free', $this->key( 'expire' ), $this->fixed_time( '2026-06-15 12:00:00' ) );
		$this->assertSame( 1, $this->service->expire_reservations( $this->fixed_time( '2026-06-15 12:16:00' ) ) );
		$this->assertSame( 0, $this->service->count_usage( $this->user_id, 'free', $this->fixed_time( '2026-06-15 12:16:00' ) ) );
		$again = $this->service->reserve( $this->user_id, 'free', $this->key( 'expire' ), $this->fixed_time( '2026-06-15 12:17:00' ) );
		$this->assertSame( $reservation['id'], $again['id'] );
	}

	public function test_failed_commit_can_be_released_without_consuming_quota(): void {
		$now = $this->fixed_time( '2026-06-15 12:00:00' );
		$reservation = $this->service->reserve( $this->user_id, 'free', $this->key( 'failed-generation' ), $now );
		$failed = $this->service->commit_with_history( $reservation['id'], $this->user_id, function () { return false; }, $now );
		$this->assertTrue( is_wp_error( $failed ) );
		$this->assertTrue( $this->service->release( $reservation['id'], $this->user_id, $now ) );
		$this->assertSame( 0, $this->service->count_usage( $this->user_id, 'free', $now ) );
	}

	public function test_standard_month_uses_wordpress_timezone_at_month_boundary(): void {
		$before = $this->service->reserve( $this->user_id, 'standard', $this->key( 'month-before' ), $this->fixed_time( '2026-07-01 06:59:59' ) );
		$after = $this->service->reserve( $this->user_id, 'standard', $this->key( 'month-after' ), $this->fixed_time( '2026-07-01 07:00:00' ) );
		$this->assertSame( '2026-06-01', $this->get_usage_month( $before['id'] ) );
		$this->assertSame( '2026-07-01', $this->get_usage_month( $after['id'] ) );
	}

	public function test_database_error_fails_closed(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE `{$this->table}`" );
		$result = $this->service->reserve( $this->user_id, 'free', $this->key( 'db-error' ), $this->fixed_time( '2026-06-15 12:00:00' ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNotSame( 'success', $result->get_error_code() );
		$this->assertTrue( egov_law_monitor_create_subscription_tables() );
	}

	public function test_two_independent_process_connections_cannot_exceed_free_limit(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is required for the independent-connection concurrency test.' );
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		for ( $i = 1; $i <= 2; $i++ ) {
			$reservation = $this->service->reserve( $this->user_id, 'free', $this->key( 'concurrent-seed-' . $i ), $now );
			$this->assertIsArray( $reservation );
			$this->assertIsArray( $this->service->commit_with_history( $reservation['id'], $this->user_id, function () use ( $i ) { return $i; }, $now ) );
		}

		$worker = __DIR__ . '/usage-reservation-worker.php';
		$barrier_id = bin2hex( random_bytes( 8 ) );
		$barrier_dir = sys_get_temp_dir() . '/egov-ai-usage-' . $barrier_id;
		$this->assertTrue( mkdir( $barrier_dir, 0700 ) );
		$processes = array();
		for ( $i = 1; $i <= 2; $i++ ) {
			$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
			$process = proc_open( array( PHP_BINARY, $worker, (string) $this->user_id, $this->key( 'concurrent-worker-' . $i ), (string) $i, $barrier_id ), $descriptors, $pipes );
			$this->assertIsResource( $process );
			fclose( $pipes[0] );
			$processes[] = array( $process, $pipes[1], $pipes[2] );
		}
		$ready_deadline = microtime( true ) + 10;
		while ( microtime( true ) < $ready_deadline ) {
			if ( file_exists( $barrier_dir . '/ready-1' ) && file_exists( $barrier_dir . '/ready-2' ) ) {
				break;
			}
			usleep( 10000 );
		}
		$both_ready = file_exists( $barrier_dir . '/ready-1' ) && file_exists( $barrier_dir . '/ready-2' );
		touch( $barrier_dir . '/go' );
		$results = array();
		foreach ( $processes as $entry ) {
			$stdout = stream_get_contents( $entry[1] );
			$stderr = stream_get_contents( $entry[2] );
			fclose( $entry[1] );
			fclose( $entry[2] );
			$results[] = array(
				'exit_code' => proc_close( $entry[0] ),
				'stdout' => $stdout,
				'stderr' => $stderr,
			);
		}
		foreach ( glob( $barrier_dir . '/*' ) as $barrier_file ) { unlink( $barrier_file ); }
		rmdir( $barrier_dir );
		$diagnostics = array();
		foreach ( $results as $index => $result ) {
			$diagnostics[] = sprintf(
				'worker=%d argv=[%s,%s,%d,%s,%d,%s] db=%s barrier=%s ready=%s exit=%d stdout=%s stderr=%s',
				$index + 1,
				PHP_BINARY,
				$worker,
				$this->user_id,
				$this->key( 'concurrent-worker-' . ( $index + 1 ) ),
				$index + 1,
				$barrier_id,
				'egov_law_monitor_test@wp-test-db',
				$barrier_dir,
				file_exists( $barrier_dir . '/ready-' . ( $index + 1 ) ) ? 'yes' : 'no',
				$result['exit_code'],
				trim( $result['stdout'] ),
				trim( $result['stderr'] )
			);
		}
		$this->assertTrue( $both_ready, "Both independent workers must reach the barrier.\n" . implode( "\n", $diagnostics ) );
		foreach ( $results as $result ) {
			$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		}
		$worker_results = array_map( function ( $result ) { return json_decode( $result['stdout'], true ); }, $results );
		$reserved = count( array_filter( $worker_results, function ( $result ) { return isset( $result['reserved'] ) && true === $result['reserved']; } ) );
		$denied = count( array_filter( $worker_results, function ( $result ) { return isset( $result['error'] ) && 'usage_limit_reached' === $result['error']; } ) );
		$this->assertSame( 1, $reserved );
		$this->assertSame( 1, $denied );
		$this->assertSame( 3, $this->service->count_usage( $this->user_id, 'free', $now ) );
	}

	private function get_usage_month( $id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT usage_month FROM {$this->table} WHERE id = %d", $id ) );
	}
}
