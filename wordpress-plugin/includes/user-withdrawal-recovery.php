<?php
/** Explicit post-deletion cleanup only. Never calls WordPress deletion or completion. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_withdrawal_recovery_snapshot( $user_id, $state, $db = null ) {
    egov_law_monitor_withdrawal_validate_state( $state, $user_id );
    if ( $state['phase'] !== 'deleting' ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '復旧はdeleting段階だけが対象です。' ); }
    try {
        if ( empty( $state['started_audit_id'] ) ) { throw new RuntimeException(); }
        $prepare = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_user_deleting', null, $db );
        $deleted = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_user_deleted', null, $db );
        if ( $deleted['id'] <= $prepare['id'] || ( $deleted['value']['pre_delete_audit_id'] ?? null ) !== $prepare['id'] || ( $deleted['value']['wp_result'] ?? null ) !== true || ( $deleted['value']['call_error'] ?? null ) !== false || ( $deleted['value']['user_exists'] ?? null ) !== false ) { throw new RuntimeException(); }
    } catch ( Throwable $ignored ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除正常終了の永続的な証拠がありません。不存在から成功を補完できません。元の処理と監査を手動調査してください。' ); }
    $report = egov_law_monitor_withdrawal_completion_snapshot( $user_id, $state, $db );
    // proof is populated only after the original successful core result and ALL retention checks.
    if ( $report['proof'] === null || $report['current_retention'] === null ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '削除成功の永続的な監査・保持情報を確認できません。不存在だけでは復旧できません。元の処理と監査を手動調査してください。' ); }
    $counts = $report['counts'];
    foreach ( $counts as $value ) { if ( ! is_int( $value ) || $value < 0 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '残存件数の読み取りに失敗しました。' ); } }
    foreach ( array( 'WordPressユーザー', '所有投稿・メディア', '本人コメント', '所有リンク' ) as $key ) { if ( $counts[$key] !== 0 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( 'ユーザーまたは所有データが残っています。この復旧操作では変更できません。' ); } }
    if ( $counts['全ユーザーメタ'] !== $counts['通知設定メタ'] + $counts['Free試用回数メタ'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '対象外のユーザーメタが残っています。手動調査が必要です。' ); }
    return $report;
}

function egov_law_monitor_reclean_withdrawal( $user_id, $fields, $factory = null ) {
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_users' ) ) { return new WP_Error( 'forbidden', '管理・削除権限が必要です。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! is_string( $fields['recovery_nonce'] ?? null ) || ! wp_verify_nonce( $fields['recovery_nonce'], 'egov_withdrawal_recovery_' . $user_id ) || ( $fields['confirm_recovery'] ?? null ) !== '1' || ! is_string( $fields['recovery_token'] ?? null ) ) { return new WP_Error( 'invalid_recovery', '対象ID・nonce・再清掃確認が不正です。' ); }
    $result = egov_law_monitor_withdrawal_user_transaction( $user_id, static function ( $db, $tables, $state ) use ( $user_id, $fields ) {
        $before = egov_law_monitor_withdrawal_recovery_snapshot( $user_id, $state, $db );
        if ( ! hash_equals( egov_law_monitor_withdrawal_completion_token( $user_id, $state, $before ), $fields['recovery_token'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( 'UUID・revisionまたは残存情報が変更されました。再確認してください。' ); }
        $local = egov_law_monitor_withdrawal_watch_tables(); $ai = egov_law_monitor_withdrawal_ai_tables();
        foreach ( array( $local['watches'], $ai['history'] ) as $table ) {
            $engine = egov_law_monitor_withdrawal_rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $table, 'BASE TABLE' ), $db );
            if ( count( $engine ) !== 1 || $engine[0]['TABLE_NAME'] !== $table || strcasecmp( $engine[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '再清掃対象のInnoDBを確認できません。' ); }
        }
        if ( $before['ok'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '再清掃対象はありません。残存データを再確認してください。' ); }
        foreach ( array( $local['watches'], $ai['history'] ) as $table ) { if ( ! $db->write( "DELETE FROM `{$table}` WHERE user_id = ?", array( $user_id ) ) ) { throw new RuntimeException(); } }
        foreach ( array( 'egov_law_monitor_notifications', 'egov_law_monitor_ai_summary_trial_usage' ) as $key ) { if ( ! $db->write( "DELETE FROM `{$local['meta']}` WHERE user_id = ? AND meta_key = ?", array( $user_id, $key ) ) ) { throw new RuntimeException(); } }
        $after = egov_law_monitor_withdrawal_recovery_snapshot( $user_id, $state, $db );
        if ( ! $after['ok'] ) { throw new EGOV_Law_Monitor_Withdrawal_User_Error( '再清掃後の残存0件・保持条件を確認できません。' ); }
        $audit = egov_law_monitor_withdrawal_user_audit( $db, $tables, $user_id, 'withdrawal_residuals_cleaned', array( 'process_uuid' => $state['process_uuid'], 'phase' => 'deleting', 'deletion_audit_id' => $after['proof']['audit_ids'][4], 'before_counts' => $before['counts'], 'remaining' => $after['counts'], 'proof' => $after['proof'], 'previous_audit_id' => (int) $state['last_audit_id'], 'revision_before' => (int) $state['revision'] ), '管理者が削除成功監査・保持記録を確認し、削除後のウォッチ・通知設定・AI個人履歴・Free試用回数のみ再清掃' );
        if ( ! $db->write( 'UPDATE `' . egov_law_monitor_withdrawal_table() . '` SET revision = revision + 1, last_audit_id = ?, updated_at = ? WHERE process_uuid = ? AND user_id = ? AND phase = ? AND revision = ?', array( $audit, gmdate( 'Y-m-d H:i:s' ), $state['process_uuid'], $user_id, 'deleting', (int) $state['revision'] ) ) || $db->affected_rows !== 1 ) { throw new RuntimeException(); }
        $saved = egov_law_monitor_withdrawal_state( $user_id, $db );
        if ( (int) $saved['revision'] !== (int) $state['revision'] + 1 || (int) $saved['last_audit_id'] !== $audit ) { throw new RuntimeException(); }
        return true;
    }, $factory );
    // Direct SQL metadata cleanup: invalidate after COMMIT or uncertain outcome, never bypass WP caches.
    try { wp_cache_delete( $user_id, 'user_meta' ); } catch ( Throwable $ignored ) { return new WP_Error( 'recovery_cache', 'キャッシュ更新に失敗しました。成功と判断せず状態・監査・残存を再確認してください。' ); }
    return $result;
}

/** Diagnostic classification is evidence-based; absence alone is deliberately insufficient. */
function egov_law_monitor_withdrawal_delete_status( $user_id, $state ) {
    if ( $state['phase'] === 'started' ) { return 'WordPress削除は未実行です。'; }
    try {
        if ( empty( $state['started_audit_id'] ) ) { throw new RuntimeException(); }
        $prepare = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, 'withdrawal_user_deleting' );
        foreach ( array( 'withdrawal_user_deleted', 'withdrawal_user_delete_failed' ) as $action ) {
            try { $record = egov_law_monitor_withdrawal_completion_audit( $user_id, $state, $action ); } catch ( Throwable $ignored ) { continue; }
            $value = $record['value'];
            if ( $record['id'] <= $prepare['id'] || ( $value['pre_delete_audit_id'] ?? null ) !== $prepare['id'] ) { continue; }
            if ( ( $value['core_called'] ?? null ) === false ) { return 'WordPress削除は未実行（直前検証で停止）。保持監査と停止原因の手動調査が必要です。'; }
            if ( ( $value['wp_result'] ?? null ) === true && ( $value['call_error'] ?? null ) === false && ( $value['user_exists'] ?? null ) === false ) { return 'WordPress削除の正常終了と結果監査を確認できます。最新の残存・保持条件は別途再確認してください。'; }
            if ( ( $value['wp_result'] ?? null ) === false && ( $value['call_error'] ?? null ) === false && ( $value['user_exists'] ?? null ) === true ) { return 'WordPress削除が明確に失敗した記録があります。'; }
        }
    } catch ( Throwable $ignored ) {}
    return '削除結果不明、または正常終了後の結果監査保存失敗を区別できません。永続的な成功証拠がなく復旧不能です。不存在から成功監査を補完せず、元の処理・ログを手動調査してください。';
}
