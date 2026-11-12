<?php
/** Administrator-only invoice lookup. No database writes or Square mutations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_invoice_error( $code, $message ) {
    return new WP_Error( 'square_invoice_' . $code, $message );
}

/** Only callers below construct paths; credentials never enter errors or results. */
function egov_law_monitor_invoice_get( $settings, $path, $deadline ) {
    $remaining = $deadline - microtime( true );
    if ( $remaining <= 0 ) { return egov_law_monitor_invoice_error( 'incomplete', '照会時間の上限に達しました。検索は未完了です。' ); }
    $origin = $settings['environment'] === 'sandbox' ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
    try {
        $response = wp_safe_remote_get( $origin . $path, array( 'timeout' => min( 10, $remaining ), 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048576,
            'headers' => array( 'Authorization' => 'Bearer ' . $settings['token'], 'Square-Version' => '2026-09-16', 'Accept' => 'application/json' ) ) );
    } catch ( Throwable $error ) { $response = new WP_Error( 'transport', '' ); }
    if ( is_wp_error( $response ) ) { return egov_law_monitor_invoice_error( 'transport', 'Squareとの通信に失敗しました。時間を置いて再照会してください。' ); }
    if ( microtime( true ) > $deadline ) { return egov_law_monitor_invoice_error( 'incomplete', '照会時間の上限に達しました。検索は未完了です。' ); }
    $status = (int) wp_remote_retrieve_response_code( $response );
    if ( $status !== 200 ) {
        $messages = array( 401 => 'Squareの認証設定を確認してください。', 403 => 'INVOICES_READ・SUBSCRIPTIONS_READ権限を確認してください。', 404 => 'Square側に対象が見つかりません。', 429 => 'Squareの呼び出し制限に達しました。時間を置いてください。' );
        return egov_law_monitor_invoice_error( 'http_' . $status, $messages[$status] ?? 'Square API照会に失敗しました。' );
    }
    $raw = wp_remote_retrieve_body( $response );
    $data = is_string( $raw ) && strlen( $raw ) <= 1048576 ? json_decode( $raw, true, 32 ) : null;
    if ( ! is_array( $data ) || ! empty( $data['errors'] ) || strpos( $raw, $settings['token'] ) !== false ) { return egov_law_monitor_invoice_error( 'response', 'Squareの応答形式を確認できません。' ); }
    return $data;
}

function egov_law_monitor_lookup_square_invoice( $user_id, $number, $nonce ) {
    if ( ! current_user_can( 'manage_options' ) ) { return egov_law_monitor_invoice_error( 'forbidden', '照会権限がありません。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! get_userdata( $user_id ) || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_square_invoice_' . $user_id ) ) { return egov_law_monitor_invoice_error( 'request', '対象ユーザーまたはnonceを確認できません。' ); }
    // Do not coerce numbers: leading zeroes are significant.
    if ( ! is_string( $number ) || ! preg_match( '/\A[\x21-\x7e]{1,191}\z/', $number ) ) { return egov_law_monitor_invoice_error( 'number', '請求書番号を半角文字で入力してください。' ); }
    $settings = egov_law_monitor_square_api_settings();
    if ( is_wp_error( $settings ) ) { return $settings; }
    $suffix = strtoupper( $settings['environment'] );
    $location = 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_' . $suffix;
    $variation = 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_' . $suffix;
    $location = defined( $location ) ? constant( $location ) : null;
    $variation = defined( $variation ) ? constant( $variation ) : null;
    if ( ! egov_law_monitor_square_api_id_valid( $location ) || ! egov_law_monitor_square_api_id_valid( $variation ) ) { return egov_law_monitor_invoice_error( 'settings', 'SquareのLocation・Plan Variation設定を確認してください。' ); }
    $deadline = microtime( true ) + 30;
    $cursor = ''; $cursors = array(); $seen = array(); $matches = array();
    for ( $page = 0; $page < 5; ++$page ) {
        $data = egov_law_monitor_invoice_get( $settings, '/v2/invoices?' . http_build_query( array( 'location_id' => $location, 'limit' => 200, 'cursor' => $cursor ), '', '&', PHP_QUERY_RFC3986 ), $deadline );
        if ( is_wp_error( $data ) ) { return $data; }
        $rows = array_key_exists( 'invoices', $data ) ? $data['invoices'] : array();
        if ( ! is_array( $rows ) || count( $rows ) > 200 ) { return egov_law_monitor_invoice_error( 'response', '請求書一覧の応答形式を確認できません。' ); }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! preg_match( '/\A[A-Za-z0-9:_-]{1,255}\z/', $row['id'] ) || ! is_string( $row['invoice_number'] ?? null ) || ( $row['location_id'] ?? null ) !== $location || isset( $seen[$row['id']] ) ) { return egov_law_monitor_invoice_error( 'response', '請求書一覧に不整合があります。' ); }
            $seen[$row['id']] = true;
            if ( $row['invoice_number'] === $number ) { $matches[] = $row['id']; }
        }
        if ( count( $matches ) > 1 ) { return egov_law_monitor_invoice_error( 'multiple', '同じ請求書番号の候補が複数あります。Dashboardで確認してください。' ); }
        $cursor = $data['cursor'] ?? '';
        if ( ! is_string( $cursor ) || strlen( $cursor ) > 4096 || isset( $cursors[$cursor] ) ) { return egov_law_monitor_invoice_error( 'incomplete', 'ページ送りを確認できません。検索は未完了です。' ); }
        if ( $cursor === '' ) { break; }
        $cursors[$cursor] = true;
    }
    if ( $cursor !== '' ) { return egov_law_monitor_invoice_error( 'incomplete', '検索上限（1000件）に達しました。検索は未完了です。' ); }
    if ( ! $matches ) { return egov_law_monitor_invoice_error( 'not_found', '一致する請求書番号が見つかりません。' ); }
    $data = egov_law_monitor_invoice_get( $settings, '/v2/invoices/' . rawurlencode( $matches[0] ), $deadline );
    if ( is_wp_error( $data ) ) { return $data; }
    $invoice = $data['invoice'] ?? null;
    if ( ! is_array( $invoice ) || ( $invoice['id'] ?? null ) !== $matches[0] || ( $invoice['invoice_number'] ?? null ) !== $number || ! egov_law_monitor_square_api_id_valid( $invoice['subscription_id'] ?? null ) ) { return egov_law_monitor_invoice_error( 'changed', '請求書が変更されたか、Subscription IDを取得できません。再照会してください。' ); }
    $data = egov_law_monitor_invoice_get( $settings, '/v2/subscriptions/' . rawurlencode( $invoice['subscription_id'] ), $deadline );
    if ( is_wp_error( $data ) ) { return $data; }
    $subscription = $data['subscription'] ?? null;
    if ( ! is_array( $subscription ) || ( $subscription['id'] ?? null ) !== $invoice['subscription_id'] ) { return egov_law_monitor_invoice_error( 'response', '契約の応答を確認できません。' ); }
    $customer = is_array( $invoice['primary_recipient'] ?? null ) ? $invoice['primary_recipient'] : array();
    $amount = 0; $paid = 0; $money_valid = is_array( $invoice['payment_requests'] ?? null ) && count( $invoice['payment_requests'] ) > 0 && count( $invoice['payment_requests'] ) <= 100;
    if ( $money_valid ) {
        foreach ( $invoice['payment_requests'] as $request ) {
            foreach ( array( 'computed_amount_money', 'total_completed_amount_money' ) as $field ) {
                $money = is_array( $request ) ? ( $request[$field] ?? null ) : null;
                if ( ! is_array( $money ) || ( $money['currency'] ?? null ) !== 'JPY' || ! is_int( $money['amount'] ?? null ) || $money['amount'] < 0 || $money['amount'] > 100000000 ) { $money_valid = false; break 2; }
            }
            $amount += $request['computed_amount_money']['amount']; $paid += $request['total_completed_amount_money']['amount'];
        }
    }
    $checks = array(
        '請求書はPAID' => ( $invoice['status'] ?? null ) === 'PAID',
        '請求額・支払済み額はともに500 JPY' => $money_valid && $amount === 500 && $paid === 500,
        '契約はACTIVE' => ( $subscription['status'] ?? null ) === 'ACTIVE',
        'Location一致' => ( $invoice['location_id'] ?? null ) === $location && ( $subscription['location_id'] ?? null ) === $location,
        'Plan Variation一致' => ( $subscription['plan_variation_id'] ?? null ) === $variation,
        'Customer ID一致' => egov_law_monitor_square_api_id_valid( $customer['customer_id'] ?? null ) && ( $customer['customer_id'] ?? null ) === ( $subscription['customer_id'] ?? null ),
    );
    // Explicit display allowlist: never return raw objects, card IDs or payment sources.
    $fields = array( 'Square環境' => $settings['environment'], '請求書番号' => $number, 'Invoice ID' => $invoice['id'], '請求書状態' => $invoice['status'] ?? '', '請求額（JPY）' => $money_valid ? (string) $amount : '確認不能', '支払済み額（JPY）' => $money_valid ? (string) $paid : '確認不能', 'Subscription ID' => $subscription['id'], '契約状態' => $subscription['status'] ?? '', '請求書Location ID' => $invoice['location_id'] ?? '', '契約Location ID' => $subscription['location_id'] ?? '', 'Plan Variation ID' => $subscription['plan_variation_id'] ?? '', '請求先Customer ID' => $customer['customer_id'] ?? '', '契約Customer ID' => $subscription['customer_id'] ?? '', '請求先氏名' => trim( ( is_string( $customer['given_name'] ?? null ) ? $customer['given_name'] : '' ) . ' ' . ( is_string( $customer['family_name'] ?? null ) ? $customer['family_name'] : '' ) ), '請求先メール' => $customer['email_address'] ?? '', '照会日時（サイト時刻）' => current_time( 'mysql' ) );
    foreach ( $fields as &$value ) { $value = is_string( $value ) && strlen( $value ) <= 512 ? $value : '応答形式不正'; } unset( $value );
    return array( 'fields' => $fields, 'checks' => $checks );
}

function egov_law_monitor_render_invoice_lookup( $user_id, $url, $result ) {
    echo '<h2>請求書番号でSquare契約を照会（読み取り専用）</h2><form method="post" action="' . esc_url( $url ) . '">';
    wp_nonce_field( 'egov_square_invoice_' . $user_id, 'invoice_nonce' );
    echo '<label>請求書番号 <input type="text" name="invoice_number" maxlength="191" required></label> <button class="button" name="egov_square_invoice_lookup" type="submit">請求書を照会</button></form>';
    echo '<p>上記のWordPressユーザーと請求先の氏名・メールを比較し、Dashboardでも本人を確認してください。番号やメールの一致だけでは本人と確定できません。照会だけでは契約登録・Standard承認は行いません。</p>';
    if ( $result === null ) { return; }
    if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; return; }
    echo '<table class="widefat"><tbody>';
    foreach ( $result['fields'] as $label => $value ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
    foreach ( $result['checks'] as $label => $ok ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . ( $ok ? '一致・確認済み' : '不一致・要確認' ) . '</td></tr>'; }
    echo '</tbody></table><p>これは照会時点の結果です。この請求書以外の未払い、今後の課金や継続的な契約有効性は保証しません。確認不一致時は承認せずDashboardで確認してください。</p>';
}
