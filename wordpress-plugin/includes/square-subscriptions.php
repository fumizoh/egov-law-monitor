<?php
/** Square contract storage. No Square API or entitlement changes. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Expected physical schema for the two contract-management tables. */
function egov_law_monitor_square_columns() {
    return array(
        'egov_square_subscriptions' => array(
            'id' => array( 'bigint unsigned', false ),
            'user_id' => array( 'bigint unsigned', false ),
            'square_customer_id' => array( 'varchar(255)', false ),
            'square_subscription_id' => array( 'varchar(255)', false ),
            'created_at' => array( 'datetime', false ),
            'updated_at' => array( 'datetime', false ),
            'verified_at' => array( 'datetime', true ),
            'verified_by' => array( 'bigint unsigned', true ),
            'admin_note' => array( 'text', true ),
        ),
        'egov_square_admin_log' => array(
            'id' => array( 'bigint unsigned', false ),
            'user_id' => array( 'bigint unsigned', false ),
            'admin_user_id' => array( 'bigint unsigned', false ),
            'action' => array( 'varchar(50)', false ),
            'old_value' => array( 'longtext', true ),
            'new_value' => array( 'longtext', true ),
            'reason' => array( 'text', true ),
            'created_at' => array( 'datetime', false ),
        ),
    );
}

/** Validate actual columns and full-column indexes, never just a version option. */
function egov_law_monitor_square_storage_ready( $database = null ) {
    global $wpdb;
    $db = $database ?: $wpdb;
    foreach ( egov_law_monitor_square_columns() as $suffix => $expected ) {
        $table = $db->prefix . $suffix;
        $identifier = '`' . str_replace( '`', '``', $table ) . '`';
        $status = $db instanceof EGOV_Law_Monitor_Square_Connection
            ? ( $db->rows( 'SHOW TABLE STATUS WHERE Name = ?', array( $table ) )[0] ?? null )
            : $db->get_row(
            $db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ),
            ARRAY_A
        );
        if ( $db->last_error !== '' || ! is_array( $status ) || strcasecmp( $status['Engine'] ?? '', 'InnoDB' ) !== 0 ) {
            return false;
        }
        $columns = $db instanceof EGOV_Law_Monitor_Square_Connection ? $db->rows( "SHOW FULL COLUMNS FROM {$identifier}" ) : $db->get_results( "SHOW FULL COLUMNS FROM {$identifier}", ARRAY_A );
        if ( $db->last_error !== '' || ! is_array( $columns ) ) {
            return false;
        }
        $by_name = array_column( $columns, null, 'Field' );
        foreach ( $expected as $name => $definition ) {
            $column = $by_name[ $name ] ?? array();
            $type = preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $column['Type'] ?? '' ) );
            if ( $type !== $definition[0] || ( $column['Null'] ?? '' ) !== ( $definition[1] ? 'YES' : 'NO' ) ) {
                return false;
            }
            if ( $name === 'id' && stripos( $column['Extra'] ?? '', 'auto_increment' ) === false ) {
                return false;
            }
        }
        $indexes = $db instanceof EGOV_Law_Monitor_Square_Connection ? $db->rows( "SHOW INDEX FROM {$identifier}" ) : $db->get_results( "SHOW INDEX FROM {$identifier}", ARRAY_A );
        if ( $db->last_error !== '' || ! is_array( $indexes ) ) {
            return false;
        }
        $groups = array();
        foreach ( $indexes as $index ) {
            $groups[ $index['Key_name'] ][] = $index;
        }
        $primary = false;
        $user_index = false;
        $subscription_unique = false;
        foreach ( $groups as $name => $parts ) {
            usort( $parts, static function ( $a, $b ) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; } );
            $first = $parts[0];
            $full = ( $first['Sub_part'] ?? null ) === null;
            $visible = ( $first['Visible'] ?? 'YES' ) !== 'NO' && ( $first['Ignored'] ?? 'NO' ) !== 'YES';
            $primary = $primary || ( $name === 'PRIMARY' && count( $parts ) === 1 && $first['Column_name'] === 'id' && (int) $first['Non_unique'] === 0 && $full );
            $user_index = $user_index || ( $first['Column_name'] === 'user_id' && $full && $visible );
            $subscription_unique = $subscription_unique || ( count( $parts ) === 1 && $first['Column_name'] === 'square_subscription_id' && (int) $first['Non_unique'] === 0 && $full );
        }
        if ( ! $primary || ! $user_index || ( $suffix === 'egov_square_subscriptions' && ! $subscription_unique ) ) {
            return false;
        }
    }
    return true;
}

/** Runs on activation and after updates, without rerunning legacy migrations. */
function egov_law_monitor_install_square_tables() {
    global $wpdb;
    if ( get_option( 'egov_law_monitor_square_schema_version' ) === '2' && egov_law_monitor_square_storage_ready() ) {
        return;
    }
    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    $charset = $wpdb->get_charset_collate();
    $subscriptions = $wpdb->prefix . 'egov_square_subscriptions';
    $log = $wpdb->prefix . 'egov_square_admin_log';
    dbDelta( "CREATE TABLE {$subscriptions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        square_customer_id VARCHAR(255) NOT NULL,
        square_subscription_id VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        verified_by BIGINT UNSIGNED NULL,
        admin_note TEXT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY square_subscription_id (square_subscription_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB {$charset};" );
    dbDelta( "CREATE TABLE {$log} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        admin_user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL,
        old_value LONGTEXT NULL,
        new_value LONGTEXT NULL,
        reason TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( egov_law_monitor_square_storage_ready() ) {
        update_option( 'egov_law_monitor_square_schema_version', '2', false );
    }
}
add_action( 'admin_init', 'egov_law_monitor_install_square_tables' );
register_activation_hook( dirname( __DIR__ ) . '/egov-law-monitor.php', 'egov_law_monitor_install_square_tables' );

add_action( 'admin_notices', function () {
    if ( current_user_can( 'manage_options' ) && ! egov_law_monitor_square_storage_ready() ) {
        echo '<div class="notice notice-error"><p>Square契約管理のDBスキーマが不完全です。カラム・主キー・インデックス・UNIQUE制約・InnoDBを確認してください。契約情報は保存できません。</p></div>';
    }
} );

require_once __DIR__ . '/square-connection.php';

/** Signed full-row snapshot; normalize numeric DB values across readers. */
function egov_law_monitor_square_record_token( $record, $key = null ) {
    ksort( $record );
    foreach ( $record as &$value ) {
        $value = $value === null ? null : (string) $value;
    }
    unset( $value );
    return hash_hmac( 'sha256', wp_json_encode( $record ), $key === null ? wp_salt( 'auth' ) : $key );
}

/** Persist validated contract and audit inside caller-owned transaction. */
function egov_law_monitor_square_store_contract( $db, $table, $log_table, $user_id, $id, $old, $customer_id, $subscription_id, $note, $now, $admin_id ) {
    if ( $old ) {
        $saved = $db->write( "UPDATE {$table} SET square_customer_id = ?, square_subscription_id = ?, admin_note = ?, updated_at = ? WHERE id = ? AND user_id = ?", array( $customer_id, $subscription_id, $note, $now, (int) $id, (int) $user_id ) );
    } else {
        $saved = $db->write( "INSERT INTO {$table} (square_customer_id, square_subscription_id, admin_note, updated_at, user_id, created_at, verified_at, verified_by) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)", array( $customer_id, $subscription_id, $note, $now, (int) $user_id, $now ) );
        $id = (int) $db->insert_id;
    }
    if ( $saved === false ) {
        throw new RuntimeException( '契約を保存できませんでした。Subscription IDの重複またはDBエラーを確認してください。' );
    }
    $new = $db->rows( "SELECT * FROM {$table} WHERE id = ?", array( $id ) )[0] ?? null;
    if ( ! $new ) {
        throw new RuntimeException( '保存結果を取得できませんでした。' );
    }
    $old_json = $old === null ? null : wp_json_encode( $old );
    $new_json = wp_json_encode( $new );
    if ( $old_json === false || $new_json === false || $db->write(
        "INSERT INTO {$log_table} (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)",
        array( (int) $user_id, (int) $admin_id, $old ? 'subscription_updated' : 'subscription_created', $old_json, $new_json, $now )
    ) === false ) {
        throw new RuntimeException( '操作履歴を保存できませんでした。' );
    }
    return $new;
}
