<?php
/** Read-only retention verification, shared by start, pre-deletion and completion. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class EGOV_Law_Monitor_Withdrawal_Retention_Error extends RuntimeException {}

/** Normalize text-protocol and prepared mysqli values without changing stored data. */
function egov_law_monitor_withdrawal_canonical_rows( $rows ) {
    return array_map( static function ( $row ) {
        ksort( $row );
        foreach ( $row as &$value ) { if ( $value !== null ) { if ( ! is_scalar( $value ) ) { throw new RuntimeException(); } $value = (string) $value; } }
        unset( $value ); return $row;
    }, $rows );
}

function egov_law_monitor_withdrawal_retention_domain( $kind, $row ) {
    if ( ! is_array( $row ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持対象の形式を確認できません。' ); }
    $columns = array(
        'contracts' => array( 'id', 'user_id', 'square_customer_id', 'square_subscription_id', 'admin_note', 'verified_at', 'verified_by', 'created_at', 'updated_at' ),
        // Mail delivery/claim timestamps are operational, not contract identity/history.
        'cancellations' => array( 'id', 'user_id', 'contract_id', 'approval_id', 'requested_at', 'end_date', 'completed_at', 'revision' ),
    );
    return egov_law_monitor_withdrawal_canonical_rows( array( isset( $columns[$kind] ) ? array_intersect_key( $row, array_flip( $columns[$kind] ) ) : $row ) )[0];
}

function egov_law_monitor_withdrawal_retention_hash( $value ) {
    $json = wp_json_encode( $value );
    if ( $json === false ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持情報を検証できません。' ); }
    return hash( 'sha256', $json );
}

function egov_law_monitor_withdrawal_retention_consent( $data ) {
    $record = null; $found = false;
    if ( ! is_array( $data['meta'] ?? null ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '同意記録を読み取れません。' ); }
    foreach ( $data['meta'] as $row ) {
        if ( $row['meta_key'] !== 'egov_law_monitor_standard_consent' ) { continue; }
        if ( $found ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '同意記録が重複しています。' ); }
        $found = true; $record = @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) );
        if ( ! is_array( $record ) || ! is_string( $record['agreed_at'] ?? null ) || trim( $record['agreed_at'] ) === '' || ! is_string( $record['terms_version'] ?? null ) || trim( $record['terms_version'] ) === '' || ( isset( $record['conditions_version'] ) && ! is_string( $record['conditions_version'] ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持する同意記録の形式が不正です。' ); }
        $record = array_intersect_key( $record, array_flip( array( 'agreed_at', 'terms_version', 'conditions_version' ) ) ); ksort( $record );
    }
    return array( 'present' => $found, 'hash' => egov_law_monitor_withdrawal_retention_hash( $record ) );
}

/** IDs and hashes only: never duplicate notes, URLs, requests or credentials. */
function egov_law_monitor_withdrawal_retention_manifest( $user_id, $data ) {
    $manifest = array( 'version' => 1, 'user_id' => $user_id );
    foreach ( array( 'contracts', 'cancellations', 'legacy' ) as $kind ) {
        $rows = $data[$kind] ?? null;
        if ( $kind === 'legacy' && $rows === 'absent' ) { $rows = array(); }
        if ( ! is_array( $rows ) || count( $rows ) >= 100 ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持対象を全件確認できません：' . $kind ); }
        $manifest[$kind] = array();
        foreach ( $rows as $row ) {
            $id = (string) ( $row['id'] ?? '' );
            if ( ! preg_match( '/\A[1-9][0-9]*\z/', $id ) || (string) (int) $id !== $id || isset( $manifest[$kind][$id] ) || ( isset( $row['user_id'] ) && (int) $row['user_id'] !== $user_id ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持対象の識別情報が不正です：' . $kind ); }
            $manifest[$kind][$id] = egov_law_monitor_withdrawal_retention_hash( egov_law_monitor_withdrawal_retention_domain( $kind, $row ) );
        }
        ksort( $manifest[$kind], SORT_NUMERIC );
    }
    $manifest['consent'] = egov_law_monitor_withdrawal_retention_consent( $data );
    return $manifest;
}

/** Existing record changes require a contiguous, administrator-authored before/after chain. */
function egov_law_monitor_withdrawal_retention_compare( $user_id, $baseline, $current, $after_id, $db = null ) {
    if ( ( $baseline['version'] ?? null ) !== 1 || ( $baseline['user_id'] ?? null ) !== $user_id || ( $baseline['consent'] ?? null ) !== $current['consent'] ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '開始時または削除前の同意保持情報が欠落・不一致です。' ); }
    $changes = null; $used = array();
    foreach ( array( 'contracts', 'cancellations', 'legacy' ) as $kind ) {
        if ( ! is_array( $baseline[$kind] ?? null ) || array_keys( $baseline[$kind] ) !== array_keys( $current[$kind] ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '開始時または削除前の記録IDが欠落・差し替えされています：' . $kind ); }
        foreach ( $baseline[$kind] as $id => $hash ) {
            if ( ! is_string( $hash ) || ! preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持記録の検証値が不正です。' ); }
            if ( $hash === $current[$kind][$id] ) { continue; }
            if ( $kind === 'legacy' ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '旧申込記録の変更を正当な監査で追跡できません。' ); }
            if ( $changes === null ) {
                $tables = egov_law_monitor_square_plan_tables();
                $actions = array( 'subscription_updated', 'standard_approved', 'subscription_end_date_set', 'subscription_end_date_changed', 'subscription_cancellation_completed' );
                $changes = egov_law_monitor_withdrawal_rows( "SELECT id, admin_user_id, action, old_value, new_value FROM `{$tables['log']}` WHERE user_id = ? AND id > ? AND action IN (?, ?, ?, ?, ?) ORDER BY id LIMIT 201", array_merge( array( $user_id, $after_id ), $actions ), $db );
                if ( count( $changes ) > 200 ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '変更監査の確認上限に達しました。' ); }
            }
            foreach ( $changes as $log ) {
                $is_contract = in_array( $log['action'], array( 'subscription_updated', 'standard_approved' ), true );
                if ( ( $kind === 'contracts' ) !== $is_contract ) { continue; }
                $before = json_decode( $log['old_value'] ?? '', true ); $after = json_decode( $log['new_value'] ?? '', true );
                $confirmed = is_array( $after ) && ( $after['dashboard_confirmed'] ?? null ) === true;
                if ( $log['action'] === 'standard_approved' ) { $before = $before['contract'] ?? null; $after = $after['contract'] ?? null; }
                if ( ! is_array( $before ) || ! is_array( $after ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '変更前後を確認できない監査があります。' ); }
                if ( (string) ( $before['id'] ?? '' ) !== (string) $id && (string) ( $after['id'] ?? '' ) !== (string) $id ) { continue; }
                if ( (int) ( $log['admin_user_id'] ?? 0 ) < 1 || (int) ( $log['id'] ?? 0 ) <= $after_id || (string) ( $before['id'] ?? '' ) !== (string) $id || (string) ( $after['id'] ?? '' ) !== (string) $id || ( isset( $before['user_id'] ) && (int) $before['user_id'] !== $user_id ) || ( isset( $after['user_id'] ) && (int) $after['user_id'] !== $user_id ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '変更監査の所属・識別情報を確認できません。' ); }
                $before = egov_law_monitor_withdrawal_retention_domain( $kind, $before ); $after = egov_law_monitor_withdrawal_retention_domain( $kind, $after );
                $allowed = $kind === 'contracts' ? ( $log['action'] === 'standard_approved' ? array( 'verified_at', 'verified_by', 'updated_at' ) : array( 'square_customer_id', 'square_subscription_id', 'admin_note', 'updated_at' ) ) : ( $log['action'] === 'subscription_cancellation_completed' ? array( 'completed_at', 'revision' ) : array( 'end_date', 'revision' ) );
                if ( array_keys( $before ) !== array_keys( $after ) || egov_law_monitor_withdrawal_retention_hash( $before ) !== $hash ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '変更前後の監査が連続していません。' ); }
                foreach ( $before as $key => $value ) { if ( $value !== $after[$key] && ! in_array( $key, $allowed, true ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '対応する管理操作では変更できない情報が変わっています。' ); } }
                if ( $kind === 'cancellations' && ( (int) ( $after['revision'] ?? 0 ) !== (int) ( $before['revision'] ?? 0 ) + 1 || ( $log['action'] !== 'subscription_cancellation_completed' && ! $confirmed ) ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '終了日の変更確認・revisionが不正です。' ); }
                $hash = egov_law_monitor_withdrawal_retention_hash( $after ); $used[] = (int) $log['id'];
            }
            if ( $hash !== $current[$kind][$id] ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '現在値へ至る正当な変更監査がありません：' . $kind ); }
        }
    }
    return array_values( array_unique( $used ) );
}

/** Same gate before irreversible deletion and before completion; no data is changed. */
function egov_law_monitor_withdrawal_verify_retention( $user_id, $state, $data, $db = null, $archive = null ) {
    egov_law_monitor_withdrawal_validate_state( $state, $user_id );
    if ( in_array( null, $data, true ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '保持検証に必要な情報を読み取れません。' ); }
    $start = egov_law_monitor_withdrawal_retention_audit( $user_id, $state, 'withdrawal_started', (int) ( $state['started_audit_id'] ?? 0 ), $db );
    $target = array( 'subscriber_only' => true, 'protected_check_passed' => true );
    if ( ( $start['value']['target_validation'] ?? null ) !== $target || ( $start['value']['confirmations'] ?? null ) !== array_keys( egov_law_monitor_withdrawal_start_confirmations() ) || ( $start['value']['manual_evidence_only'] ?? null ) !== true || ( $start['value']['phase'] ?? null ) !== 'started' ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '正当な退会開始の確認監査が不足しています。' ); }
    $watch = egov_law_monitor_withdrawal_retention_audit( $user_id, $state, 'withdrawal_watches_deleted', null, $db );
    $ai = egov_law_monitor_withdrawal_retention_audit( $user_id, $state, 'withdrawal_ai_history_deleted', null, $db );
    if ( min( $watch['id'], $ai['id'] ) <= $start['id'] || ( $watch['value']['remaining'] ?? null ) !== array( 'watches' => 0, 'notifications' => 0 ) || ( $ai['value']['remaining'] ?? null ) !== array( 'history' => 0, 'trial' => 0 ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '後続の清掃監査を確認できません。' ); }
    if ( $archive !== null ) {
        $consent = $archive['evidence']['consent'] ?? null;
        $data['meta'] = $consent === null ? array() : array( array( 'meta_key' => 'egov_law_monitor_standard_consent', 'meta_value' => serialize( $consent ) ) );
    }
    $current = egov_law_monitor_withdrawal_retention_manifest( $user_id, $data );
    $used = egov_law_monitor_withdrawal_retention_compare( $user_id, $start['value']['retention_manifest'] ?? null, $current, $start['id'], $db );
    if ( $archive !== null ) {
        $anchor = $archive['evidence']['retention']['manifest'] ?? null;
        if ( ! is_array( $anchor ) || (int) ( $archive['audit_id'] ?? 0 ) <= max( $watch['id'], $ai['id'] ) ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '削除前の保持記録が不足しています。' ); }
        $used = array_merge( $used, egov_law_monitor_withdrawal_retention_compare( $user_id, $anchor, $current, (int) $archive['audit_id'], $db ) );
    }
    return array( 'manifest' => $current, 'change_audit_ids' => array_values( array_unique( $used ) ), 'start_audit_id' => $start['id'] );
}

function egov_law_monitor_withdrawal_retention_audit( $user_id, $state, $action, $id = null, $db = null ) {
    $tables = egov_law_monitor_square_plan_tables();
    $select = "SELECT action, new_value, id, admin_user_id, created_at FROM `{$tables['log']}`";
    $rows = $id === null
        ? egov_law_monitor_withdrawal_rows( $select . ' WHERE user_id = ? AND action = ? AND id >= ? ORDER BY id DESC LIMIT 1', array( $user_id, $action, (int) $state['started_audit_id'] ), $db )
        : egov_law_monitor_withdrawal_rows( $select . ' WHERE id = ? AND user_id = ?', array( $id, $user_id ), $db );
    $value = count( $rows ) === 1 ? json_decode( $rows[0]['new_value'] ?? '', true ) : null;
    if ( ! is_array( $value ) || $rows[0]['action'] !== $action || ( $value['process_uuid'] ?? null ) !== $state['process_uuid'] || (int) ( $rows[0]['id'] ?? 0 ) < (int) $state['started_audit_id'] || (int) ( $rows[0]['admin_user_id'] ?? 0 ) < 1 || ! is_string( $rows[0]['created_at'] ?? null ) || $rows[0]['created_at'] === '' ) { throw new EGOV_Law_Monitor_Withdrawal_Retention_Error( '同じ退会処理の必要な監査が欠落・不正です：' . $action ); }
    return array( 'id' => (int) $rows[0]['id'], 'admin_id' => (int) $rows[0]['admin_user_id'], 'created_at' => $rows[0]['created_at'], 'value' => $value );
}
