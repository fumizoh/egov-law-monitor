<?php
/** Final verification and explicit completion only; contains no DELETE or WP deletion call. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class EGOV_Law_Monitor_Withdrawal_Completion_Error extends RuntimeException {}

function egov_law_monitor_withdrawal_completion_count( $table, $user_id, $column, $meta_key = null, $db = null ) {
    foreach ( array( $table, $column ) as $identifier ) { if ( ! is_string( $identifier ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $identifier ) ) { throw new RuntimeException(); } }
    $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE `{$column}` = ?"; $params = array( $user_id );
    if ( $meta_key !== null ) { $sql .= ' AND meta_key = ?'; $params[] = $meta_key; }
    $rows = egov_law_monitor_withdrawal_rows( $sql, $params, $db ); $value = $rows[0]['total'] ?? null;
    if ( count( $rows ) !== 1 || ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', (string) $value ) || (string) (int) $value !== (string) $value ) { throw new RuntimeException(); }
    return (int) $value;
}

/** Bound to user, action, start audit and process UUID. No current WP role is inferred. */
function egov_law_monitor_withdrawal_completion_audit( $user_id, $state, $action, $id = null, $db = null ) {
    return egov_law_monitor_withdrawal_retention_audit( $user_id, $state, $action, $id, $db );
}

/** All current reads; read/validation failures are reported, never converted to zero. */
function egov_law_monitor_withdrawal_completion_snapshot( $user_id, $state, $db = null ) {
    global $wpdb;
    egov_law_monitor_withdrawal_validate_state( $state, $user_id );
    $report = array( 'ok' => false, 'counts' => array(), 'errors' => array(), 'proof' => null, 'retention' => array(), 'current_retention' => null, 'completed' => null );
    $targets = array(
        'WordPressユーザー' => array( $wpdb->users, 'ID', null ),
        '法令ウォッチ' => array( $wpdb->prefix . 'law_watch_settings', 'user_id', null ),
        '通知設定メタ' => array( $wpdb->usermeta, 'user_id', 'egov_law_monitor_notifications' ),
        'AI個人履歴' => array( $wpdb->prefix . 'egov_law_ai_summary_history', 'user_id', null ),
        'Free試用回数メタ' => array( $wpdb->usermeta, 'user_id', 'egov_law_monitor_ai_summary_trial_usage' ),
        '全ユーザーメタ' => array( $wpdb->usermeta, 'user_id', null ),
        '所有投稿・メディア' => array( $wpdb->posts, 'post_author', null ),
        '本人コメント' => array( $wpdb->comments, 'user_id', null ),
        '所有リンク' => array( $wpdb->links, 'link_owner', null ),
    );
    foreach ( $targets as $label => $spec ) {
        try {
            $report['counts'][$label] = egov_law_monitor_withdrawal_completion_count( $spec[0], $user_id, $spec[1], $spec[2], $db );
            if ( $report['counts'][$label] !== 0 ) { $report['errors'][] = $label . 'が残存しています。完了処理は削除しません。'; }
        } catch ( Throwable $ignored ) { $report['counts'][$label] = null; $report['errors'][] = $label . 'の読み取りに失敗しました。'; }
    }
    try {
        if ( ! in_array( $state['phase'], array( 'deleting', 'completed' ), true ) || (int) ( $state['started_audit_id'] ?? 0 ) < 1 ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '削除段階の退会記録と開始監査IDを確認できません。' ); }
        $start = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_started', (int) $state['started_audit_id'], $db );
        $watch = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_watches_deleted', null, $db );
        $ai = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_ai_history_deleted', null, $db );
        $prepare = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_user_deleting', null, $db );
        $deleted = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_user_deleted', null, $db );
        $target = array( 'subscriber_only' => true, 'protected_check_passed' => true );
        if ( ( $start['value']['target_validation'] ?? null ) !== $target || ( $prepare['value']['target_validation'] ?? null ) !== $target || ( $start['value']['confirmations'] ?? null ) !== array_keys( egov_law_monitor_withdrawal_start_confirmations() ) || ( $start['value']['manual_evidence_only'] ?? null ) !== true || ( $start['value']['phase'] ?? null ) !== 'started' || ( $prepare['value']['phase'] ?? null ) !== 'deleting' ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '削除済みユーザーの対象判定・管理者確認の保持記録を確認できません。' ); }
        if ( $watch['id'] <= $start['id'] || $ai['id'] <= $start['id'] || $prepare['id'] <= max( $watch['id'], $ai['id'] ) || $deleted['id'] <= $prepare['id'] || ( $watch['value']['remaining'] ?? null ) !== array( 'watches' => 0, 'notifications' => 0 ) || ( $ai['value']['remaining'] ?? null ) !== array( 'history' => 0, 'trial' => 0 ) ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '先行段階の監査順序・残存確認が不整合です。' ); }
        $result = $deleted['value'];
        if ( ( $result['pre_delete_audit_id'] ?? null ) !== $prepare['id'] || ( $result['user_exists'] ?? null ) !== false || ( $result['wp_result'] ?? null ) !== true || ( $result['call_error'] ?? null ) !== false || ( $result['retained_contracts_match'] ?? null ) !== true ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( 'WordPressユーザー削除の成功記録が不明・不整合です。再削除せず手順7の監査を確認してください。' ); }
        $archive = egov_law_monitor_withdrawal_user_archive( $user_id, $state, $db );
        $evidence = $archive['evidence'];
        $encoded_evidence = wp_json_encode( $evidence );
        if ( $encoded_evidence === false || ( $result['preserved_evidence_hash'] ?? null ) !== hash( 'sha256', $encoded_evidence ) ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '削除成功時に確認した保持情報と削除前監査が一致しません。' ); }
        if ( ! is_array( $start['value']['saved_evidence'] ?? null ) || ( $start['value']['saved_evidence']['plan'] ?? null ) !== 'free' || ! is_array( $start['value']['saved_evidence']['contracts'] ?? null ) || ! is_array( $start['value']['saved_evidence']['cancellations'] ?? null ) || ( $evidence['square']['plan'] ?? null ) !== 'free' || ( $evidence['watch_remaining'] ?? null ) !== array( 'watches' => 0, 'notifications' => 0 ) || ( $evidence['ai_remaining'] ?? null ) !== array( 'history' => 0, 'trial' => 0 ) || ( $evidence['ownership'] ?? null ) !== array( 'posts' => 0, 'comments' => 0, 'links' => 0 ) ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '開始・削除前の確認記録が不足しています。' ); }
        $data = egov_law_monitor_withdrawal_check_data( $user_id, $db );
        $square = egov_law_monitor_withdrawal_start_evidence( $data );
        $report['current_retention'] = egov_law_monitor_withdrawal_verify_retention( $user_id, $state, $data, $db, array( 'evidence' => $evidence, 'audit_id' => $prepare['id'] ) );
        if ( ! is_bool( $evidence['consent_required'] ?? null ) || ! array_key_exists( 'consent', $evidence ) ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '必要な同意の保持条件を確認できません。' ); }
        $consent = $evidence['consent'];
        if ( $evidence['consent_required'] ) {
            if ( ! is_array( $consent ) || ! is_string( $consent['agreed_at'] ?? null ) || trim( $consent['agreed_at'] ) === '' || ! is_string( $consent['terms_version'] ?? null ) || trim( $consent['terms_version'] ) === '' ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '必要なStandard同意の保持記録が失われています。' ); }
        } elseif ( $consent !== null || $data['contracts'] ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( 'Standard同意の保持条件が不整合です。' ); }
        $report['retention'] = array( 'Square契約' => count( $data['contracts'] ) . '件・ID保持と変更監査を確認', '解約履歴' => count( $data['cancellations'] ) . '件・ID保持と変更監査を確認', '旧申込履歴' => $data['legacy'] === 'absent' ? '元の対象テーブルなし' : count( $data['legacy'] ) . '件・IDと記録を保持', '必要な退会監査' => '開始・ウォッチ・AI・削除開始・ユーザー削除を同じUUIDで確認', 'Standard同意' => $evidence['consent_required'] ? '開始時の記録を削除前監査に保持済み' : '元の記録なし・保持不要', '共有AIキャッシュ' => '全ユーザー共通・この処理の変更対象外' );
        $report['proof'] = array( 'audit_ids' => array( $start['id'], $watch['id'], $ai['id'], $prepare['id'], $deleted['id'] ), 'retained_hash' => $evidence['retained_hash'], 'consent_hash' => hash( 'sha256', wp_json_encode( $consent ) ), 'counts' => $report['counts'] );
        $chain = array( 'head_audit_id' => (int) $state['last_audit_id'], 'revision' => (int) $state['revision'] );
        if ( $state['phase'] === 'completed' ) {
            $done = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_completed', (int) $state['last_audit_id'], $db );
            $chain = $done['value']['cleanup_chain'] ?? null;
            if ( ! is_array( $chain ) || ! is_int( $chain['head_audit_id'] ?? null ) || ! is_int( $chain['revision'] ?? null ) || $chain['head_audit_id'] < $deleted['id'] || $chain['head_audit_id'] >= $done['id'] || $chain['revision'] < 1 || $chain['revision'] !== (int) $state['revision'] - 1 ) {
                throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '完了時の再清掃監査の起点・revisionを確認できません。完了記録は変更せず、監査を調査してください。' );
            }
        }
        if ( $chain['head_audit_id'] !== $deleted['id'] ) {
            $zero_proof = $report['proof']; $zero_proof['counts'] = array_fill_keys( array_keys( $report['counts'] ), 0 );
            $cursor = $chain['head_audit_id']; $revision = $chain['revision']; $depth = 0;
            while ( $cursor !== $deleted['id'] ) {
                if ( ++$depth > 100 ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '再清掃監査の確認上限です。手動調査が必要です。' ); }
                $clean = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_residuals_cleaned', $cursor, $db );
                $previous = $clean['value']['previous_audit_id'] ?? null;
                if ( $clean['id'] <= $deleted['id'] || ( $clean['value']['deletion_audit_id'] ?? null ) !== $deleted['id'] || ( $clean['value']['phase'] ?? null ) !== 'deleting' || ( $clean['value']['proof'] ?? null ) !== $zero_proof || ( $clean['value']['remaining'] ?? null ) !== $zero_proof['counts'] || ( $clean['value']['revision_before'] ?? null ) !== $revision - 1 || ! is_int( $previous ) || $previous < $deleted['id'] || $previous >= $cursor ) {
                    throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '削除後再清掃の監査・revisionが不整合です。' );
                }
                $cursor = $previous; --$revision;
            }
        }

        if ( $state['phase'] === 'completed' ) {
            $historical_proof = $report['proof']; $historical_proof['counts'] = array_fill_keys( array_keys( $report['counts'] ), 0 );
            if ( ! is_string( $state['completed_at'] ?? null ) || ( $done['value']['completed_at_utc'] ?? null ) !== $state['completed_at'] || ( $done['value']['proof'] ?? null ) !== $historical_proof ) { throw new EGOV_Law_Monitor_Withdrawal_Completion_Error( '完了日時・完了監査が不整合です。' ); }
            $report['completed'] = array( 'at' => $state['completed_at'], 'admin_id' => $done['admin_id'], 'audit_id' => $done['id'] );
        }
    } catch ( Throwable $error ) { $report['proof'] = null; $report['current_retention'] = null; $report['errors'][] = $error instanceof EGOV_Law_Monitor_Withdrawal_Completion_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Start_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Retention_Error ? $error->getMessage() : '必要な監査・保持情報の読み取りまたは検証に失敗しました。'; }
    $report['ok'] = ! $report['errors'] && $report['proof'] !== null;
    return $report;
}

function egov_law_monitor_withdrawal_completion_token( $user_id, $state, $report ) {
    $json = wp_json_encode( array( $user_id, $state['process_uuid'], (string) $state['revision'], $report['proof'], $report['current_retention'] ) );
    if ( $json === false ) { throw new RuntimeException(); }
    return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
}

function egov_law_monitor_complete_withdrawal( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', '管理者権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! is_string( $fields['complete_nonce'] ?? null ) || ! wp_verify_nonce( $fields['complete_nonce'], 'egov_withdrawal_complete_' . $user_id ) ) { return new WP_Error( 'invalid_nonce', '対象ID・nonceを確認できません。' ); }
    if ( ( $fields['confirm_complete'] ?? null ) !== '1' || ! is_string( $fields['complete_token'] ?? null ) ) { return new WP_Error( 'confirmation_required', '残存・保持記録の最終確認が必要です。' ); }
    $result = egov_law_monitor_withdrawal_user_transaction( $user_id, static function ( $db, $tables, $state ) use ( $user_id, $fields ) {
        if ( $state['phase'] !== 'deleting' ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( 'deletingの場合のみ完了できます。完了済みは再実行できません。' ); }
        $report = egov_law_monitor_withdrawal_completion_snapshot( $user_id, $state, $db );
        if ( ! $report['ok'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( implode( ' ', $report['errors'] ) ); }
        if ( ! hash_equals( egov_law_monitor_withdrawal_completion_token( $user_id, $state, $report ), $fields['complete_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '確認後に状態が変わりました。残存データを再確認してください。' ); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $audit = egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, 'withdrawal_completed', array( 'process_uuid' => $state['process_uuid'], 'phase' => 'completed', 'completed_at_utc' => $now, 'proof' => $report['proof'], 'retention_verified' => $report['current_retention'], 'cleanup_chain' => array( 'head_audit_id' => (int) $state['last_audit_id'], 'revision' => (int) $state['revision'] ) ), '管理者が残存0件と必要な監査・契約・同意の保持を最終確認し、退会完了を記録' );
        if ( ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET phase = ?, revision = revision + 1, completed_at = ?, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ?', array( 'completed', $now, $audit, $now, $state['process_uuid'], $user_id, 'deleting', (int) $state['revision'] ) ) || $db->affected_rows !== 1 ) { throw new RuntimeException(); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( ! $saved || $saved['phase'] !== 'completed' || $saved['completed_at'] !== $now || (int) $saved['last_audit_id'] !== $audit || (int) $saved['revision'] !== (int) $state['revision'] + 1 ) { throw new RuntimeException(); }
        return true;
    }, $factory );
    if ( is_wp_error( $result ) && $result->get_error_code() === 'withdrawal_user_unknown' ) { return new WP_Error( 'withdrawal_completion_unknown', '退会完了の確定・取消結果が不明です。成功と断定せず、再読込してphase・完了監査・完了日時を確認してください。' ); }
    return $result;
}
