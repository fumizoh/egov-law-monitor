<?php
/** Administrator-triggered, read-only Square subscription lookup. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Secrets live in server configuration, never WordPress options or HTML. */
function egov_law_monitor_square_api_settings() {
    $environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : null;
    if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
        return new WP_Error( 'square_api_not_configured', 'Square APIの環境設定を確認してください。sandbox または production が必要です。' );
    }
    if ( $environment === 'sandbox' ) {
        $token = defined( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX' ) ? EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX : null;
    } else {
        $token = defined( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION' ) ? EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION : null;
    }
    if ( ! is_string( $token ) || $token === '' || strlen( $token ) > 4096 || preg_match( '/[^\x21-\x7e]/', $token ) ) {
        return new WP_Error( 'square_api_not_configured', '選択したSquare環境のアクセストークンが未設定、または形式が不正です。サーバー設定を確認してください。' );
    }
    return array( 'token' => $token, 'environment' => $environment );
}

/** Validate only displayable identifiers, not arbitrary API payloads. */
function egov_law_monitor_square_api_id_valid( $value ) {
    return is_string( $value ) && strlen( $value ) <= 255 && preg_match( '/\A[A-Za-z0-9_-]+\z/', $value );
}

/** No raw transport messages, response bodies or credentials leave this function. */
function egov_law_monitor_lookup_square_subscription( $user_id, $record_id, $nonce, $record_token ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'square_lookup_forbidden', 'Square契約を照会する権限がありません。' );
    }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! is_int( $record_id ) || $record_id < 1 || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_square_lookup_' . $user_id . '_' . $record_id ) ) {
        return new WP_Error( 'square_lookup_invalid_request', '照会リクエストを確認できません。画面を再読み込みしてください。' );
    }
    if ( ! get_userdata( $user_id ) ) {
        return new WP_Error( 'square_lookup_invalid_user', '対象ユーザーが存在しません。' );
    }
    global $wpdb;
    $table = $wpdb->prefix . 'egov_square_subscriptions';
    $query = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $record_id, $user_id );
    $record = $wpdb->get_row( $query, ARRAY_A );
    if ( $wpdb->last_error !== '' ) {
        return new WP_Error( 'square_lookup_storage_error', '登録済み契約の読み取りに失敗しました。' );
    }
    if ( ! is_array( $record ) || (int) $record['user_id'] !== $user_id ) {
        return new WP_Error( 'square_lookup_record_not_found', '対象ユーザーの登録済み契約が見つかりません。' );
    }
    if ( ! is_string( $record_token ) || ! hash_equals( egov_law_monitor_square_record_token( $record ), $record_token ) ) {
        return new WP_Error( 'square_lookup_stale_record', '契約情報が変更されています。画面を再読み込みして照会してください。' );
    }
    if ( ! egov_law_monitor_square_api_id_valid( $record['square_subscription_id'] ) || ! egov_law_monitor_square_api_id_valid( $record['square_customer_id'] ) ) {
        return new WP_Error( 'square_lookup_invalid_ids', '登録済みCustomer ID・Subscription IDを確認してください。' );
    }
    $settings = egov_law_monitor_square_api_settings();
    if ( is_wp_error( $settings ) ) { return $settings; }
    $origin = $settings['environment'] === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    try {
        $response = wp_safe_remote_get( $origin . '/v2/subscriptions/' . rawurlencode( $record['square_subscription_id'] ), array(
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => 65536,
        'headers' => array(
            'Authorization' => 'Bearer ' . $settings['token'],
            'Square-Version' => '2026-09-16',
            'Accept' => 'application/json',
        ),
        ) );
    } catch ( Throwable $error ) {
        // HTTP extensions can throw; never propagate their credential-bearing details.
        $response = new WP_Error( 'square_transport_exception', 'Squareとの通信に失敗しました。' );
    }
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'square_lookup_transport_error', 'Squareとの通信に失敗しました。タイムアウトやネットワーク接続を確認し、必要に応じて再操作してください。' );
    }
    $status = (int) wp_remote_retrieve_response_code( $response );
    $errors = array(
        401 => 'Square APIの認証に失敗しました。トークンと環境を確認してください。',
        403 => 'Square APIの照会権限がありません。SUBSCRIPTIONS_READ権限を確認してください。',
        404 => 'Square契約が見つかりません。Subscription ID・環境・Squareアカウントを確認してください。',
        429 => 'Square APIの呼び出し制限に達しました。時間を置いて再操作してください。',
    );
    if ( $status !== 200 ) {
        return new WP_Error( 'square_lookup_http_error', $errors[$status] ?? 'Square APIでエラーが発生しました。時間を置いて再操作してください。' );
    }
    $body = wp_remote_retrieve_body( $response );
    $data = is_string( $body ) && strlen( $body ) <= 65536 ? json_decode( $body, true, 32 ) : null;
    $subscription = is_array( $data ) ? ( $data['subscription'] ?? null ) : null;
    $invalid = new WP_Error( 'square_lookup_invalid_response', 'Square APIの応答を確認できません。契約情報を表示できませんでした。' );
    if ( ! is_array( $subscription ) || ! empty( $data['errors'] ) || ! isset( $subscription['id'], $subscription['status'] ) || $subscription['id'] !== $record['square_subscription_id'] || ! is_string( $subscription['status'] ) || ! preg_match( '/\A[A-Z_]{1,64}\z/', $subscription['status'] ) ) {
        return $invalid;
    }
    foreach ( array( 'customer_id', 'plan_variation_id', 'plan_id' ) as $key ) {
        if ( array_key_exists( $key, $subscription ) && ! egov_law_monitor_square_api_id_valid( $subscription[$key] ) ) { return $invalid; }
    }
    $result = array(
        'subscription_id' => $subscription['id'],
        'status' => $subscription['status'],
        'registered_customer_id' => $record['square_customer_id'],
        'customer_id' => $subscription['customer_id'] ?? '',
        'customer_matches' => isset( $subscription['customer_id'] ) ? $subscription['customer_id'] === $record['square_customer_id'] : null,
        'plan_variation_id' => $subscription['plan_variation_id'] ?? '',
        'plan_id' => $subscription['plan_id'] ?? '',
        'environment' => $settings['environment'],
        'queried_at' => current_time( 'mysql' ),
    );
    // Even a reflected secret in an otherwise valid field must not reach HTML.
    foreach ( $result as $value ) {
        if ( is_string( $value ) && strpos( $value, $settings['token'] ) !== false ) { return $invalid; }
    }
    $current = $wpdb->get_row( $query, ARRAY_A );
    if ( $wpdb->last_error !== '' || ! is_array( $current ) || ! hash_equals( egov_law_monitor_square_record_token( $current ), $record_token ) ) {
        return new WP_Error( 'square_lookup_stale_record', '照会中に契約情報が変更されたか、再確認できませんでした。画面を再読み込みしてください。' );
    }
    return $result;
}
