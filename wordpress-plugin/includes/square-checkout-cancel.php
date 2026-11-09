<?php
/** Cancellation is local only. Square operations belong to the administrator. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The log has no actor-type column: user events use admin_user_id=0 and explicit JSON actor. */
function egov_law_monitor_checkout_cancel_audit( $db, $tables, $row, $state, $reason, $checks, $user_action ) {
    $now = gmdate( 'Y-m-d H:i:s' );
    $old = array_intersect_key( $row, array_flip( array( 'application_uuid', 'state', 'payment_link_id', 'template_order_id', 'environment' ) ) );
    $new = array_replace( $old, array( 'state' => $state, 'actor_type' => $user_action ? 'user' : 'administrator',
        'actor_user_id' => (int) get_current_user_id(), 'recorded_at' => $now ) );
    if ( ! $user_action ) { $new['dashboard_confirmation'] = $checks; $new['confirmed_at'] = $now; }
    $before = wp_json_encode( $old ); $after = wp_json_encode( $new );
    if ( $before === false || $after === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
        array( (int) $row['user_id'], $user_action ? 0 : (int) get_current_user_id(), $user_action ? 'checkout_cancel_requested' : 'checkout_cancel_completed', $before, $after, $reason, $now ) ) ) { throw new RuntimeException(); }
}

/** Locked, signed transition + audit in one owned transaction. Never calls Square. */
function egov_law_monitor_checkout_cancel_transition( $user_id, $id, $token, $complete, $reason, $checks, $factory ) {
    return egov_law_monitor_checkout_transaction( static function ( $db, $tables, $table ) use ( $user_id, $id, $token, $complete, $reason, $checks ) {
        $users = $db->rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ) );
        if ( ! $users || (int) $users[0]['ID'] !== $user_id ) { throw new RuntimeException(); }
        $rows = $db->rows( "SELECT * FROM {$table} WHERE id = ? AND user_id = ? FOR UPDATE", array( $id, $user_id ) );
        $row = $rows[0] ?? null;
        if ( ! $row || (int) $row['user_id'] !== $user_id || $row['state'] !== ( $complete ? 'cancel_requested' : 'ready' ) ||
            ! hash_equals( egov_law_monitor_square_record_token( $row ), $token ) || ! egov_law_monitor_checkout_uuid_valid( $row['application_uuid'] ) ||
            ! in_array( $row['environment'], array( 'sandbox', 'production' ), true ) ||
            ! egov_law_monitor_square_api_id_valid( $row['payment_link_id'] ) || ! egov_law_monitor_square_api_id_valid( $row['template_order_id'] ) ) {
            return new WP_Error( 'cancel_conflict', '対象・申込状態が変わりました。画面を再読み込みしてください。' );
        }
        // A registered contract is positive evidence; do not complete it as an unestablished contract.
        if ( $complete ) {
            $contracts = $db->rows( "SELECT id FROM `{$tables['contracts']}` WHERE user_id = ? LIMIT 1", array( $user_id ) );
            if ( ! is_array( $contracts ) ) { throw new RuntimeException(); }
            if ( $contracts ) { return new WP_Error( 'cancel_existing_contract', '登録済み契約があります。完了登録せず契約・決済状況を確認してください。' ); }
        }
        $state = $complete ? 'closed' : 'cancel_requested'; $now = gmdate( 'Y-m-d H:i:s' );
        if ( ! $db->write( "UPDATE {$table} SET state = ?, updated_at = ? WHERE id = ? AND user_id = ?", array( $state, $now, $id, $user_id ) ) ) { throw new RuntimeException(); }
        $saved = $db->rows( "SELECT * FROM {$table} WHERE id = ? AND user_id = ?", array( $id, $user_id ) );
        if ( ! $saved || $saved[0]['state'] !== $state ) { throw new RuntimeException(); }
        egov_law_monitor_checkout_cancel_audit( $db, $tables, $row, $state, $reason, $checks, ! $complete );
        return true;
    }, $factory );
}

function egov_law_monitor_request_checkout_cancel( $fields, $factory = null ) {
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 || ! get_userdata( $user_id ) ) { return new WP_Error( 'cancel_login_required', 'ログインしてください。' ); }
    if ( ! egov_law_monitor_standard_application_allowed() ) { return new WP_Error( 'checkout_application_restricted', 'Standardプランのお申し込みは現在準備中です。管理者へお問い合わせください。' ); }
    $id = $fields['checkout_cancel_id'] ?? ''; $nonce = $fields['checkout_cancel_nonce'] ?? ''; $token = $fields['checkout_cancel_token'] ?? '';
    if ( ! is_string( $id ) || ! ctype_digit( $id ) || (int) $id < 1 || ! is_string( $nonce ) ||
        ! wp_verify_nonce( $nonce, 'egov_checkout_cancel_request_' . $user_id . '_' . (int) $id ) || ! is_string( $token ) ||
        ( $fields['checkout_cancel_confirmed'] ?? '' ) !== '1' ) { return new WP_Error( 'cancel_invalid_request', '画面を再読み込みし、注意事項を確認してください。' ); }
    return egov_law_monitor_checkout_cancel_transition( $user_id, (int) $id, $token, false, null, array(), $factory );
}

function egov_law_monitor_complete_checkout_cancel( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'cancel_forbidden', '操作する権限がありません。' ); }
    $id = $fields['checkout_cancel_id'] ?? ''; $nonce = $fields['checkout_cancel_nonce'] ?? ''; $token = $fields['checkout_cancel_token'] ?? ''; $reason = $fields['checkout_cancel_reason'] ?? '';
    if ( ! is_int( $user_id ) || $user_id < 1 || ! get_userdata( $user_id ) || ! is_string( $id ) || ! ctype_digit( $id ) || (int) $id < 1 ||
        ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'egov_checkout_cancel_complete_' . $user_id . '_' . (int) $id ) || ! is_string( $token ) || ! is_string( $reason ) || strlen( $reason ) > 6000 ) {
        return new WP_Error( 'cancel_invalid_request', '画面を再読み込みして操作してください。' );
    }
    $checks = array();
    foreach ( array( 'link_deleted', 'no_payment_subscription', 'no_unresolved_issue' ) as $key ) {
        if ( ( $fields[$key] ?? '' ) !== '1' ) { return new WP_Error( 'cancel_confirmation_required', '確認が不十分な場合は完了登録せず、申請中のまま保留してください。' ); }
        $checks[$key] = true;
    }
    $reason = trim( sanitize_textarea_field( $reason ) );
    if ( $reason === '' ) { return new WP_Error( 'cancel_reason_required', '処理理由・備考を入力してください。' ); }
    return egov_law_monitor_checkout_cancel_transition( $user_id, (int) $id, $token, true, $reason, $checks, $factory );
}
