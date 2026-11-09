<?php
/** Checkout applications, not payment or entitlement state. No automatic API calls. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_checkout_columns() {
    return array( 'id' => array( 'bigint unsigned', false ), 'application_uuid' => array( 'char(36)', false ),
        'user_id' => array( 'bigint unsigned', false ), 'environment' => array( 'varchar(10)', false ),
        'request_json' => array( 'longtext', false ), 'payment_link_id' => array( 'varchar(255)', true ),
        'template_order_id' => array( 'varchar(255)', true ), 'checkout_url' => array( 'text', true ),
        'state' => array( 'varchar(20)', false ), 'claim_token' => array( 'char(32)', false ),
        'claimed_at' => array( 'datetime', false ), 'created_at' => array( 'datetime', false ), 'updated_at' => array( 'datetime', false ) );
}

function egov_law_monitor_checkout_storage_ready( $db = null ) {
    global $wpdb;
    $db = $db ?: $wpdb;
    $table = $db->prefix . 'egov_square_checkout_attempts';
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { return false; }
    $native = $db instanceof EGOV_Law_Monitor_Square_Connection;
    $status = $native ? ( $db->rows( 'SHOW TABLE STATUS WHERE Name = ?', array( $table ) )[0] ?? null ) : $db->get_row( $db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
    if ( $db->last_error !== '' || ! $status || strcasecmp( $status['Engine'] ?? '', 'InnoDB' ) !== 0 ) { return false; }
    $columns = $native ? $db->rows( "SHOW FULL COLUMNS FROM `{$table}`" ) : $db->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
    if ( ! is_array( $columns ) || $db->last_error !== '' ) { return false; }
    $columns = array_column( $columns, null, 'Field' );
    foreach ( egov_law_monitor_checkout_columns() as $name => $spec ) {
        $column = $columns[$name] ?? array();
        if ( preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $column['Type'] ?? '' ) ) !== $spec[0] || ( $column['Null'] ?? '' ) !== ( $spec[1] ? 'YES' : 'NO' ) || ( $name === 'id' && stripos( $column['Extra'] ?? '', 'auto_increment' ) === false ) ) { return false; }
    }
    $indexes = $native ? $db->rows( "SHOW INDEX FROM `{$table}`" ) : $db->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
    if ( ! is_array( $indexes ) || $db->last_error !== '' ) { return false; }
    $groups = array(); $found = array();
    foreach ( $indexes as $row ) { $groups[$row['Key_name']][] = $row; }
    foreach ( $groups as $name => $parts ) {
        usort( $parts, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
        if ( array_filter( $parts, static fn( $p ) => ( $p['Sub_part'] ?? null ) !== null || ( $p['Visible'] ?? 'YES' ) === 'NO' || ( $p['Ignored'] ?? 'NO' ) === 'YES' ) ) { continue; }
        $names = array_column( $parts, 'Column_name' );
        if ( $name === 'PRIMARY' && $names === array( 'id' ) && (int) $parts[0]['Non_unique'] === 0 ) { $found['primary'] = true; }
        if ( $names === array( 'application_uuid' ) && (int) $parts[0]['Non_unique'] === 0 ) { $found['uuid'] = true; }
        if ( $names === array( 'environment', 'payment_link_id' ) && (int) $parts[0]['Non_unique'] === 0 ) { $found['link'] = true; }
        if ( $names[0] === 'user_id' ) { $found['user'] = true; }
    }
    return count( $found ) === 4;
}

function egov_law_monitor_install_checkout_table() {
    global $wpdb;
    if ( get_option( 'egov_law_monitor_checkout_schema_version' ) === '1' && egov_law_monitor_checkout_storage_ready() ) { return; }
    if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
    $table = $wpdb->prefix . 'egov_square_checkout_attempts';
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        application_uuid CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        environment VARCHAR(10) NOT NULL,
        request_json LONGTEXT NOT NULL,
        payment_link_id VARCHAR(255) NULL,
        template_order_id VARCHAR(255) NULL,
        checkout_url TEXT NULL,
        state VARCHAR(20) NOT NULL,
        claim_token CHAR(32) NOT NULL,
        claimed_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY application_uuid (application_uuid),
        UNIQUE KEY environment_link (environment,payment_link_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB {$charset};" );
    if ( egov_law_monitor_checkout_storage_ready() ) { update_option( 'egov_law_monitor_checkout_schema_version', '1', false ); }
}
add_action( 'admin_init', 'egov_law_monitor_install_checkout_table' );
register_activation_hook( dirname( __DIR__ ) . '/egov-law-monitor.php', 'egov_law_monitor_install_checkout_table' );

/** Server constants only; enabling confirms the merchant checked monthly/tax settings. */
function egov_law_monitor_checkout_settings() {
    $settings = egov_law_monitor_square_api_settings();
    if ( is_wp_error( $settings ) ) { return $settings; }
    if ( ! defined( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED' ) || EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED !== true ) { return new WP_Error( 'checkout_disabled', '申込受付は準備中です。' ); }
    $suffix = strtoupper( $settings['environment'] );
    foreach ( array( 'location_id' => 'LOCATION_ID', 'variation_id' => 'PLAN_VARIATION_ID' ) as $key => $constant ) {
        $constant = 'EGOV_LAW_MONITOR_SQUARE_' . $constant . '_' . $suffix;
        $value = defined( $constant ) ? constant( $constant ) : null;
        if ( ! egov_law_monitor_square_api_id_valid( $value ) ) { return new WP_Error( 'checkout_not_configured', '申込受付の設定を確認できません。管理者へお問い合わせください。' ); }
        $settings[$key] = $value;
    }
    $url = defined( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' ) ? EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL : null;
    $validation = egov_law_monitor_checkout_config_checks();
    if ( ! $validation['return_url_valid'] ) { return new WP_Error( 'checkout_not_configured', '申込受付の設定を確認できません。管理者へお問い合わせください。' ); }
    $settings['return_url'] = $url;
    return $settings;
}

/** Shared predicates: boolean results only; no SQL or API requests. */
function egov_law_monitor_checkout_config_checks() {
    $environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : null;
    $suffix = in_array( $environment, array( 'sandbox', 'production' ), true ) ? strtoupper( $environment ) : '';
    $location = 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_' . $suffix;
    $variation = 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_' . $suffix;
    $url = defined( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' ) ? EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL : null;
    $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
    $home = wp_parse_url( home_url( '/' ) );
    $host_matches = $parts && ( $parts['host'] ?? '' ) === ( $home['host'] ?? '' );
    $port_matches = $parts && ( $parts['port'] ?? 443 ) === ( $home['port'] ?? 443 );
    return array(
        'location_id_valid' => $suffix !== '' && egov_law_monitor_square_api_id_valid( defined( $location ) ? constant( $location ) : null ),
        'plan_variation_id_valid' => $suffix !== '' && egov_law_monitor_square_api_id_valid( defined( $variation ) ? constant( $variation ) : null ),
        'return_url_valid' => (bool) ( $parts && ( $parts['scheme'] ?? '' ) === 'https' && $host_matches && $port_matches && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] ) && strlen( $url ) <= 2048 && ! preg_match( '/[\x00-\x20\x7f]/', $url ) ),
        'return_url_host_matches' => (bool) $host_matches,
        'return_url_port_matches' => (bool) $port_matches,
    );
}

function egov_law_monitor_checkout_uuid_valid( $value ) {
    return is_string( $value ) && preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
}

function egov_law_monitor_checkout_request( $settings, $uuid ) {
    return array( 'version' => '2026-09-16', 'body' => array( 'idempotency_key' => $uuid,
        'order' => array( 'location_id' => $settings['location_id'], 'reference_id' => $uuid,
            'line_items' => array( array( 'name' => 'Standard 月額500円（税込）', 'quantity' => '1', 'base_price_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ),
        'checkout_options' => array( 'subscription_plan_id' => $settings['variation_id'], 'allow_tipping' => false, 'ask_for_shipping_address' => false, 'redirect_url' => $settings['return_url'] ) ) );
}

/** Never accept a redirect destination supplied by a buyer or arbitrary API response. */
function egov_law_monitor_checkout_url_valid( $url, $environment ) {
    if ( ! is_string( $url ) || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) { return false; }
    $p = wp_parse_url( $url );
    $hosts = $environment === 'sandbox' ? array( 'sandbox.square.link', 'checkout.squareupsandbox.com', 'sandbox.checkout.square.site' ) : ( $environment === 'production' ? array( 'square.link', 'checkout.square.site' ) : array() );
    return is_array( $p ) && ( $p['scheme'] ?? '' ) === 'https' && in_array( $p['host'] ?? '', $hosts, true ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) && ! isset( $p['port'] );
}

/** The only write API in this feature. Transport details and raw bodies stay private. */
function egov_law_monitor_create_checkout_link( $settings, $attempt ) {
    $request = json_decode( $attempt['request_json'], true );
    if ( ! in_array( $settings['environment'] ?? null, array( 'sandbox', 'production' ), true ) || $attempt['environment'] !== $settings['environment'] || ! egov_law_monitor_checkout_uuid_valid( $attempt['application_uuid'] ) || $request !== egov_law_monitor_checkout_request( $settings, $attempt['application_uuid'] ) ) { return new WP_Error( 'checkout_request_changed', '申込時の設定と一致しません。管理者へお問い合わせください。' ); }
    $origin = $settings['environment'] === 'sandbox' ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    try {
        $response = wp_safe_remote_post( $origin . '/v2/online-checkout/payment-links', array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 131072,
            'headers' => array( 'Authorization' => 'Bearer ' . $settings['token'], 'Square-Version' => $request['version'], 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => wp_json_encode( $request['body'] ) ) );
    } catch ( Throwable $error ) { $response = new WP_Error( 'transport', '通信結果不明' ); }
    if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) { return new WP_Error( 'checkout_api_failed', 'リンク発行を確認できません。同じ申込の再確認、または管理者へのお問い合わせが必要です。' ); }
    $body = wp_remote_retrieve_body( $response );
    $data = is_string( $body ) && strlen( $body ) <= 131072 ? json_decode( $body, true, 32 ) : null;
    $link = is_array( $data ) ? ( $data['payment_link'] ?? array() ) : array();
    $valid = empty( $data['errors'] ) && egov_law_monitor_square_api_id_valid( $link['id'] ?? null ) && egov_law_monitor_square_api_id_valid( $link['order_id'] ?? null ) && egov_law_monitor_checkout_url_valid( $link['url'] ?? null, $settings['environment'] );
    $order_ok = false;
    foreach ( is_array( $data['related_resources']['orders'] ?? null ) ? $data['related_resources']['orders'] : array() as $order ) {
        if ( is_array( $order ) && ( $order['id'] ?? null ) === ( $link['order_id'] ?? null ) && ( $order['reference_id'] ?? null ) === $attempt['application_uuid'] && ( $order['location_id'] ?? null ) === $settings['location_id'] && ( $order['total_money']['amount'] ?? null ) === 500 && ( $order['total_money']['currency'] ?? null ) === 'JPY' ) { $order_ok = true; }
    }
    if ( ! $valid || ! $order_ok ) { return new WP_Error( 'checkout_invalid_response', '発行結果のID・金額・申込参照を確認できません。管理者へお問い合わせください。' ); }
    $result = array( 'payment_link_id' => $link['id'], 'template_order_id' => $link['order_id'], 'checkout_url' => $link['url'] );
    foreach ( $result as $value ) { if ( strpos( $value, $settings['token'] ) !== false ) { return new WP_Error( 'checkout_invalid_response', '発行結果を確認できません。' ); } }
    return $result;
}

/** Test factory replaces only the owned session; no WP transaction is ever started. */
function egov_law_monitor_checkout_transaction( $operation, $factory = null ) {
    global $wpdb;
    $db = null; $started = false; $committing = false;
    try {
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        $tables = egov_law_monitor_square_plan_tables();
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) || ! egov_law_monitor_checkout_storage_ready( $db ) ) { throw new RuntimeException(); }
        if ( ! $db->begin() ) { throw new RuntimeException(); }
        $started = true;
        $result = $operation( $db, $tables, '`' . $db->prefix . 'egov_square_checkout_attempts`' );
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException(); }
        return $result;
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $ignored ) {} }
        $unknown = $started && ( $committing || ! $rolled_back );
        error_log( 'e-Gov Square: checkout storage ' . ( $unknown ? 'outcome unknown' : 'failed' ) );
        return new WP_Error( 'checkout_storage_error', '申込の保存結果を確認できません。新しい申込を作らず、画面を再読み込みして管理者へお問い合わせください。' );
    } finally { if ( $db ) { $db->close(); } }
}

function egov_law_monitor_checkout_public_result( $row ) {
    if ( ! egov_law_monitor_checkout_uuid_valid( $row['application_uuid'] ?? null ) || ! in_array( $row['state'] ?? '', array( 'creating', 'ready', 'unknown', 'closed', 'cancel_requested', 'closing', 'canceling', 'cancel_unknown' ), true ) || ( $row['state'] === 'ready' && ! egov_law_monitor_checkout_url_valid( $row['checkout_url'] ?? null, $row['environment'] ?? '' ) ) ) { return new WP_Error( 'checkout_invalid_state', '申込情報を確認できません。管理者へお問い合わせください。' ); }
    return array( 'application_uuid' => $row['application_uuid'], 'state' => $row['state'], 'checkout_url' => $row['state'] === 'ready' ? $row['checkout_url'] : '' );
}

/** Explicit POST only. Persist UUID+payload before HTTP, and claim to serialize clicks. */
function egov_law_monitor_submit_checkout( $nonce, $retry_uuid = '', $factory = null, $transport = null ) {
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 || ! get_userdata( $user_id ) ) { return new WP_Error( 'checkout_login_required', 'ログインしてください。' ); }
    if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_square_checkout_' . $user_id ) || ! is_string( $retry_uuid ) || ( $retry_uuid !== '' && ! egov_law_monitor_checkout_uuid_valid( $retry_uuid ) ) ) { return new WP_Error( 'checkout_invalid_request', '画面を再読み込みして操作してください。' ); }
    $settings = egov_law_monitor_checkout_settings();
    if ( is_wp_error( $settings ) ) { return $settings; }
    $reserved = egov_law_monitor_checkout_transaction( static function ( $db, $tables, $table ) use ( $user_id, $settings, $retry_uuid ) {
        $users = $db->rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ) );
        if ( ! $users || (int) $users[0]['ID'] !== $user_id ) { throw new RuntimeException(); }
        $plan = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
        if ( $plan['plan'] !== 'free' ) { return new WP_Error( 'checkout_free_only', 'Freeプランのユーザーのみ申込できます。' ); }
        $contracts = $db->rows( "SELECT id FROM `{$tables['contracts']}` WHERE user_id = ? LIMIT 1", array( $user_id ) );
        if ( $contracts === false ) { throw new RuntimeException(); }
        if ( $contracts ) { return new WP_Error( 'checkout_existing_contract', '既存の契約があります。新しい申込は行わず管理者へお問い合わせください。' ); }
        $rows = $db->rows( "SELECT * FROM {$table} WHERE user_id = ? AND state <> 'closed' ORDER BY id DESC LIMIT 2 FOR UPDATE", array( $user_id ) );
        if ( ! is_array( $rows ) ) { throw new RuntimeException(); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $claim = bin2hex( random_bytes( 16 ) );
        if ( $rows ) {
            $row = $rows[0];
            if ( count( $rows ) !== 1 || (int) $row['user_id'] !== $user_id || $row['environment'] !== $settings['environment'] || ( $retry_uuid !== '' && $retry_uuid !== $row['application_uuid'] ) || ! egov_law_monitor_checkout_uuid_valid( $row['application_uuid'] ) || json_decode( $row['request_json'], true ) !== egov_law_monitor_checkout_request( $settings, $row['application_uuid'] ) ) { return new WP_Error( 'checkout_conflict', '申込時の環境・設定または所有者を確認できません。管理者へお問い合わせください。' ); }
            if ( $row['state'] === 'ready' ) { return array( 'send' => false, 'row' => $row ); }
            $created = strtotime( $row['created_at'] . ' UTC' ); $claimed = strtotime( $row['claimed_at'] . ' UTC' );
            if ( ! $created || ! $claimed || $created > time() || $claimed > time() || time() - $created > 900 || ! in_array( $row['state'], array( 'creating', 'unknown' ), true ) ) { return new WP_Error( 'checkout_review_required', '未解決の申込があります。新しいリンクを発行せず管理者へお問い合わせください。' ); }
            if ( time() - $claimed < ( $row['state'] === 'creating' ? 90 : 30 ) ) { return new WP_Error( 'checkout_busy', '申込を処理中です。しばらく待ってから同じ申込を再確認してください。' ); }
            if ( ! $db->write( "UPDATE {$table} SET state = 'creating', claim_token = ?, claimed_at = ?, updated_at = ? WHERE id = ? AND user_id = ?", array( $claim, $now, $now, (int) $row['id'], $user_id ) ) ) { throw new RuntimeException(); }
            $row['claim_token'] = $claim;
        } else {
            if ( $retry_uuid !== '' ) { return new WP_Error( 'checkout_wrong_owner', 'このユーザーの申込が見つかりません。' ); }
            $uuid = wp_generate_uuid4();
            if ( ! egov_law_monitor_checkout_uuid_valid( $uuid ) ) { throw new RuntimeException(); }
            $json = wp_json_encode( egov_law_monitor_checkout_request( $settings, $uuid ) );
            if ( $json === false || ! $db->write( "INSERT INTO {$table} (application_uuid, user_id, environment, request_json, state, claim_token, claimed_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'creating', ?, ?, ?, ?)", array( $uuid, $user_id, $settings['environment'], $json, $claim, $now, $now, $now ) ) ) { throw new RuntimeException(); }
            $row = array( 'id' => (int) $db->insert_id, 'user_id' => $user_id, 'application_uuid' => $uuid, 'environment' => $settings['environment'], 'request_json' => $json, 'claim_token' => $claim );
        }
        return array( 'send' => true, 'row' => $row );
    }, $factory );
    if ( is_wp_error( $reserved ) ) { return $reserved; }
    if ( ! $reserved['send'] ) { return egov_law_monitor_checkout_public_result( $reserved['row'] ); }
    $row = $reserved['row'];
    // Both DB sessions are closed here; HTTP never runs under a transaction.
    try { $link = $transport ? $transport( $settings, $row ) : egov_law_monitor_create_checkout_link( $settings, $row ); }
    catch ( Throwable $error ) { $link = new WP_Error( 'checkout_api_failed', '発行結果を確認できません。' ); }
    $stored = egov_law_monitor_checkout_transaction( static function ( $db, $tables, $table ) use ( $user_id, $row, $link ) {
        $current = $db->rows( "SELECT * FROM {$table} WHERE id = ? AND user_id = ? FOR UPDATE", array( (int) $row['id'], $user_id ) );
        $current = $current[0] ?? null;
        if ( ! $current || $current['application_uuid'] !== $row['application_uuid'] || $current['request_json'] !== $row['request_json'] || $current['environment'] !== $row['environment'] || $current['state'] !== 'creating' || ! hash_equals( $current['claim_token'], $row['claim_token'] ) ) { throw new RuntimeException(); }
        $state = is_wp_error( $link ) ? 'unknown' : 'ready';
        if ( ! $db->write( "UPDATE {$table} SET state = ?, payment_link_id = ?, template_order_id = ?, checkout_url = ?, updated_at = ? WHERE id = ? AND user_id = ? AND claim_token = ?", array( $state, is_wp_error( $link ) ? null : $link['payment_link_id'], is_wp_error( $link ) ? null : $link['template_order_id'], is_wp_error( $link ) ? null : $link['checkout_url'], gmdate( 'Y-m-d H:i:s' ), (int) $row['id'], $user_id, $row['claim_token'] ) ) ) { throw new RuntimeException(); }
        $saved = $db->rows( "SELECT * FROM {$table} WHERE id = ? AND user_id = ?", array( (int) $row['id'], $user_id ) );
        if ( ! $saved || $saved[0]['state'] !== $state ) { throw new RuntimeException(); }
        foreach ( array( 'payment_link_id', 'template_order_id', 'checkout_url' ) as $key ) {
            if ( ( $saved[0][$key] ?? null ) !== ( is_wp_error( $link ) ? null : $link[$key] ) ) { throw new RuntimeException(); }
        }
        return $saved[0];
    }, $factory );
    if ( is_wp_error( $stored ) ) { return $stored; }
    return is_wp_error( $link ) ? $link : egov_law_monitor_checkout_public_result( $stored );
}
