<?php
/**
 * e-Gov Law Monitor - Database
 */

/**
 * Create law watch tables.
 */
function egov_law_monitor_create_tables() {

    global $wpdb;

    $table_name      = $wpdb->prefix . 'law_watch_settings';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        keyword VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY user_keyword (user_id, keyword),
        KEY user_id (user_id),
        KEY keyword (keyword)
    ) {$charset_collate};";

    dbDelta( $sql );

    $summary_table = $wpdb->prefix . 'egov_law_ai_summaries';

    $sql = "CREATE TABLE {$summary_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        law_id VARCHAR(32) NOT NULL,
        effective_date DATE NOT NULL,
        law_data_id VARCHAR(32) NOT NULL DEFAULT '',
        sub_revision VARCHAR(32) NOT NULL DEFAULT '',
        amendment_name TEXT NOT NULL,
        comparison_effective_date VARCHAR(10) NOT NULL DEFAULT '',
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY law_effective_revision (law_id, effective_date, law_data_id, sub_revision),
        KEY law_id (law_id),
        KEY effective_date (effective_date),
        KEY law_data_revision (law_data_id, sub_revision)
    ) {$charset_collate};";

    dbDelta( $sql );

    /*
     * Explicitly migrate the existing AI summary cache table.
     *
     * dbDelta() should normally add these columns, but older installations
     * may retain the legacy schema. Check the actual table and add anything
     * that is missing.
     */
    $summary_columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$summary_table}",
        ARRAY_A
    );

    $summary_column_names = array();

    foreach ( $summary_columns as $column ) {
        $summary_column_names[] = $column['Field'];
    }

    if ( ! in_array( 'law_data_id', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN law_data_id VARCHAR(32) NOT NULL DEFAULT ''
             AFTER effective_date"
        );
    }

    if ( ! in_array( 'sub_revision', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN sub_revision VARCHAR(32) NOT NULL DEFAULT ''
             AFTER law_data_id"
        );
    }

    if ( ! in_array( 'amendment_name', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN amendment_name TEXT NOT NULL
             AFTER sub_revision"
        );
    }

    if ( ! in_array( 'comparison_effective_date', $summary_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD COLUMN comparison_effective_date VARCHAR(10) NOT NULL DEFAULT ''
             AFTER amendment_name"
        );
    }

    /*
     * Remove the old unique key used by the previous cache design.
     *
     * The AI summary cache is now identified by:
     * law_id + effective_date + law_data_id + sub_revision.
     */
    $old_summary_key = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM {$summary_table}
             WHERE Key_name = %s",
            'law_effective_date'
        )
    );

    if ( $old_summary_key !== null ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             DROP INDEX law_effective_date"
        );
    }

    $summary_index = $wpdb->get_results(
        "SHOW INDEX FROM {$summary_table}
         WHERE Key_name = 'law_effective_revision'",
        ARRAY_A
    );

    if ( empty( $summary_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$summary_table}
             ADD UNIQUE KEY law_effective_revision (
                 law_id,
                 effective_date,
                 law_data_id,
                 sub_revision
             )"
        );
    }

    $history_table = $wpdb->prefix . 'egov_law_ai_summary_history';

    $sql = "CREATE TABLE {$history_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        law_id VARCHAR(32) NOT NULL,
        law_name TEXT NOT NULL,
        effective_date DATE NOT NULL,
        law_data_id VARCHAR(32) NOT NULL DEFAULT '',
        sub_revision VARCHAR(32) NOT NULL DEFAULT '',
        revision_hash CHAR(64) NOT NULL,
        summary_title TEXT NOT NULL,
        summary_body LONGTEXT NOT NULL,
        used_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_summary (user_id, law_id, effective_date, law_data_id, sub_revision, revision_hash),
        KEY user_id (user_id),
        KEY law_id (law_id),
        KEY effective_date (effective_date),
        KEY law_data_revision (law_data_id, sub_revision)
    ) {$charset_collate};";

    dbDelta( $sql );

    /*
     * Migrate existing AI summary history tables.
     *
     * Legacy installations may not yet have law_data_id/sub_revision,
     * and the old unique key used only revision_hash.
     */
    $history_columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$history_table}",
        ARRAY_A
    );

    $history_column_names = array();

    foreach ( $history_columns as $column ) {
        $history_column_names[] = $column['Field'];
    }

    if ( ! in_array( 'law_data_id', $history_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD COLUMN law_data_id VARCHAR(32) NOT NULL DEFAULT ''
             AFTER effective_date"
        );
    }

    if ( ! in_array( 'sub_revision', $history_column_names, true ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD COLUMN sub_revision VARCHAR(32) NOT NULL DEFAULT ''
             AFTER law_data_id"
        );
    }

    $history_index = $wpdb->get_results(
        "SHOW INDEX FROM {$history_table}
         WHERE Key_name = 'user_summary'",
        ARRAY_A
    );

    if ( ! empty( $history_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             DROP INDEX user_summary"
        );
    }

    $new_history_index = $wpdb->get_results(
        "SHOW INDEX FROM {$history_table}
         WHERE Key_name = 'user_summary'",
        ARRAY_A
    );

    if ( empty( $new_history_index ) ) {
        $wpdb->query(
            "ALTER TABLE {$history_table}
             ADD UNIQUE KEY user_summary (
                 user_id,
                 law_id,
                 effective_date,
                 law_data_id,
                 sub_revision,
                 revision_hash
             )"
        );
    }
}

/**
 * Create and verify the Square subscription-related tables.
 *
 * The SQL is intentionally compatible with WordPress 6.8.3 dbDelta(): each
 * index declaration is kept on one line. No foreign keys are used because
 * WordPress user deletion and cross-table consistency are handled by the
 * application layer.
 *
 * @return bool Whether every table, required index, and InnoDB engine is ready.
 */
function egov_law_monitor_create_subscription_tables() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$schemas         = egov_law_monitor_subscription_table_schemas( $charset_collate );

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	foreach ( $schemas as $table_name => $schema ) {
		$wpdb->last_error = '';
		dbDelta( $schema['sql'] );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		if ( ! egov_law_monitor_verify_subscription_table( $table_name, $schema ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Return the current versioned Square schema definitions.
 *
 * External identifiers are unique within a Square environment and merchant.
 * Timestamps are stored as UTC DATETIME values. `usage_month` is a DATE set
 * to the first day of the applicable WordPress-local calendar month.
 *
 * @param string $charset_collate Charset and collation from wpdb.
 * @return array<string,array{sql:string,columns:array,indexes:array}>
 */
function egov_law_monitor_subscription_table_schemas( $charset_collate ) {
	global $wpdb;

	$subscriptions = $wpdb->prefix . 'egov_law_subscriptions';
	$invoices      = $wpdb->prefix . 'egov_law_subscription_invoices';
	$square_events = $wpdb->prefix . 'egov_law_square_events';
	$usage         = $wpdb->prefix . 'egov_law_ai_summary_usage';

	/*
	 * Store only the normalized Square event fields required for retry.
	 * Future webhook code must exclude card numbers, security codes, and
	 * payment credentials before writing normalized_payload.
	 *
	 * For quota reservations, serialize by locking the stable wp_users row
	 * (`SELECT ID ... FOR UPDATE`) before counting/inserting usage rows. The
	 * WordPress users table must be InnoDB for that future transaction path.
	 */

	return array(
		$subscriptions => array(
			'sql'     => "CREATE TABLE {$subscriptions} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				environment VARCHAR(16) NOT NULL,
				merchant_id VARCHAR(64) NOT NULL,
				square_location_id VARCHAR(64) NOT NULL DEFAULT '',
				square_customer_id VARCHAR(64) NOT NULL,
				square_subscription_id VARCHAR(64) NOT NULL,
				square_plan_variation_id VARCHAR(64) NOT NULL DEFAULT '',
				plan_code VARCHAR(32) NOT NULL DEFAULT 'standard',
				internal_status VARCHAR(32) NOT NULL DEFAULT 'pending',
				square_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
				initial_payment_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
				current_slot TINYINT UNSIGNED NULL DEFAULT NULL,
				started_at DATETIME NULL DEFAULT NULL,
				current_period_start DATETIME NULL DEFAULT NULL,
				current_period_end DATETIME NULL DEFAULT NULL,
				cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
				cancel_scheduled_at DATETIME NULL DEFAULT NULL,
				canceled_at DATETIME NULL DEFAULT NULL,
				first_payment_failed_at DATETIME NULL DEFAULT NULL,
				grace_ends_at DATETIME NULL DEFAULT NULL,
				sync_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
				last_synced_at DATETIME NULL DEFAULT NULL,
				sync_error_code VARCHAR(100) NOT NULL DEFAULT '',
				last_square_event_at DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_subscription (environment, merchant_id, square_subscription_id),
				UNIQUE KEY current_user_subscription (user_id, environment, merchant_id, current_slot),
				KEY user_status (user_id, internal_status, current_slot),
				KEY customer_lookup (environment, merchant_id, square_customer_id),
				KEY period_end (internal_status, current_period_end),
				KEY sync_due (sync_state, last_synced_at)
			) ENGINE=InnoDB {$charset_collate};",
			'columns' => array(
				'id', 'user_id', 'environment', 'merchant_id', 'square_location_id',
				'square_customer_id', 'square_subscription_id', 'square_plan_variation_id',
				'plan_code', 'internal_status', 'square_status', 'initial_payment_status',
				'current_slot', 'started_at', 'current_period_start', 'current_period_end',
				'cancel_at_period_end', 'cancel_scheduled_at', 'canceled_at',
				'first_payment_failed_at', 'grace_ends_at', 'sync_state', 'last_synced_at',
				'sync_error_code', 'last_square_event_at', 'created_at', 'updated_at',
			),
			'indexes' => array(
				'PRIMARY'                   => array( true, array( 'id' ) ),
				'square_subscription'       => array( true, array( 'environment', 'merchant_id', 'square_subscription_id' ) ),
				'current_user_subscription' => array( true, array( 'user_id', 'environment', 'merchant_id', 'current_slot' ) ),
				'user_status'               => array( false, array( 'user_id', 'internal_status', 'current_slot' ) ),
				'customer_lookup'           => array( false, array( 'environment', 'merchant_id', 'square_customer_id' ) ),
				'period_end'                => array( false, array( 'internal_status', 'current_period_end' ) ),
				'sync_due'                  => array( false, array( 'sync_state', 'last_synced_at' ) ),
			),
		),
		$invoices => array(
			'sql'     => "CREATE TABLE {$invoices} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				environment VARCHAR(16) NOT NULL,
				merchant_id VARCHAR(64) NOT NULL,
				square_invoice_id VARCHAR(64) NOT NULL,
				square_payment_id VARCHAR(64) NULL DEFAULT NULL,
				invoice_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
				square_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
				payment_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
				amount BIGINT NOT NULL DEFAULT 0,
				currency CHAR(3) NOT NULL DEFAULT 'JPY',
				billing_period_start DATETIME NULL DEFAULT NULL,
				billing_period_end DATETIME NULL DEFAULT NULL,
				due_at DATETIME NULL DEFAULT NULL,
				paid_at DATETIME NULL DEFAULT NULL,
				failed_at DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_invoice (environment, merchant_id, square_invoice_id),
				UNIQUE KEY square_payment (environment, merchant_id, square_payment_id),
				KEY subscription_state (subscription_id, invoice_status, due_at),
				KEY user_period (user_id, billing_period_start),
				KEY unresolved_due (payment_status, due_at)
			) ENGINE=InnoDB {$charset_collate};",
			'columns' => array(
				'id', 'subscription_id', 'user_id', 'environment', 'merchant_id',
				'square_invoice_id', 'square_payment_id', 'invoice_status', 'square_status',
				'payment_status', 'amount', 'currency', 'billing_period_start',
				'billing_period_end', 'due_at', 'paid_at', 'failed_at', 'created_at', 'updated_at',
			),
			'indexes' => array(
				'PRIMARY'            => array( true, array( 'id' ) ),
				'square_invoice'     => array( true, array( 'environment', 'merchant_id', 'square_invoice_id' ) ),
				'square_payment'     => array( true, array( 'environment', 'merchant_id', 'square_payment_id' ) ),
				'subscription_state' => array( false, array( 'subscription_id', 'invoice_status', 'due_at' ) ),
				'user_period'        => array( false, array( 'user_id', 'billing_period_start' ) ),
				'unresolved_due'     => array( false, array( 'payment_status', 'due_at' ) ),
			),
		),
		$square_events => array(
			'sql'     => "CREATE TABLE {$square_events} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment VARCHAR(16) NOT NULL,
				merchant_id VARCHAR(64) NOT NULL,
				square_event_id VARCHAR(128) NOT NULL,
				event_type VARCHAR(100) NOT NULL,
				square_created_at DATETIME NULL DEFAULT NULL,
				received_at DATETIME NOT NULL,
				processing_status VARCHAR(20) NOT NULL DEFAULT 'received',
				retry_count INT UNSIGNED NOT NULL DEFAULT 0,
				next_retry_at DATETIME NULL DEFAULT NULL,
				processing_started_at DATETIME NULL DEFAULT NULL,
				processed_at DATETIME NULL DEFAULT NULL,
				payload_hash CHAR(64) NOT NULL DEFAULT '',
				normalized_payload LONGTEXT NULL,
				last_error_code VARCHAR(100) NOT NULL DEFAULT '',
				last_error_message TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_event (environment, merchant_id, square_event_id),
				KEY retry_queue (processing_status, next_retry_at),
				KEY event_type_received (event_type, received_at)
			) ENGINE=InnoDB {$charset_collate};",
			'columns' => array(
				'id', 'environment', 'merchant_id', 'square_event_id', 'event_type',
				'square_created_at', 'received_at', 'processing_status', 'retry_count',
				'next_retry_at', 'processing_started_at', 'processed_at', 'payload_hash',
				'normalized_payload', 'last_error_code', 'last_error_message', 'created_at', 'updated_at',
			),
			'indexes' => array(
				'PRIMARY'             => array( true, array( 'id' ) ),
				'square_event'        => array( true, array( 'environment', 'merchant_id', 'square_event_id' ) ),
				'retry_queue'         => array( false, array( 'processing_status', 'next_retry_at' ) ),
				'event_type_received' => array( false, array( 'event_type', 'received_at' ) ),
			),
		),
		$usage => array(
			'sql'     => "CREATE TABLE {$usage} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment VARCHAR(16) NOT NULL DEFAULT 'production',
				user_id BIGINT UNSIGNED NOT NULL,
				subscription_id BIGINT UNSIGNED NULL DEFAULT NULL,
				summary_history_id BIGINT UNSIGNED NULL DEFAULT NULL,
				request_key VARCHAR(64) NOT NULL,
				plan_code VARCHAR(16) NOT NULL,
				usage_month DATE NULL DEFAULT NULL,
				usage_status VARCHAR(16) NOT NULL DEFAULT 'reserved',
				reserved_at DATETIME NOT NULL,
				reservation_expires_at DATETIME NULL DEFAULT NULL,
				committed_at DATETIME NULL DEFAULT NULL,
				released_at DATETIME NULL DEFAULT NULL,
				expired_at DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_request (environment, user_id, request_key),
				KEY user_period_status (environment, user_id, plan_code, usage_month, usage_status),
				KEY reservation_expiry (usage_status, reservation_expires_at),
				KEY subscription_id (subscription_id),
				KEY summary_history_id (summary_history_id)
			) ENGINE=InnoDB {$charset_collate};",
			'columns' => array(
				'id', 'environment', 'user_id', 'subscription_id', 'summary_history_id', 'request_key',
				'plan_code', 'usage_month', 'usage_status', 'reserved_at',
				'reservation_expires_at', 'committed_at', 'released_at', 'expired_at',
				'created_at', 'updated_at',
			),
			'indexes' => array(
				'PRIMARY'              => array( true, array( 'id' ) ),
				'user_request'         => array( true, array( 'environment', 'user_id', 'request_key' ) ),
				'user_period_status'   => array( false, array( 'environment', 'user_id', 'plan_code', 'usage_month', 'usage_status' ) ),
				'reservation_expiry'   => array( false, array( 'usage_status', 'reservation_expires_at' ) ),
				'subscription_id'      => array( false, array( 'subscription_id' ) ),
				'summary_history_id'   => array( false, array( 'summary_history_id' ) ),
			),
		),
	);
}

/**
 * Verify an expected table, convert it to InnoDB if needed, and validate its schema.
 *
 * @param string $table_name Table name.
 * @param array  $schema     Expected columns and indexes.
 * @return bool
 */
function egov_law_monitor_verify_subscription_table( $table_name, $schema ) {
	global $wpdb;

	$table_status = $wpdb->get_row(
		$wpdb->prepare(
			'SHOW TABLE STATUS WHERE Name = %s',
			$table_name
		)
	);

	if ( ! is_object( $table_status ) ) {
		return false;
	}

	if ( 'INNODB' !== strtoupper( (string) $table_status->Engine ) ) {
		$wpdb->last_error = '';
		$altered          = $wpdb->query( "ALTER TABLE `{$table_name}` ENGINE=InnoDB" );

		if ( false === $altered || '' !== $wpdb->last_error ) {
			return false;
		}

		$table_status = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW TABLE STATUS WHERE Name = %s',
				$table_name
			)
		);

		if ( ! is_object( $table_status ) || 'INNODB' !== strtoupper( (string) $table_status->Engine ) ) {
			return false;
		}
	}

	$column_rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`" );
	if ( ! is_array( $column_rows ) ) {
		return false;
	}
	$actual_columns = array();
	foreach ( $column_rows as $column_row ) {
		$actual_columns[ $column_row->Field ] = $column_row;
	}
	$expected_columns = egov_law_monitor_subscription_column_definitions( $schema['sql'], $schema['columns'] );
	if ( count( $expected_columns ) !== count( $schema['columns'] ) ) {
		return false;
	}
	foreach ( $expected_columns as $column_name => $expected ) {
		if ( ! isset( $actual_columns[ $column_name ] ) ) {
			return false;
		}
		$actual = $actual_columns[ $column_name ];
		if (
			strtolower( (string) $actual->Type ) !== strtolower( $expected['type'] )
			|| ( 'YES' === $actual->Null ) !== $expected['nullable']
			|| (string) $actual->Default !== $expected['default']
		) {
			return false;
		}
	}

	$index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$table_name}`" );

	if ( ! is_array( $index_rows ) ) {
		return false;
	}

	$actual_indexes = array();
	foreach ( $index_rows as $index_row ) {
		$key_name = strtolower( $index_row->Key_name );

		if ( ! isset( $actual_indexes[ $key_name ] ) ) {
			$actual_indexes[ $key_name ] = array(
				'unique'  => '0' === (string) $index_row->Non_unique,
				'columns' => array(),
			);
		}

		$actual_indexes[ $key_name ]['columns'][ (int) $index_row->Seq_in_index ] = $index_row->Column_name;
	}
	$expected_index_names = array_map( 'strtolower', array_keys( $schema['indexes'] ) );
	$actual_index_names   = array_keys( $actual_indexes );
	sort( $expected_index_names );
	sort( $actual_index_names );
	if ( $expected_index_names !== $actual_index_names ) {
		return false;
	}

	foreach ( $schema['indexes'] as $expected_name => $expected_index ) {
		$key_name = strtolower( $expected_name );

		if ( ! isset( $actual_indexes[ $key_name ] ) ) {
			return false;
		}

		ksort( $actual_indexes[ $key_name ]['columns'] );

		if (
			$expected_index[0] !== $actual_indexes[ $key_name ]['unique']
			|| array_values( $expected_index[1] ) !== array_values( $actual_indexes[ $key_name ]['columns'] )
		) {
			return false;
		}
	}

	return true;
}

/**
 * Extract column type, nullability and default clauses from our one-column-per-line DDL.
 *
 * @param string $sql          CREATE TABLE statement.
 * @param array  $column_names Expected column names.
 * @return array<string,array{type:string,nullable:bool,default:string}>
 */
function egov_law_monitor_subscription_column_definitions( $sql, $column_names ) {
	$definitions = array();
	foreach ( preg_split( '/\r?\n/', $sql ) as $line ) {
		if ( ! preg_match( '/^\s+([a-z][a-z0-9_]*)\s+(.+?)[,]?\s*$/i', $line, $matches ) ) {
			continue;
		}
		$column_name = $matches[1];
		if ( ! in_array( $column_name, $column_names, true ) ) {
			continue;
		}
		$definition = trim( $matches[2] );
		if ( ! preg_match( '/^(\S+(?:\s+UNSIGNED)?)\s+(NOT NULL|NULL)(.*)$/i', $definition, $parts ) ) {
			continue;
		}
		$default = '';
		if ( preg_match( '/\bDEFAULT\s+(\'[^\']*\'|"[^"]*"|[^\s,]+)/i', $parts[3], $default_match ) ) {
			$default = trim( $default_match[1], "'\"" );
			if ( 'NULL' === strtoupper( $default ) ) {
				$default = '';
			}
		}
		$definitions[ $column_name ] = array(
			'type'     => preg_replace( '/\s+/', ' ', $parts[1] ),
			'nullable' => 'NULL' === strtoupper( $parts[2] ),
			'default'  => $default,
		);
	}
	return $definitions;
}

/**
 * Apply the current Square schema and record its version only after verification.
 *
 * DDL is not treated as transactional. If any table creation, engine conversion,
 * schema check, or version write fails, the option remains behind and the
 * idempotent dbDelta() migration can be retried on a later request.
 *
 * @return bool Whether the current subscription schema is installed.
 */
function egov_law_monitor_maybe_upgrade_subscription_schema() {
	global $wpdb;

	$schema_version_option = 'egov_law_monitor_square_schema_version';
	$current_version       = 1;
	$stored_version        = (int) get_option( $schema_version_option, 0 );

	if ( $stored_version > $current_version ) {
		return false;
	}

	if ( $stored_version === $current_version ) {
		$schemas        = egov_law_monitor_subscription_table_schemas( $wpdb->get_charset_collate() );
		$missing_tables = array();
		foreach ( $schemas as $table_name => $schema ) {
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
			if ( $table_exists !== $table_name ) {
				$missing_tables[ $table_name ] = $schema;
				continue;
			}
			if ( ! egov_law_monitor_verify_subscription_table( $table_name, $schema ) ) {
				error_log( 'e-Gov Law Monitor: Subscription schema validation failed for existing table ' . $table_name . ' (required column definition, index definition, or InnoDB engine mismatch). No automatic structural repair was attempted.' );
				return false;
			}
		}
		if ( $missing_tables ) {
			if ( ! egov_law_monitor_create_missing_subscription_tables( $missing_tables ) ) {
				error_log( 'e-Gov Law Monitor: Failed to recreate one or more missing subscription tables.' );
				return false;
			}
		}
	} elseif ( $stored_version < $current_version && ! egov_law_monitor_create_subscription_tables() ) {
		error_log( 'e-Gov Law Monitor: Subscription schema migration failed; the schema version was not advanced.' );
		return false;
	}

	$stored_version = (int) get_option( $schema_version_option, 0 );

	if ( $stored_version !== $current_version ) {
		$wpdb->last_error = '';
		$updated          = update_option( $schema_version_option, $current_version, false );

		if ( ! $updated && (int) get_option( $schema_version_option, 0 ) !== $current_version ) {
			return false;
		}
	}

	return true;
}

/** Create only absent subscription tables, without passing existing tables to dbDelta(). */
function egov_law_monitor_create_missing_subscription_tables( $missing_tables ) {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( $missing_tables as $table_name => $schema ) {
		$wpdb->last_error = '';
		dbDelta( $schema['sql'] );
		if ( '' !== $wpdb->last_error || ! egov_law_monitor_verify_subscription_table( $table_name, $schema ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Get cached AI summary.
 *
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_cache(
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summaries';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE law_id = %s
             AND effective_date = %s
             AND law_data_id = %s
             AND sub_revision = %s
             LIMIT 1",
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
        ),
        ARRAY_A
    );
}

/**
 * Save AI summary cache.
 *
 * @param string $law_id          Law ID.
 * @param string $effective_date  Effective date.
 * @param string $law_data_id     e-Gov law data ID.
 * @param string $sub_revision    e-Gov sub revision.
 * @param string $amendment_name  Amendment law name.
 * @param string $comparison_effective_date Comparison target effective date.
 * @param string $revision_hash   Revision hash.
 * @param string $summary_title   Summary title.
 * @param string $summary_body    Summary body.
 * @return int|false
 */
function egov_law_monitor_save_ai_summary_cache(
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision,
    $amendment_name,
    $comparison_effective_date,
    $revision_hash,
    $summary_title,
    $summary_body
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summaries';

    $now = current_time( 'mysql' );

    return $wpdb->replace(
        $table_name,
        array(
            'law_id'          => $law_id,
            'effective_date'  => $effective_date,
            'law_data_id'     => $law_data_id,
            'sub_revision'    => $sub_revision,
            'amendment_name'  => $amendment_name,
            'comparison_effective_date' => $comparison_effective_date,
            'revision_hash'   => $revision_hash,
            'summary_title'   => $summary_title,
            'summary_body'    => $summary_body,
            'created_at'      => $now,
            'updated_at'      => $now,
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Check whether a user has used an AI summary for the exact revision.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return bool
 */
function egov_law_monitor_has_ai_summary_history(
    $user_id,
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_name}
             WHERE user_id = %d
             AND law_id = %s
             AND effective_date = %s
             AND law_data_id = %s
             AND sub_revision = %s",
            $user_id,
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
        )
    );

    return (int) $count > 0;
}

/**
 * Get the user's existing AI summary history for an exact revision.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $law_data_id    e-Gov law data ID.
 * @param string $sub_revision   e-Gov sub revision.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_history_by_revision(
    $user_id,
    $law_id,
    $effective_date,
    $law_data_id,
    $sub_revision
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE user_id = %d
             AND law_id = %s
             AND effective_date = %s
             AND law_data_id = %s
             AND sub_revision = %s
             ORDER BY used_at DESC
             LIMIT 1",
            $user_id,
            $law_id,
            $effective_date,
            $law_data_id,
            $sub_revision
        ),
        ARRAY_A
    );
}

/**
 * Save AI summary usage history.
 *
 * @param int    $user_id        User ID.
 * @param string $law_id         Law ID.
 * @param string $effective_date Effective date.
 * @param string $revision_hash  Revision hash.
 * @return int|false
 */
function egov_law_monitor_save_ai_summary_history(
    $user_id,
    $law_id,
    $law_name,
    $effective_date,
    $law_data_id,
    $sub_revision,
    $revision_hash,
    $summary_title,
    $summary_body
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->replace(
        $table_name,
        array(
            'user_id'        => $user_id,
            'law_id'         => $law_id,
            'law_name'       => $law_name,
            'effective_date' => $effective_date,
            'law_data_id'   => $law_data_id,
            'sub_revision'  => $sub_revision,
            'revision_hash'  => $revision_hash,
            'summary_title'  => $summary_title,
            'summary_body'   => $summary_body,
            'used_at'        => current_time( 'mysql' ),
        ),
        array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Get AI summary usage count for a user.
 *
 * @param int $user_id User ID.
 * @return int
 */
function egov_law_monitor_get_ai_summary_usage_count(
    $user_id
) {
    if ( ! function_exists( 'egov_law_monitor_ai_usage_service' ) ) {
        return 0;
    }
    $count = egov_law_monitor_ai_usage_service()->count_usage( $user_id, 'standard' );
    return is_wp_error( $count ) ? 100 : $count;
}

/**
 * Get an AI summary usage count for an already-resolved plan without hiding errors.
 *
 * Free usage includes the legacy lifetime trial counter in production. Standard
 * usage is counted by the usage service for the current WordPress-local month.
 *
 * @param int    $user_id   User ID.
 * @param string $plan_code Resolved plan code (`free` or `standard`).
 * @return int|WP_Error Usage count, or the underlying retrieval error.
 */
function egov_law_monitor_get_ai_summary_usage_count_for_plan( $user_id, $plan_code ) {
	if ( ! in_array( $plan_code, array( 'free', 'standard' ), true ) ) {
		return new WP_Error( 'ai_summary_usage_plan_invalid', 'AI要約の利用プランが不正です。' );
	}

	if ( ! function_exists( 'egov_law_monitor_ai_usage_service' ) ) {
		return new WP_Error( 'ai_summary_usage_service_unavailable', 'AI要約の利用状況を取得できません。' );
	}

	$count = egov_law_monitor_ai_usage_service()->count_usage( $user_id, $plan_code );
	if ( is_wp_error( $count ) ) {
		return $count;
	}
	if ( ! is_int( $count ) || $count < 0 ) {
		return new WP_Error( 'ai_summary_usage_count_invalid', 'AI要約の利用状況を取得できません。' );
	}

	if ( 'free' === $plan_code ) {
		$environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : 'production';
		if ( 'production' === $environment ) {
			$count += max( 0, (int) get_user_meta( $user_id, 'egov_law_monitor_ai_summary_trial_usage', true ) );
		}
	}

	return $count;
}

/**
 * Get the lifetime AI summary trial usage count for a user.
 *
 * @param int $user_id User ID.
 * @return int
 */
function egov_law_monitor_get_ai_summary_trial_usage_count( $user_id ) {
    $environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : 'production';
    $legacy_count = 'production' === $environment ? max(
        0,
        (int) get_user_meta(
            $user_id,
            'egov_law_monitor_ai_summary_trial_usage',
            true
        )
    ) : 0;
    if ( ! function_exists( 'egov_law_monitor_ai_usage_service' ) ) {
        return $legacy_count;
    }
    $ledger_count = egov_law_monitor_ai_usage_service()->count_usage( $user_id, 'free' );
    return $legacy_count + ( is_wp_error( $ledger_count ) ? max( 0, 3 - $legacy_count ) : $ledger_count );
}

/**
 * Increment the lifetime AI summary trial usage count for a user.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function egov_law_monitor_increment_ai_summary_trial_usage_count( $user_id ) {
    $current_count = egov_law_monitor_get_ai_summary_trial_usage_count( $user_id );

    return update_user_meta(
        $user_id,
        'egov_law_monitor_ai_summary_trial_usage',
        $current_count + 1
    ) !== false;
}

/**
 * Clear AI summary usage history for the current month.
 *
 * @param int $user_id User ID.
 * @return int|false Number of deleted rows, or false on error.
 */
function egov_law_monitor_clear_ai_summary_usage( $user_id ) {

    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $now = current_time( 'timestamp' );

    $month_start = wp_date(
        'Y-m-01 00:00:00',
        $now
    );

    $next_month_start = wp_date(
        'Y-m-01 00:00:00',
        strtotime( '+1 month', $now )
    );

    return $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name}
             WHERE user_id = %d
             AND used_at >= %s
             AND used_at < %s",
            $user_id,
            $month_start,
            $next_month_start
        )
    );
}

/**
 * Get AI summary usage history for a user.
 *
 * @param int $user_id User ID.
 * @return array
 */
/**
 * Get one AI summary history record for the current user.
 *
 * @param int $user_id    User ID.
 * @param int $history_id History record ID.
 * @return array|null
 */
function egov_law_monitor_get_ai_summary_history_by_id(
    $user_id,
    $history_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE id = %d
             AND user_id = %d
             LIMIT 1",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
}

/**
 * Get AI summary usage history for a user.
 *
 * @param int $user_id User ID.
 * @return array
 */
function egov_law_monitor_get_ai_summary_history(
    $user_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE user_id = %d
             ORDER BY used_at DESC",
            $user_id
        ),
        ARRAY_A
    );
}

/**
 * Get the total number of AI summary history records for a user.
 *
 * @param int $user_id User ID.
 * @return int
 */
function egov_law_monitor_get_ai_summary_history_count(
    $user_id
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_name}
             WHERE user_id = %d",
            $user_id
        )
    );

    return (int) $count;
}

/**
 * Get one page of AI summary usage history for a user.
 *
 * @param int $user_id   User ID.
 * @param int $per_page  Number of records per page.
 * @param int $offset    Offset.
 * @return array
 */
function egov_law_monitor_get_ai_summary_history_page(
    $user_id,
    $per_page,
    $offset
) {
    global $wpdb;

    $table_name =
        $wpdb->prefix . 'egov_law_ai_summary_history';

    $per_page = max( 1, (int) $per_page );
    $offset   = max( 0, (int) $offset );

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE user_id = %d
             ORDER BY used_at DESC, id DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ),
        ARRAY_A
    );
}

/**
 * Delete law watch settings when a user is deleted.
 */
function egov_law_monitor_delete_user_data( $user_id ) {

    global $wpdb;

    $table_name = $wpdb->prefix . 'law_watch_settings';

    $wpdb->delete(
        $table_name,
        array(
            'user_id' => $user_id,
        ),
        array(
            '%d',
        )
    );

    $usage_table = $wpdb->prefix . 'egov_law_ai_summary_usage';
    $wpdb->delete(
        $usage_table,
        array( 'user_id' => absint( $user_id ) ),
        array( '%d' )
    );
}

add_action(
    'delete_user',
    'egov_law_monitor_delete_user_data'
);
