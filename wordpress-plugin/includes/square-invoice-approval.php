<?php
/** Invoice evidence -> explicit atomic registration and entitlement approval. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_invoice_fingerprint( $result ) {
    $fields = $result['fields']; unset( $fields['照会日時（サイト時刻）'] );
    return hash( 'sha256', wp_json_encode( array( $fields, $result['checks'], $result['versions'] ) ) );
}

/** Legacy rows without recorded environment cannot be silently adopted. */
function egov_law_monitor_invoice_contract_environment( $record ) {
    $note = $record['admin_note'] ?? '';
    foreach ( array( '請求書照合: ', '申込照合: ' ) as $prefix ) {
        if ( is_string( $note ) && str_starts_with( $note, $prefix ) ) {
            $metadata = json_decode( substr( $note, strlen( $prefix ) ), true );
            return is_array( $metadata ) ? ( $metadata['environment'] ?? null ) : null;
        }
    }
    return null;
}

/** Same unique Subscription lookup before signing and under the final lock. */
function egov_law_monitor_invoice_contract( $user_id, $subscription_id, $customer_id, $environment, $db = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'egov_square_subscriptions';
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException(); }
    $sql = "SELECT * FROM `{$table}` WHERE square_subscription_id = ";
    $rows = $db ? $db->rows( $sql . '? FOR UPDATE', array( $subscription_id ) ) : $wpdb->get_results( $wpdb->prepare( $sql . '%s', $subscription_id ), ARRAY_A );
    if ( ! is_array( $rows ) || count( $rows ) > 1 || ( $db ?: $wpdb )->last_error !== '' || ( $db && $db->connection_lost ) ) { throw new RuntimeException( '契約情報を確認できません。' ); }
    $record = $rows[0] ?? null;
    if ( $record && ( (int) $record['user_id'] !== $user_id || $record['square_subscription_id'] !== $subscription_id || $record['square_customer_id'] !== $customer_id || egov_law_monitor_invoice_contract_environment( $record ) !== $environment ) ) {
        throw new RuntimeException( '契約の所属・Customer ID・保存環境を確認できません。既存契約を管理者が確認してください。' );
    }
    return $record;
}

function egov_law_monitor_invoice_signature( $payload ) {
    return hash_hmac( 'sha256', 'square-invoice-approval:' . $payload, wp_salt( 'auth' ) );
}

/** Attach a short-lived candidate; API lookup remains strictly read-only. */
function egov_law_monitor_invoice_candidate( $user_id, $result ) {
    if ( is_wp_error( $result ) || in_array( false, $result['checks'], true ) ) { return $result; }
    try {
        $fields = $result['fields'];
        $record = egov_law_monitor_invoice_contract( $user_id, $fields['Subscription ID'], $fields['請求先Customer ID'], $fields['Square環境'] );
        $snapshot = egov_law_monitor_square_plan_snapshot( $user_id, egov_law_monitor_square_plan_tables() );
        if ( $snapshot['plan'] === 'standard' ) {
            if ( ! $record ) { throw new RuntimeException( '既にStandardですが、この契約は未登録です。状態を確認してください。' ); }
            $result['approval_notice'] = 'このユーザーは登録済み契約で既にStandardです。二重登録・二重承認は行いません。';
            return $result;
        }
        $candidate = array( 'user_id' => $user_id, 'admin_id' => (int) get_current_user_id(), 'issued_at' => time(), 'invoice_id' => $fields['Invoice ID'], 'number' => $fields['請求書番号'], 'environment' => $fields['Square環境'], 'location' => $fields['請求書Location ID'], 'variation' => $fields['Plan Variation ID'], 'subscription_id' => $fields['Subscription ID'], 'customer_id' => $fields['請求先Customer ID'], 'fingerprint' => egov_law_monitor_invoice_fingerprint( $result ), 'plan_token' => egov_law_monitor_square_plan_token( $user_id, $snapshot ), 'contract_token' => $record ? egov_law_monitor_square_record_token( $record ) : null );
        $result['candidate'] = wp_json_encode( $candidate );
        $result['signature'] = egov_law_monitor_invoice_signature( $result['candidate'] );
    } catch ( Throwable $error ) { $result['approval_notice'] = '契約の所属・保存環境・現在プランを確認できないため、一括承認できません。管理者が既存契約を確認してください。'; }
    return $result;
}

/** No API calls after opening the independent transaction. */
function egov_law_monitor_approve_square_invoice( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) ) { return egov_law_monitor_invoice_error( 'forbidden', '承認権限がありません。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! get_userdata( $user_id ) || ! is_string( $fields['nonce'] ?? null ) || ! wp_verify_nonce( $fields['nonce'], 'egov_square_invoice_approve_' . $user_id ) ) { return egov_law_monitor_invoice_error( 'request', '対象ユーザーまたはnonceを確認できません。' ); }
    $payload = $fields['candidate'] ?? null; $signature = $fields['signature'] ?? null; $reason = $fields['reason'] ?? null;
    if ( ( $fields['confirmed'] ?? null ) !== '1' || ! is_string( $reason ) || strlen( $reason ) > 6000 || trim( sanitize_textarea_field( $reason ) ) === '' ) { return egov_law_monitor_invoice_error( 'confirmation', '本人確認チェックと本人確認の根拠を入力してください。' ); }
    if ( ! is_string( $payload ) || strlen( $payload ) > 8192 || ! is_string( $signature ) || ! hash_equals( egov_law_monitor_invoice_signature( $payload ), $signature ) ) { return egov_law_monitor_invoice_error( 'candidate', '照会候補を確認できません。再照会してください。' ); }
    $candidate = json_decode( $payload, true, 16 );
    if ( ! is_array( $candidate ) || ( $candidate['user_id'] ?? null ) !== $user_id || ( $candidate['admin_id'] ?? null ) !== (int) get_current_user_id() || ! is_int( $candidate['issued_at'] ?? null ) || $candidate['issued_at'] > time() || time() - $candidate['issued_at'] > 300 ) { return egov_law_monitor_invoice_error( 'candidate', '照会候補が古いか対象が異なります。再照会してください。' ); }
    $settings = egov_law_monitor_square_api_settings();
    if ( is_wp_error( $settings ) ) { return $settings; }
    $suffix = strtoupper( $settings['environment'] );
    $location_name = 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_' . $suffix; $variation_name = 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_' . $suffix;
    if ( $candidate['environment'] !== $settings['environment'] || ! defined( $location_name ) || ! defined( $variation_name ) || $candidate['location'] !== constant( $location_name ) || $candidate['variation'] !== constant( $variation_name ) ) { return egov_law_monitor_invoice_error( 'settings_changed', '環境または設定が変わりました。再照会してください。' ); }
    $fresh = egov_law_monitor_invoice_details( $settings, $candidate['location'], $candidate['variation'], $candidate['number'], $candidate['invoice_id'], microtime( true ) + 30 );
    if ( is_wp_error( $fresh ) ) { return $fresh; }
    if ( in_array( false, $fresh['checks'], true ) || ! hash_equals( $candidate['fingerprint'], egov_law_monitor_invoice_fingerprint( $fresh ) ) || time() - $candidate['issued_at'] > 300 ) { return egov_law_monitor_invoice_error( 'changed', '請求書・契約情報が照会時から変わりました。再照会してください。' ); }
    global $wpdb;
    $db = null; $started = false; $committing = false; $committed = false; $invalidate = false; $rollback_failed = false;
    try {
        $tables = egov_law_monitor_square_plan_tables();
        $db = $factory ? $factory() : EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) || ! $db->begin() ) { throw new RuntimeException(); }
        $started = true;
        $users = $db->rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ) );
        if ( ! $users || (int) $users[0]['ID'] !== $user_id ) { throw new RuntimeException(); }
        $record = egov_law_monitor_invoice_contract( $user_id, $candidate['subscription_id'], $candidate['customer_id'], $candidate['environment'], $db );
        if ( $db->rows( "SELECT umeta_id FROM `{$tables['meta']}` WHERE user_id = ? AND meta_key = ? FOR UPDATE", array( $user_id, EGOV_LAW_MONITOR_META_PLAN ) ) === false ) { throw new RuntimeException(); }
        $before = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
        if ( $record && $before['plan'] === 'standard' ) {
            if ( ! $db->rollback() ) { $rollback_failed = true; throw new RuntimeException(); }
            $started = false;
            return array( 'already' => true, 'message' => '既にStandardです。二重登録・二重承認は行いません。' );
        }
        if ( time() - $candidate['issued_at'] > 300 || $before['plan'] !== 'free' || ! hash_equals( $candidate['plan_token'], egov_law_monitor_square_plan_token( $user_id, $before ) ) || ( $record ? egov_law_monitor_square_record_token( $record ) : null ) !== $candidate['contract_token'] ) { throw new RuntimeException(); }
        $now = current_time( 'mysql' ); $admin_id = get_current_user_id();
        $evidence = array( 'environment' => $candidate['environment'], 'invoice_id' => $candidate['invoice_id'], 'invoice_number' => $candidate['number'], 'subscription_id' => $candidate['subscription_id'], 'customer_id' => $candidate['customer_id'], 'location_id' => $candidate['location'], 'variation_id' => $candidate['variation'], 'status' => 'PAID', 'amount_jpy' => 500, 'paid_jpy' => 500, 'queried_at' => $candidate['issued_at'], 'confirmed_at' => $now );
        if ( ! $record ) {
            $record = egov_law_monitor_square_store_contract( $db, '`' . $tables['contracts'] . '`', '`' . $tables['log'] . '`', $user_id, 0, null, $candidate['customer_id'], $candidate['subscription_id'], '請求書照合: ' . wp_json_encode( $evidence ), $now, $admin_id );
        }
        $invalidate = true;
        egov_law_monitor_square_apply_plan( $db, $tables, $user_id, $before, $record, 'standard', $now, $admin_id, sanitize_textarea_field( $reason ), $evidence );
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException(); }
        $committed = true;
        return array( 'already' => false, 'message' => '契約登録・Standard承認・確認記録・監査履歴の保存が完了しました。' );
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $rollback_error ) {} }
        $unknown = $started && ( $committing || ! $rolled_back || $rollback_failed );
        error_log( 'e-Gov Square: invoice approval ' . ( $unknown ? 'outcome unknown' : 'failed' ) . '; user_id=' . $user_id );
        return egov_law_monitor_invoice_error( $unknown ? 'outcome_unknown' : 'save_failed', $unknown ? '確定または取り消し結果が不明です。再操作せず、現在のプラン・契約・操作履歴を確認してください。' : '契約登録・承認できませんでした。再照会し、プラン・契約の競合とDBの状態を確認してください。' );
    } finally {
        if ( $db ) { $db->close(); }
        if ( $invalidate ) {
            // WordPress may return false when the cache entry is already absent.
            try { wp_cache_delete( $user_id, 'user_meta' ); }
            catch ( Throwable $error ) { if ( $committed ) { return egov_law_monitor_invoice_error( 'cache_warning', '登録・承認と監査保存は確定済みですがキャッシュを無効化できません。再承認せず現在のプランとキャッシュを確認してください。' ); } }
        }
    }
}
