<?php
/** Low-frequency request coordination. No cross-connection transaction or automatic retry. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_withdrawal_link_context( $user_id ) {
    if ( ! egov_law_monitor_cancellation_storage_ready() ) { throw new RuntimeException(); }
    $context = egov_law_monitor_cancellation_context( $user_id );
    return array( 'approval_id' => (int) $context['approval_id'], 'contract_id' => (int) $context['contract']['id'], 'token' => $context['plan_token'] );
}

/** Exact approval/contract lookup, never the latest historical cancellation. */
function egov_law_monitor_withdrawal_link_row( $user_id, $record ) {
    if ( ! egov_law_monitor_cancellation_storage_ready() ) { throw new RuntimeException(); }
    $table = egov_law_monitor_cancellation_table();
    $rows = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE approval_id = ?", array( $record['approval_id'] ) );
    if ( ! $rows ) { return null; }
    $row = $rows[0];
    if ( count( $rows ) !== 1 || (int) $row['user_id'] !== $user_id || (int) $row['contract_id'] !== $record['contract_id'] || (int) $row['approval_id'] !== $record['approval_id'] || (int) $row['id'] < 1 || ( $record['cancellation_id'] > 0 && (int) $row['id'] !== $record['cancellation_id'] ) ) { throw new RuntimeException(); }
    return $row;
}

/** Never overwrite another approval's receipt; verify Options API persistence. */
function egov_law_monitor_withdrawal_link_store( $user_id, $before, $after ) {
    $current = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $current ) ) { throw new RuntimeException(); }
    if ( $current === $after ) { return $current; }
    if ( $current !== $before ) { throw new RuntimeException(); }
    update_option( egov_law_monitor_withdrawal_request_key( $user_id ), $after, false );
    $saved = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $saved ) || $saved !== $after ) { throw new RuntimeException(); }
    return $saved;
}

function egov_law_monitor_link_withdrawal_cancellation( $user_id, $record, $fields, $factory = null ) {
    try {
        $standard = egov_law_monitor_get_plan( $user_id ) === 'standard';
        if ( $standard ) {
            $context = egov_law_monitor_withdrawal_link_context( $user_id );
            if ( ! is_string( $fields['withdrawal_cancel_nonce'] ?? null ) || ! wp_verify_nonce( $fields['withdrawal_cancel_nonce'], 'egov_standard_cancel_' . $user_id ) || ! is_string( $fields['withdrawal_cancel_token'] ?? null ) || ! hash_equals( $context['token'], $fields['withdrawal_cancel_token'] ) ) { return new WP_Error( 'withdrawal_link_changed', '契約・承認対象または確認画面が変更されました。画面を開き直して確認してください。' ); }
        }
        $linked = is_array( $record ) && isset( $record['approval_id'] );
        if ( $linked && $standard && ( $record['approval_id'] !== $context['approval_id'] || $record['contract_id'] !== $context['contract_id'] ) ) { throw new RuntimeException(); }
        if ( ! $linked ) {
            if ( ! $standard ) { throw new RuntimeException(); }
            $pending = array( 'user_id' => $user_id, 'requested_at' => $record['requested_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ), 'status' => 'pending_cancellation', 'approval_id' => $context['approval_id'], 'contract_id' => $context['contract_id'], 'cancellation_id' => 0, 'cancellation_attempt' => 'ready' );
            if ( $record === null ) {
                add_option( egov_law_monitor_withdrawal_request_key( $user_id ), $pending, '', false );
                $saved = egov_law_monitor_get_withdrawal_request( $user_id );
                if ( is_wp_error( $saved ) || $saved === null || ! isset( $saved['approval_id'] ) || $saved['approval_id'] !== $pending['approval_id'] || $saved['contract_id'] !== $pending['contract_id'] ) { throw new RuntimeException(); }
                $record = $saved;
            } else { $record = egov_law_monitor_withdrawal_link_store( $user_id, $record, $pending ); }
        }
        $row = egov_law_monitor_withdrawal_link_row( $user_id, $record );
        if ( $row && $standard && $row['completed_at'] !== null ) { throw new RuntimeException(); }
        if ( $row === null ) {
            // An uncertain attempt is reconciled by reads only, even on an explicit POST.
            if ( ! $standard || $record['status'] === 'received' || $record['cancellation_attempt'] === 'uncertain' ) { return new WP_Error( 'withdrawal_link_unknown', '退会意思は保存済みですが、解約申請の連携を確認できません。自動再送せず、管理者へ調査をご依頼ください。' ); }
            $attempt = $record; $attempt['cancellation_attempt'] = 'uncertain';
            $record = egov_law_monitor_withdrawal_link_store( $user_id, $record, $attempt );
            $result = egov_law_monitor_request_subscription_cancellation( $fields['withdrawal_cancel_nonce'], $fields['withdrawal_cancel_token'], $factory );
            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() !== 'cancellation_outcome_unknown' ) {
                    $failed = $record; $failed['cancellation_attempt'] = 'failed';
                    egov_law_monitor_withdrawal_link_store( $user_id, $record, $failed );
                }
                return new WP_Error( 'withdrawal_link_pending', '退会意思は保存済みですが、解約申請の連携は未完了です。' . $result->get_error_message() );
            }
            $row = egov_law_monitor_withdrawal_link_row( $user_id, $record );
            if ( ! $row || (int) $result['id'] !== (int) $row['id'] ) { throw new RuntimeException(); }
        }
        $received = $record; $received['status'] = 'received'; $received['cancellation_id'] = (int) $row['id']; $received['cancellation_attempt'] = 'confirmed';
        $saved = egov_law_monitor_withdrawal_link_store( $user_id, $record, $received );
        // Existing mail reservation/retry rules remain authoritative; delivery is not receipt success.
        if ( $standard && $row['completed_at'] === null ) { try { egov_law_monitor_process_cancellation_mail( (int) $row['id'], $factory ); } catch ( Throwable $ignored ) {} }
        return $saved;
    } catch ( Throwable $ignored ) { return new WP_Error( 'withdrawal_link_unconfirmed', '退会申請または解約申請の連携を確認できません。受付状況を再確認し、未完了の場合は明示的に再照合してください。' ); }
}

/** Small start gate: a later manual Free change alone cannot resolve a pending option. */
function egov_law_monitor_withdrawal_request_start_check( $user_id ) {
    $record = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $record ) ) { return $record; }
    if ( $record === null ) { return true; } // Existing contact-based intake is unchanged.
    if ( $record['status'] === 'pending_cancellation' ) { return new WP_Error( 'withdrawal_link_pending', '退会申請とStandard解約申請の連携が未完了です。退会開始前に再照合してください。' ); }
    if ( isset( $record['approval_id'] ) ) {
        try { if ( ! egov_law_monitor_withdrawal_link_row( $user_id, $record ) ) { throw new RuntimeException(); } }
        catch ( Throwable $ignored ) { return new WP_Error( 'withdrawal_link_missing', '連携済みの解約申請を確認できません。退会を開始せず調査してください。' ); }
    }
    return true;
}
