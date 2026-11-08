<?php
/** Manual entitlements: user meta is authoritative; contracts are evidence only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress may use global/custom user tables. Reject cross-database identifiers. */
function egov_law_monitor_square_plan_tables() {
    global $wpdb;
    $tables = array( 'users' => $wpdb->users, 'meta' => $wpdb->usermeta, 'log' => $wpdb->prefix . 'egov_square_admin_log', 'contracts' => $wpdb->prefix . 'egov_square_subscriptions' );
    foreach ( $tables as $name ) {
        if ( ! is_string( $name ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) {
            throw new RuntimeException( '同一DBのユーザーテーブルを確認できません。' );
        }
    }
    return $tables;
}

/** Snapshot without the WP meta cache; reject duplicate/unknown plan rows. */
function egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db = null ) {
    global $wpdb;
    $sql = "SELECT umeta_id, meta_value FROM `{$tables['meta']}` WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id";
    $rows = $db ? $db->rows( str_replace( array( '%d', '%s' ), '?', $sql ), array( $user_id, EGOV_LAW_MONITOR_META_PLAN ) )
        : $wpdb->get_results( $wpdb->prepare( $sql, $user_id, EGOV_LAW_MONITOR_META_PLAN ), ARRAY_A );
    if ( ! is_array( $rows ) || ( $db ?: $wpdb )->last_error !== '' || count( $rows ) > 1 ) {
        throw new RuntimeException( 'プラン情報の読み取り失敗または重複があります。管理者が状態を確認してください。' );
    }
    $raw = $rows ? $rows[0]['meta_value'] : '';
    if ( ! is_string( $raw ) || ! in_array( $raw, array( '', 'free', 'standard' ), true ) ) {
        throw new RuntimeException( '未対応のプラン情報です。管理者が状態を確認してください。' );
    }
    $sql = "SELECT id FROM `{$tables['log']}` WHERE user_id = %d AND action IN ('standard_approved', 'plan_changed') ORDER BY id DESC LIMIT 1";
    $versions = $db ? $db->rows( str_replace( '%d', '?', $sql ), array( $user_id ) )
        : $wpdb->get_results( $wpdb->prepare( $sql, $user_id ), ARRAY_A );
    if ( ! is_array( $versions ) || ( $db ?: $wpdb )->last_error !== '' ) {
        throw new RuntimeException( 'プラン変更履歴を確認できません。' );
    }
    return array( 'meta_id' => $rows ? (string) $rows[0]['umeta_id'] : null, 'raw' => $raw,
        'plan' => $raw === '' ? 'free' : $raw, 'revision' => (string) ( $versions[0]['id'] ?? '0' ) );
}

/** Revision catches free -> standard -> free, even when values return to old state. */
function egov_law_monitor_square_plan_token( $user_id, $snapshot, $record = null, $key = null ) {
    return hash_hmac( 'sha256', wp_json_encode( array( (int) $user_id, $snapshot,
        $record === null ? null : egov_law_monitor_square_record_token( $record, $key ) ) ), $key === null ? wp_salt( 'auth' ) : $key );
}

function egov_law_monitor_square_plan_form_token( $user_id, $record = null ) {
    try {
        return egov_law_monitor_square_plan_token( $user_id, egov_law_monitor_square_plan_snapshot( (int) $user_id, egov_law_monitor_square_plan_tables() ), $record );
    } catch ( Throwable $error ) {
        return new WP_Error( 'square_plan_read_failed', 'プランと変更履歴を確認できません。画面を再読み込みし、DBの状態を確認してください。' );
    }
}

/** No DDL. Confirm actual current-database tables, InnoDB, columns and primary keys. */
function egov_law_monitor_square_plan_storage_ready( $db, $tables ) {
    if ( ! egov_law_monitor_square_storage_ready( $db ) ) { return false; }
    foreach ( array( 'users' => array( 'ID' => 'bigint unsigned' ), 'meta' => array( 'umeta_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext' ) ) as $kind => $required ) {
        $name = $tables[$kind];
        $status = $db->rows( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?', array( $name, 'BASE TABLE' ) );
        if ( count( $status ?: array() ) !== 1 || $status[0]['TABLE_NAME'] !== $name || strcasecmp( $status[0]['ENGINE'] ?? '', 'InnoDB' ) !== 0 ) { return false; }
        $columns = $db->rows( "SHOW FULL COLUMNS FROM `{$name}`" );
        $indexes = $db->rows( "SHOW INDEX FROM `{$name}`" );
        if ( ! is_array( $columns ) || ! is_array( $indexes ) ) { return false; }
        $columns = array_column( $columns, null, 'Field' );
        foreach ( $required as $column => $type ) {
            if ( preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $columns[$column]['Type'] ?? '' ) ) !== $type ) { return false; }
        }
        $primary = array_values( array_filter( $indexes, static function ( $row ) { return $row['Key_name'] === 'PRIMARY'; } ) );
        if ( count( $primary ) !== 1 || $primary[0]['Column_name'] !== ( $kind === 'users' ? 'ID' : 'umeta_id' ) || (int) $primary[0]['Non_unique'] !== 0 || ( $primary[0]['Sub_part'] ?? null ) !== null ) { return false; }
    }
    return ! $db->connection_lost && $db->last_error === '';
}

/** One transaction for plan + selected contract + audit. Never calls Square API. */
function egov_law_monitor_square_change_plan( $user_id, $plan, $expected_token, $nonce, $contract_id = 0, $confirmed = false, $reason = '', ?EGOV_Law_Monitor_Square_Connection $database = null ) {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'この操作を行う権限がありません。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! is_int( $contract_id ) || $contract_id < 0 || ! in_array( $plan, array( 'free', 'standard' ), true ) ) { return new WP_Error( 'invalid_plan_request', 'プラン変更リクエストが不正です。' ); }
    $approving = $contract_id > 0;
    if ( ! $approving && $plan !== 'free' ) { return new WP_Error( 'invalid_plan_request', 'Standardへの変更には登録済み契約の手動承認が必要です。' ); }
    $action = $approving ? 'egov_square_approve_' . $user_id . '_' . $contract_id : 'egov_square_free_' . $user_id;
    if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $action ) ) { return new WP_Error( 'invalid_nonce', '操作を確認できません。画面を再読み込みしてください。' ); }
    if ( ! get_userdata( $user_id ) ) { return new WP_Error( 'invalid_user', '対象ユーザーが存在しません。' ); }
    if ( ! is_string( $reason ) || strlen( $reason ) > 6000 || trim( $reason ) === '' || ( $approving && ( $plan !== 'standard' || $confirmed !== true ) ) ) { return new WP_Error( 'confirmation_required', '変更理由を入力し、Standard承認時はDashboardでの確認チェックも行ってください。' ); }
    $reason = sanitize_textarea_field( $reason );
    if ( trim( $reason ) === '' ) { return new WP_Error( 'confirmation_required', '変更理由を入力してください。' ); }
    $now = current_time( 'mysql' );
    $admin_id = get_current_user_id();
    $key = wp_salt( 'auth' );
    $db = null; $started = false; $committing = false; $committed = false; $invalidate = false;
    try {
        $tables = egov_law_monitor_square_plan_tables();
        $db = $database ?: EGOV_Law_Monitor_Square_Connection::open( $wpdb );
        if ( ! egov_law_monitor_square_plan_storage_ready( $db, $tables ) ) { throw new RuntimeException( '同一DB・InnoDB・必要スキーマを確認できないため変更できません。' ); }
        if ( ! $db->begin() ) { throw new RuntimeException( '変更処理を開始できませんでした。' ); }
        $started = true;
        // Approval and Free changes serialize on this existing user row.
        $users = $db->rows( "SELECT ID FROM `{$tables['users']}` WHERE ID = ? FOR UPDATE", array( $user_id ) );
        if ( ! $users || (int) $users[0]['ID'] !== $user_id ) { throw new RuntimeException( '対象ユーザーを確認できません。' ); }
        $old = null;
        if ( $approving ) {
            $records = $db->rows( "SELECT * FROM `{$tables['contracts']}` WHERE id = ? FOR UPDATE", array( $contract_id ) );
            $old = $records[0] ?? null;
            if ( ! $old || (int) $old['user_id'] !== $user_id ) { throw new RuntimeException( '対象ユーザーの契約が見つかりません。' ); }
            foreach ( array( 'square_customer_id', 'square_subscription_id' ) as $field ) {
                if ( ! is_string( $old[$field] ) || ! preg_match( '/\A[A-Za-z0-9_-]{1,255}\z/', $old[$field] ) ) { throw new RuntimeException( '登録済み契約IDが不正です。' ); }
            }
        }
        // Current locking read, not a cached WP value; holds existing meta rows too.
        if ( $db->rows( "SELECT umeta_id FROM `{$tables['meta']}` WHERE user_id = ? AND meta_key = ? FOR UPDATE", array( $user_id, EGOV_LAW_MONITOR_META_PLAN ) ) === false ) { throw new RuntimeException( 'プラン情報をロックできません。' ); }
        $before = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
        if ( ! is_string( $expected_token ) || ! hash_equals( egov_law_monitor_square_plan_token( $user_id, $before, $old, $key ), $expected_token ) ) { throw new RuntimeException( 'プランまたは契約が変更されています。画面を再読み込みしてください。' ); }
        if ( $approving && $before['plan'] === 'standard' ) { throw new RuntimeException( '既にStandardです。二重承認はできません。' ); }
        if ( ! $approving && $before['plan'] !== 'standard' ) { throw new RuntimeException( '現在Standardではありません。二重操作はできません。' ); }
        $invalidate = true;
        $saved = $before['meta_id'] === null
            ? $db->write( "INSERT INTO `{$tables['meta']}` (user_id, meta_key, meta_value) VALUES (?, ?, ?)", array( $user_id, EGOV_LAW_MONITOR_META_PLAN, $plan ) )
            : $db->write( "UPDATE `{$tables['meta']}` SET meta_value = ? WHERE umeta_id = ? AND user_id = ? AND meta_key = ?", array( $plan, (int) $before['meta_id'], $user_id, EGOV_LAW_MONITOR_META_PLAN ) );
        if ( ! $saved ) { throw new RuntimeException( 'プランを保存できませんでした。' ); }
        $after = egov_law_monitor_square_plan_snapshot( $user_id, $tables, $db );
        if ( $after['plan'] !== $plan || $after['meta_id'] === null ) { throw new RuntimeException( 'プラン保存結果を確認できません。' ); }
        $new = null;
        if ( $approving ) {
            if ( ! $db->write( "UPDATE `{$tables['contracts']}` SET verified_at = ?, verified_by = ?, updated_at = ? WHERE id = ? AND user_id = ?", array( $now, $admin_id, $now, $contract_id, $user_id ) ) ) { throw new RuntimeException( '契約確認記録を保存できませんでした。' ); }
            $records = $db->rows( "SELECT * FROM `{$tables['contracts']}` WHERE id = ?", array( $contract_id ) );
            $new = $records[0] ?? null;
            if ( ! $new || $new['verified_at'] !== $now || (int) $new['verified_by'] !== $admin_id ) { throw new RuntimeException( '契約確認記録を再取得できません。' ); }
        }
        $old_json = wp_json_encode( array( 'plan' => $before['plan'], 'plan_meta' => $before, 'contract' => $old ) );
        $new_json = wp_json_encode( array( 'plan' => $plan, 'contract' => $new ) );
        if ( $old_json === false || $new_json === false || ! $db->write( "INSERT INTO `{$tables['log']}` (user_id, admin_user_id, action, old_value, new_value, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", array( $user_id, $admin_id, $approving ? 'standard_approved' : 'plan_changed', $old_json, $new_json, $reason, $now ) ) ) { throw new RuntimeException( '監査履歴を保存できませんでした。' ); }
        $committing = true;
        if ( ! $db->commit() ) { throw new RuntimeException( '確定応答を確認できません。' ); }
        $committed = true;
        return true;
    } catch ( Throwable $error ) {
        $rolled_back = false;
        if ( $started ) { try { $rolled_back = $db->rollback() !== false; } catch ( Throwable $rollback_error ) { /* unknown */ } }
        $unknown = $started && ( $committing || ! $rolled_back );
        error_log( 'e-Gov Square: plan operation ' . ( $unknown ? 'outcome unknown' : 'failed' ) . '; user_id=' . $user_id . '; contract_id=' . $contract_id );
        return new WP_Error( $unknown ? 'square_plan_outcome_unknown' : 'square_plan_failed', $unknown
            ? '変更結果または取り消しを確認できません。再操作前に現在のプラン・契約確認記録・操作履歴を確認してください。'
            : '変更できませんでした。' . ( $error instanceof RuntimeException ? $error->getMessage() : 'DBの状態を確認してください。' ) . ( $rolled_back ? ' 変更を取り消しました。' : '' ) );
    } finally {
        if ( $db ) { $db->close(); }
        if ( $invalidate ) {
            try { wp_cache_delete( $user_id, 'user_meta' ); }
            catch ( Throwable $cache_error ) {
                error_log( 'e-Gov Square: user meta cache invalidation failed; user_id=' . $user_id );
                if ( $committed ) { return new WP_Error( 'square_plan_cache_warning', 'DBへの変更と監査履歴の保存は完了しましたが、キャッシュを更新できません。再承認せず、管理者がキャッシュと現在のプランを確認してください。' ); }
            }
        }
    }
}

/** Post-commit operation: never confuse watch failure with an uncommitted plan. */
function egov_law_monitor_square_free_watch_limit( $user_id ) {
    global $wpdb;
    try {
        $snapshot = egov_law_monitor_square_plan_snapshot( $user_id, egov_law_monitor_square_plan_tables() );
        if ( $snapshot['plan'] !== 'free' || egov_law_monitor_get_plan( $user_id ) !== 'free' ) { throw new RuntimeException(); }
        egov_law_monitor_enforce_watch_limit( $user_id );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}law_watch_settings WHERE user_id = %d AND is_active = 1", $user_id ), ARRAY_A );
        if ( ! is_array( $rows ) || $wpdb->last_error !== '' || count( $rows ) > egov_law_monitor_get_watch_limit( $user_id ) ) { throw new RuntimeException(); }
        $current = egov_law_monitor_square_plan_snapshot( $user_id, egov_law_monitor_square_plan_tables() );
        if ( $current !== $snapshot ) { throw new RuntimeException(); }
        return true;
    } catch ( Throwable $error ) {
        error_log( 'e-Gov Square: Free plan committed; watch limit unconfirmed; user_id=' . (int) $user_id );
        return new WP_Error( 'square_free_watch_warning', 'Freeへのプラン変更と監査履歴の保存は確定済みですが、ウォッチ上限の適用を確認できません。再度Free変更せず、現在のプラン・キャッシュ・有効ウォッチを確認してください。一部のウォッチだけ停止している可能性があります。' );
    }
}

/** User-level downgrade; contract evidence and usage counters are not modified. */
function egov_law_monitor_square_make_free( $user_id, $token, $nonce, $reason, ?EGOV_Law_Monitor_Square_Connection $database = null ) {
    $result = egov_law_monitor_square_change_plan( $user_id, 'free', $token, $nonce, 0, false, $reason, $database );
    if ( is_wp_error( $result ) ) {
        if ( $result->get_error_code() === 'square_plan_cache_warning' ) {
            return array( 'warning' => true, 'message' => 'Freeへのプラン変更と監査履歴の保存は確定済みですが、キャッシュ更新に失敗したためウォッチ上限処理は実行していません。再度Free変更せず、キャッシュと有効ウォッチを確認してください。' );
        }
        return $result;
    }
    $watch = egov_law_monitor_square_free_watch_limit( $user_id );
    return array( 'warning' => is_wp_error( $watch ), 'message' => is_wp_error( $watch ) ? $watch->get_error_message() : 'Freeへのプラン変更・監査履歴の保存とウォッチ上限の適用を確認しました。Square側の解約・課金停止は行っていません。' );
}
