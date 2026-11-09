<?php
/** Administrator-triggered lookup and explicit registration; no Square mutations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_checkout_contract_error( $code, $message ) { return new WP_Error( 'checkout_contract_' . $code, $message . ' 既存の手動契約登録も利用できます。' ); }

/** Read only this administrator-selected user's ready application. */
function egov_law_monitor_checkout_contract_attempt( $user_id, $attempt_id, $record_token ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_square_checkout_attempts WHERE user_id = %d AND state <> 'closed' ORDER BY id DESC LIMIT 2", $user_id ), ARRAY_A );
    $row = is_array( $rows ) && count( $rows ) === 1 ? $rows[0] : null;
    if ( $wpdb->last_error !== '' || ! $row || (int) $row['id'] !== $attempt_id || (int) $row['user_id'] !== $user_id || $row['state'] !== 'ready' ||
        ! hash_equals( egov_law_monitor_square_record_token( $row ), $record_token ) || ! egov_law_monitor_checkout_uuid_valid( $row['application_uuid'] ) ||
        ! egov_law_monitor_square_api_id_valid( $row['payment_link_id'] ) || ! egov_law_monitor_square_api_id_valid( $row['template_order_id'] ) ) {
        return egov_law_monitor_checkout_contract_error( 'stale', '対象・申込状態を確認できません。画面を再読み込みしてください。' );
    }
    return $row;
}

/** Fixed read endpoints, bounded responses, sanitized errors. POST search is read-only. */
function egov_law_monitor_checkout_contract_api( $settings, $path, $body, $timeout ) {
    $search = $path === '/v2/subscriptions/search';
    if ( ! in_array( $settings['environment'] ?? '', array( 'sandbox', 'production' ), true ) ||
        ( ! $search && ! preg_match( '~^/v2/orders/[A-Za-z0-9_-]+$~D', $path ) ) ) { return egov_law_monitor_checkout_contract_error( 'request', '照会先を確認できません。' ); }
    $args = array( 'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 262144,
        'headers' => array( 'Authorization' => 'Bearer ' . $settings['token'], 'Square-Version' => '2026-09-16', 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) );
    try {
        $url = ( $settings['environment'] === 'sandbox' ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com' ) . $path;
        if ( $search ) { $args['body'] = wp_json_encode( $body ); $response = wp_safe_remote_post( $url, $args ); }
        else { $response = wp_safe_remote_get( $url, $args ); }
    } catch ( Throwable $error ) { return egov_law_monitor_checkout_contract_error( 'transport', 'Squareとの通信結果を確認できません。' ); }
    if ( is_wp_error( $response ) ) { return egov_law_monitor_checkout_contract_error( 'transport', 'Squareとの通信結果を確認できません。' ); }
    $status = (int) wp_remote_retrieve_response_code( $response );
    if ( $status !== 200 ) {
        $messages = array( 401 => 'Square認証を確認してください。', 403 => 'Square照会権限を確認してください。', 404 => 'Square側の対象情報が見つかりません。', 429 => 'Squareの呼び出し制限に達しました。時間を置いてください。' );
        return egov_law_monitor_checkout_contract_error( 'http', $messages[$status] ?? 'Square API照会に失敗しました。' );
    }
    $raw = wp_remote_retrieve_body( $response );
    $data = is_string( $raw ) && strlen( $raw ) <= 262144 ? json_decode( $raw, true, 32 ) : null;
    if ( ! is_array( $data ) || ! empty( $data['errors'] ) || strpos( $raw, $settings['token'] ) !== false ) { return egov_law_monitor_checkout_contract_error( 'response', 'Square応答を確認できません。' ); }
    return $data;
}

function egov_law_monitor_checkout_contract_search( $row ) {
    $settings = egov_law_monitor_square_api_settings();
    if ( is_wp_error( $settings ) ) { return egov_law_monitor_checkout_contract_error( 'settings', 'Squareの照会設定を確認してください。' ); }
    if ( $settings['environment'] !== $row['environment'] ) { return egov_law_monitor_checkout_contract_error( 'environment', '保存環境と照会環境が一致しません。' ); }
    $request = json_decode( $row['request_json'], true, 32 );
    $location = $request['body']['order']['location_id'] ?? null;
    $variation = $request['body']['checkout_options']['subscription_plan_id'] ?? null;
    if ( ! egov_law_monitor_square_api_id_valid( $location ) || ! egov_law_monitor_square_api_id_valid( $variation ) ||
        ( $request['body']['order']['reference_id'] ?? null ) !== $row['application_uuid'] || ( $request['body']['idempotency_key'] ?? null ) !== $row['application_uuid'] ) {
        return egov_law_monitor_checkout_contract_error( 'snapshot', '保存済み申込内容を確認できません。' );
    }
    // Check the original template/reference, not the paid invoice order.
    $deadline = microtime( true ) + 30;
    $order = egov_law_monitor_checkout_contract_api( $settings, '/v2/orders/' . $row['template_order_id'], null, 10 );
    if ( is_wp_error( $order ) ) { return $order; }
    $order = $order['order'] ?? null;
    if ( ! is_array( $order ) || ( $order['id'] ?? null ) !== $row['template_order_id'] || ( $order['reference_id'] ?? null ) !== $row['application_uuid'] || ( $order['location_id'] ?? null ) !== $location ) {
        return egov_law_monitor_checkout_contract_error( 'order', '注文テンプレートと申込UUID・Locationが一致しません。' );
    }
    $cursor = null; $seen = array(); $matches = array(); $complete = false;
    for ( $page = 0; $page < 5; ++$page ) {
        $remaining = $deadline - microtime( true );
        if ( $remaining < 1 ) { break; }
        $body = array( 'query' => array( 'filter' => array( 'location_ids' => array( $location ) ) ), 'limit' => 100 );
        if ( $cursor !== null ) { $body['cursor'] = $cursor; }
        $data = egov_law_monitor_checkout_contract_api( $settings, '/v2/subscriptions/search', $body, min( 10, $remaining ) );
        if ( is_wp_error( $data ) ) { return $data; }
        if ( array_key_exists( 'subscriptions', $data ) && ( ! is_array( $data['subscriptions'] ) || array_values( $data['subscriptions'] ) !== $data['subscriptions'] || count( $data['subscriptions'] ) > 100 ) ) { return egov_law_monitor_checkout_contract_error( 'response', '契約一覧の応答を確認できません。' ); }
        foreach ( $data['subscriptions'] ?? array() as $sub ) {
            if ( ! is_array( $sub ) || ! egov_law_monitor_square_api_id_valid( $sub['id'] ?? null ) || ! is_array( $sub['phases'] ?? null ) || array_values( $sub['phases'] ) !== $sub['phases'] || ! $sub['phases'] ) {
                return egov_law_monitor_checkout_contract_error( 'incomplete', 'phase情報が不足しているため、検索の完全性を確認できません。' );
            }
            $matched = false;
            foreach ( $sub['phases'] as $phase ) {
                if ( ! is_array( $phase ) || ! egov_law_monitor_square_api_id_valid( $phase['order_template_id'] ?? null ) ) { return egov_law_monitor_checkout_contract_error( 'incomplete', 'phase情報を確認できません。' ); }
                if ( $phase['order_template_id'] === $row['template_order_id'] ) { $matched = true; }
            }
            if ( $matched ) {
                foreach ( array( 'customer_id', 'location_id', 'plan_variation_id' ) as $key ) { if ( ! egov_law_monitor_square_api_id_valid( $sub[$key] ?? null ) ) { return egov_law_monitor_checkout_contract_error( 'response', '候補のID情報が不足しています。' ); } }
                if ( $sub['location_id'] !== $location || $sub['plan_variation_id'] !== $variation || ! is_string( $sub['status'] ?? null ) || ! preg_match( '/\A[A-Z_]{1,64}\z/', $sub['status'] ) ) { return egov_law_monitor_checkout_contract_error( 'mismatch', '候補のLocation・Variation・状態が整合しません。' ); }
                $candidate = array();
                foreach ( array( 'id', 'customer_id', 'location_id', 'plan_variation_id', 'status' ) as $key ) { $candidate[$key] = $sub[$key]; }
                if ( isset( $matches[$sub['id']] ) && $matches[$sub['id']] !== $candidate ) { return egov_law_monitor_checkout_contract_error( 'incomplete', '検索中に同じ契約の情報が変わりました。再照会してください。' ); }
                $matches[$sub['id']] = $candidate;
            }
        }
        $cursor = $data['cursor'] ?? null;
        if ( $cursor === null || $cursor === '' ) { $complete = true; break; }
        if ( ! is_string( $cursor ) || strlen( $cursor ) > 2048 || isset( $seen[$cursor] ) ) { return egov_law_monitor_checkout_contract_error( 'incomplete', 'ページングを確認できません。' ); }
        $seen[$cursor] = true;
    }
    if ( ! $complete || microtime( true ) > $deadline ) { return egov_law_monitor_checkout_contract_error( 'incomplete', '検索上限に達しました。一意照合は未完了です。' ); }
    if ( count( $matches ) !== 1 ) { return egov_law_monitor_checkout_contract_error( count( $matches ) ? 'multiple' : 'none', count( $matches ) ? '複数の契約が一致しました。登録候補を確定できません。' : '一致する契約を検出できません。未決済・契約なしとは断定できません。' ); }
    $candidate = reset( $matches );
    if ( $candidate['status'] !== 'ACTIVE' ) { return egov_law_monitor_checkout_contract_error( 'status', '契約状態がACTIVEではないため、登録候補にできません。' ); }
    return $candidate;
}

function egov_law_monitor_checkout_candidate_signature( $candidate ) {
    return hash_hmac( 'sha256', wp_json_encode( $candidate ), wp_salt( 'auth' ) );
}

/** Run only inside the existing contract-save transaction, after schema validation. */
function egov_law_monitor_checkout_contract_lock( $db, $user_id, $context ) {
    $tables = egov_law_monitor_square_plan_tables();
    $users = $db->rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( (int) $user_id ) );
    $rows = $db->rows( "SELECT * FROM `{$db->prefix}egov_square_checkout_attempts` WHERE user_id = ? AND state <> 'closed' ORDER BY id DESC LIMIT 2 FOR UPDATE", array( (int) $user_id ) );
    $row = is_array( $rows ) && count( $rows ) === 1 ? $rows[0] : null;
    if ( ! $users || ! is_array( $context ) || ! $row || $row['state'] !== 'ready' || (int) $row['user_id'] !== (int) $user_id ||
        (int) $row['id'] !== ( $context['attempt_id'] ?? null ) || $row['application_uuid'] !== ( $context['application_uuid'] ?? null ) ||
        $row['environment'] !== ( $context['environment'] ?? null ) || ! defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) || $row['environment'] !== EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT ||
        $row['template_order_id'] !== ( $context['template_order_id'] ?? null ) || ! is_string( $context['record_token'] ?? null ) ||
        ! hash_equals( egov_law_monitor_square_record_token( $row ), $context['record_token'] ) ) { throw new RuntimeException( '保存前に申込情報が変わりました。再照会してください。' ); }
}

/** Repeat the full read-only lookup at confirmation; never trust browser IDs. */
function egov_law_monitor_checkout_contract_operation( $user_id, $fields, $register = false, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) ) { return egov_law_monitor_checkout_contract_error( 'forbidden', '操作する権限がありません。' ); }
    $id = $fields['attempt_id'] ?? ''; $token = $fields['attempt_token'] ?? ''; $nonce = $fields['attempt_nonce'] ?? '';
    $action = $register ? 'register' : 'lookup';
    if ( ! is_int( $user_id ) || $user_id < 1 || ! get_userdata( $user_id ) || ! is_string( $id ) || ! ctype_digit( $id ) || (int) $id < 1 || ! is_string( $token ) || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_checkout_contract_' . $action . '_' . $user_id . '_' . (int) $id ) ) { return egov_law_monitor_checkout_contract_error( 'request', '操作情報を確認できません。画面を再読み込みしてください。' ); }
    $row = egov_law_monitor_checkout_contract_attempt( $user_id, (int) $id, $token );
    if ( is_wp_error( $row ) ) { return $row; }
    $previous = null;
    if ( $register ) {
        $payload = $fields['candidate'] ?? ''; $signature = $fields['candidate_signature'] ?? '';
        $previous = is_string( $payload ) && strlen( $payload ) <= 8192 ? json_decode( $payload, true, 16 ) : null;
        if ( ! is_array( $previous ) || ! is_string( $signature ) || ! hash_equals( egov_law_monitor_checkout_candidate_signature( $previous ), $signature ) ||
            ( $previous['admin_id'] ?? null ) !== (int) get_current_user_id() || ( $previous['user_id'] ?? null ) !== $user_id ||
            ( $previous['attempt_id'] ?? null ) !== (int) $id || ( $previous['record_token'] ?? null ) !== $token || ( $previous['environment'] ?? null ) !== $row['environment'] ||
            ! is_int( $previous['queried_at'] ?? null ) || $previous['queried_at'] > time() || time() - $previous['queried_at'] > 300 ) { return egov_law_monitor_checkout_contract_error( 'candidate', '照会結果が古いか変更されています。再照会してください。' ); }
    }
    $candidate = egov_law_monitor_checkout_contract_search( $row );
    if ( is_wp_error( $candidate ) ) { return $candidate; }
    $current = egov_law_monitor_checkout_contract_attempt( $user_id, (int) $id, $token );
    if ( is_wp_error( $current ) ) { return $current; }
    if ( $register ) {
        if ( time() - $previous['queried_at'] > 300 ) { return egov_law_monitor_checkout_contract_error( 'candidate', '照会結果の期限が切れました。再照会してください。' ); }
        foreach ( $candidate as $key => $value ) { if ( ( $previous[$key] ?? null ) !== $value ) { return egov_law_monitor_checkout_contract_error( 'changed', 'Square契約が照会時から変わりました。再照会してください。' ); } }
        $context = array( 'attempt_id' => (int) $id, 'record_token' => $token, 'application_uuid' => $row['application_uuid'], 'environment' => $row['environment'], 'template_order_id' => $row['template_order_id'], 'checked_at' => gmdate( 'Y-m-d H:i:s' ) );
        $note = '申込照合: ' . wp_json_encode( array_intersect_key( $context, array_flip( array( 'application_uuid', 'environment', 'template_order_id', 'checked_at' ) ) ) );
        $db = $factory ? $factory() : null;
        return egov_law_monitor_save_square_subscription( $user_id, 0, $candidate['customer_id'], $candidate['id'], $note, '', $db, $context );
    }
    return $candidate + array( 'admin_id' => (int) get_current_user_id(), 'user_id' => $user_id, 'attempt_id' => (int) $id, 'record_token' => $token, 'environment' => $row['environment'], 'queried_at' => time() );
}

function egov_law_monitor_render_checkout_contract_result( $user_id, $result ) {
    if ( ! current_user_can( 'manage_options' ) || $result === null ) { return; }
    if ( is_wp_error( $result ) ) { echo '<div class="notice notice-warning"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return; }
    if ( is_int( $result ) ) { echo '<div class="notice notice-success"><p>契約情報を登録しました。Standard承認は行っていません。登録済み契約を確認し、別途手動承認してください。</p></div>'; return; }
    echo '<h3>Square契約の登録候補</h3><p>一意のテンプレート一致を確認しました。支払済みの証明ではありません。確認後に登録を確定してください。</p><dl>';
    foreach ( array( 'id' => 'Subscription ID', 'customer_id' => 'Customer ID', 'status' => '契約状態', 'location_id' => 'Location ID', 'plan_variation_id' => 'Plan Variation ID', 'environment' => '環境' ) as $key => $label ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $result[$key] ) . '</dd>'; }
    echo '<dt>照会日時（UTC）</dt><dd>' . esc_html( gmdate( 'Y-m-d H:i:s', $result['queried_at'] ) ) . '</dd></dl><form method="post">';
    wp_nonce_field( 'egov_checkout_contract_register_' . $user_id . '_' . $result['attempt_id'], 'attempt_nonce' );
    foreach ( array( 'attempt_id' => $result['attempt_id'], 'attempt_token' => $result['record_token'], 'candidate' => wp_json_encode( $result ), 'candidate_signature' => egov_law_monitor_checkout_candidate_signature( $result ) ) as $name => $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
    echo '<button class="button" name="egov_checkout_contract_register" type="submit">この契約を登録</button></form>';
}
