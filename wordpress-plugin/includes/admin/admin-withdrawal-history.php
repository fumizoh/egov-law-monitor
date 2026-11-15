<?php
/** Read-only retained records. No schema checks, writes, plan inference or Square calls. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_render_deleted_user_list() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    global $wpdb;
    $raw = $_GET['deleted_after'] ?? '0';
    if ( ! is_scalar( $raw ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', (string) $raw ) || (string) (int) $raw !== (string) $raw ) { wp_die( '履歴一覧の指定が不正です。' ); }
    $after = (int) $raw; $parts = array(); $params = array();
    foreach ( array( $wpdb->users, $wpdb->prefix . 'egov_square_subscriptions', $wpdb->prefix . 'egov_square_admin_log', $wpdb->prefix . 'egov_square_cancellations' ) as $table ) {
        if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { wp_die( '保存先を確認できません。' ); }
    }
    foreach ( array( 'egov_square_subscriptions', 'egov_square_admin_log', 'egov_square_cancellations' ) as $suffix ) {
        $parts[] = "(SELECT DISTINCT r.user_id FROM `{$wpdb->prefix}{$suffix}` r WHERE r.user_id > %d AND NOT EXISTS (SELECT 1 FROM `{$wpdb->users}` u WHERE u.ID = r.user_id) ORDER BY r.user_id LIMIT %d)";
        array_push( $params, $after, 101 );
    }
    $params[] = 101;
    $sql = 'SELECT user_id FROM (' . implode( ' UNION ', $parts ) . ') AS deleted_history_users ORDER BY user_id LIMIT %d';
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
    echo '<h2>削除済みユーザーの契約履歴</h2><p>WordPressに存在せず、契約・監査・解約申請の記録があるユーザーIDです。各ページ100件を表示します。</p>';
    if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { echo '<p>履歴一覧を取得できません。保存先を管理者が確認してください。</p>'; return; }
    $more = count( $rows ) > 100; $rows = array_slice( $rows, 0, 100 );
    echo '<table class="widefat striped"><thead><tr><th>ユーザーID</th><th>履歴</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        $id = (string) $row['user_id'];
        $url = add_query_arg( array( 'page' => 'egov-square-subscriptions', 'user_id' => $id ), admin_url( 'admin.php' ) );
        echo '<tr><td>' . esc_html( $id ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">履歴を表示</a></td></tr>';
    }
    if ( ! $rows ) { echo '<tr><td colspan="2">該当する削除済みユーザーの記録はありません。</td></tr>'; }
    echo '</tbody></table><p>';
    if ( $after > 0 ) { echo '<a href="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor' ) ) . '">履歴一覧の先頭へ</a> '; }
    if ( $more ) { echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'egov-law-monitor', 'deleted_after' => end( $rows )['user_id'] ), admin_url( 'admin.php' ) ) ) . '">次の100件</a>'; }
    echo '</p>';
}

function egov_law_monitor_render_retained_rows( $rows, $columns ) {
    echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
    foreach ( $columns as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ( $rows as $row ) {
        echo '<tr>';
        foreach ( $columns as $key => $label ) {
            echo '<td style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( $row[$key] ?? '未記録' ) . '</td>';
        }
        echo '</tr>';
    }
    if ( ! $rows ) { echo '<tr><td colspan="' . count( $columns ) . '">保存済みの記録はありません。</td></tr>'; }
    echo '</tbody></table></div>';
}

function egov_law_monitor_render_withdrawal_history( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'このページにアクセスする権限がありません。' ); }
    if ( ! is_int( $user_id ) || $user_id < 1 || ! empty( $_POST ) || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) { wp_die( '履歴は閲覧専用です。' ); }
    global $wpdb;
    $sets = array();
    foreach ( array( 'contracts' => 'egov_square_subscriptions', 'logs' => 'egov_square_admin_log', 'cancellations' => 'egov_square_cancellations' ) as $key => $suffix ) {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$suffix} WHERE user_id = %d ORDER BY id DESC LIMIT 100", $user_id ), ARRAY_A );
        $sets[$key] = is_array( $rows ) && $wpdb->last_error === '' ? $rows : null;
    }
    echo '<div class="wrap"><h1>Square契約管理</h1>';
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor' ) ) . '">ユーザー管理へ戻る</a></p><h2>削除済みユーザー（ID: ' . esc_html( $user_id ) . '）</h2><p>現在WordPressに存在しないIDの保存記録です。登録歴や氏名・メール・現在のプランは推測しません。変更操作はできません。</p><p>各履歴は最新100件です。Square側の現在の契約・支払・解約状態はこの画面では確認していません。契約状態の専用保存項目はありません。</p>';
    foreach ( array( 'contracts' => '登録済みSquare契約', 'cancellations' => '解約申請履歴', 'logs' => 'Square操作・監査履歴' ) as $key => $title ) {
        echo '<h2>' . esc_html( $title ) . '</h2>';
        if ( $sets[$key] === null ) { echo '<div class="notice notice-error inline"><p>記録を取得できません。保存先を管理者が確認してください。</p></div>'; continue; }
        if ( $key === 'logs' ) { egov_law_monitor_render_square_audit( $sets[$key], false ); }
        elseif ( $key === 'contracts' ) {
            egov_law_monitor_render_retained_rows( $sets[$key], array( 'id' => '契約ID', 'square_customer_id' => 'Customer ID', 'square_subscription_id' => 'Subscription ID', 'created_at' => '登録日時', 'updated_at' => '更新日時', 'verified_at' => '確認日時', 'verified_by' => '確認管理者ID', 'admin_note' => '管理者メモ' ) );
        } else {
            echo '<p>完了日時はWordPress側の利用終了処理の記録です。Squareでの解約完了を証明しません。</p>';
            egov_law_monitor_render_retained_rows( $sets[$key], array( 'id' => '申請ID', 'contract_id' => '契約ID', 'approval_id' => '承認履歴ID', 'requested_at' => '申請日時', 'end_date' => '利用終了日', 'updated_at' => '更新日時', 'completed_at' => 'WordPress側完了日時' ) );
        }
    }
    echo '</div>';
}
