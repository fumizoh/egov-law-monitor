<?php
/** Administrator-only preflight. SELECTs only; no entitlement/schema/cleanup helpers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/admin-withdrawal-history.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-policy.php';

function egov_law_monitor_withdrawal_check_target( $user ) {
    return egov_law_monitor_withdrawal_target( $user );
}

/** Unlike several legacy getters, a failed read is never returned as an empty list. */
function egov_law_monitor_withdrawal_check_rows( $sql, $params ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
    if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { throw new RuntimeException(); }
    return $rows;
}

function egov_law_monitor_withdrawal_check_data( $user_id ) {
    global $wpdb;
    $prefix = $wpdb->prefix;
    foreach ( array( $prefix, $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->comments, $wpdb->links ) as $identifier ) {
        if ( ! is_string( $identifier ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $identifier ) ) { throw new RuntimeException(); }
    }
    $result = array();
    $queries = array(
        'meta' => array( "SELECT meta_key, CASE WHEN meta_key = '_application_passwords' THEN 'present' ELSE meta_value END AS meta_value FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key IN (%s, %s, %s, %s, %s)", array( $user_id, 'egov_law_monitor_plan', 'egov_law_monitor_notifications', 'egov_law_monitor_ai_summary_trial_usage', 'egov_law_monitor_standard_consent', '_application_passwords' ) ),
        'contracts' => array( "SELECT * FROM `{$prefix}egov_square_subscriptions` WHERE user_id = %d ORDER BY id DESC LIMIT 100", array( $user_id ) ),
        'cancellations' => array( "SELECT * FROM `{$prefix}egov_square_cancellations` WHERE user_id = %d ORDER BY id DESC LIMIT 100", array( $user_id ) ),
        'pending' => array( "SELECT COUNT(*) AS total FROM `{$prefix}egov_square_cancellations` WHERE user_id = %d AND completed_at IS NULL", array( $user_id ) ),
        'watches' => array( "SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS stopped FROM `{$prefix}law_watch_settings` WHERE user_id = %d", array( $user_id ) ),
        'ai' => array( "SELECT COUNT(*) AS total FROM `{$prefix}egov_law_ai_summary_history` WHERE user_id = %d", array( $user_id ) ),
        'posts' => array( "SELECT post_type, COUNT(*) AS total FROM `{$wpdb->posts}` WHERE post_author = %d GROUP BY post_type", array( $user_id ) ),
        'comments' => array( "SELECT COUNT(*) AS total FROM `{$wpdb->comments}` WHERE user_id = %d", array( $user_id ) ),
        'links' => array( "SELECT COUNT(*) AS total FROM `{$wpdb->links}` WHERE link_owner = %d", array( $user_id ) ),
    );
    foreach ( $queries as $key => $query ) {
        try { $result[$key] = egov_law_monitor_withdrawal_check_rows( ...$query ); }
        catch ( Throwable $error ) { $result[$key] = null; }
    }
    foreach ( array( 'pending', 'watches', 'ai', 'comments', 'links' ) as $key ) {
        $columns = $key === 'watches' ? array( 'total', 'active', 'stopped' ) : array( 'total' );
        if ( $result[$key] === null || count( $result[$key] ) !== 1 ) { $result[$key] = null; continue; }
        foreach ( $columns as $column ) {
            $count = $result[$key][0][$column] ?? null;
            if ( ! ( is_string( $count ) || is_int( $count ) ) || ! ctype_digit( (string) $count ) ) { $result[$key] = null; break; }
        }
    }
    try {
        $table = $prefix . 'egov_square_checkout_attempts';
        $exists = egov_law_monitor_withdrawal_check_rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $table ) );
        if ( count( $exists ) > 1 || ( $exists && ( $exists[0]['TABLE_NAME'] ?? '' ) !== $table ) ) { throw new RuntimeException(); }
        $result['legacy'] = $exists ? egov_law_monitor_withdrawal_check_rows( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT 100", array( $user_id ) ) : 'absent';
    } catch ( Throwable $error ) { $result['legacy'] = null; }
    return $result;
}

function egov_law_monitor_render_withdrawal_check() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'このページにアクセスする権限がありません。' ); }
    if ( ! empty( $_POST ) || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) { wp_die( '退会前チェックは閲覧専用です。' ); }
    $raw = $_GET['user_id'] ?? null;
    if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]*\z/', $raw ) || (string) (int) $raw !== $raw ) { wp_die( '有効なユーザーIDを指定してください。' ); }
    $user = get_userdata( (int) $raw );
    if ( ! $user ) { wp_die( '対象ユーザーは存在しません。削除済みユーザーの履歴はユーザー管理一覧から確認してください。' ); }
    if ( ! egov_law_monitor_withdrawal_check_target( $user ) ) { wp_die( '管理者本人・管理者権限・サービス用または一般会員以外のアカウントは対象外です。' ); }
    try { $data = egov_law_monitor_withdrawal_check_data( (int) $raw ); }
    catch ( Throwable $error ) { wp_die( '読み取りエラー：保存先を確認できません。' ); }
    $meta = array();
    if ( $data['meta'] !== null ) {
        foreach ( $data['meta'] as $row ) {
            if ( array_key_exists( $row['meta_key'], $meta ) ) { $data['meta'] = null; break; }
            $meta[$row['meta_key']] = $row['meta_value'];
        }
    }
    // Do not display application password hashes or accidentally treat API users as members.
    if ( $data['meta'] !== null && isset( $meta['_application_passwords'] ) ) { echo '<div class="notice notice-warning"><p>Application Passwordが登録されています。サービス用途の有無を管理者が確認してください。</p></div>'; }
    $errors = in_array( null, $data, true );
    $plan = $data['meta'] === null ? '読み取りエラー' : ( $meta['egov_law_monitor_plan'] ?? 'free' );
    if ( $plan === '' ) { $plan = 'free'; }
    if ( ! in_array( $plan, array( 'free', 'standard' ), true ) ) { $errors = true; $plan = '確認できません'; }
    $notifications = $data['meta'] === null ? '読み取りエラー' : ( (bool) ( $meta['egov_law_monitor_notifications'] ?? '1' ) ? 'ON' : 'OFF' );
    if ( ( $meta['egov_law_monitor_notifications'] ?? null ) === '' ) { $notifications = 'ON'; }
    $trial = $meta['egov_law_monitor_ai_summary_trial_usage'] ?? '0';
    if ( ! is_string( $trial ) || ! ctype_digit( $trial ) ) { $errors = true; $trial = '不正な記録'; }
    $consent = null;
    if ( isset( $meta['egov_law_monitor_standard_consent'] ) ) {
        $consent = @unserialize( $meta['egov_law_monitor_standard_consent'], array( 'allowed_classes' => false ) );
        if ( ! is_array( $consent ) || ! is_string( $consent['agreed_at'] ?? null ) || ! is_string( $consent['terms_version'] ?? null ) ) { $errors = true; $consent = false; }
    }
    $attention = $plan === 'standard' || ! empty( $data['contracts'] ) || ! empty( $data['posts'] ) || ( is_array( $data['legacy'] ) && $data['legacy'] ) || (int) ( $data['pending'][0]['total'] ?? 0 ) > 0 || (int) ( $data['comments'][0]['total'] ?? 0 ) > 0 || (int) ( $data['links'][0]['total'] ?? 0 ) > 0;
    echo '<div class="wrap"><h1>退会前チェック</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor' ) ) . '">ユーザー管理へ戻る</a></p><div class="notice ' . ( $errors ? 'notice-error' : 'notice-warning' ) . ' inline"><p><strong>' . ( $errors ? '読み取りエラー・確認できない情報あり' : ( $attention ? '確認事項あり' : '契約等の確認対象記録なし' ) ) . '</strong></p></div><p>退会前チェックはWordPress側の保存情報です。Square側の契約・課金状態を保証するものではありません。</p><p>本人確認・Square Dashboardでの課金停止と利用終了の確認は別途必要です。この画面は退会可能と判定せず、削除・プラン変更を行いません。</p><p>投稿用アカウントの実際のSecrets設定はWordPressから確認できません。一般会員として表示されてもサービス用でないことを管理者が確認してください。</p>';
    egov_law_monitor_render_retained_rows( array( array( 'id' => $raw, 'login' => $user->user_login, 'email' => $user->user_email, 'plan' => in_array( $plan, array( 'free', 'standard' ), true ) ? ucfirst( $plan ) : $plan, 'registered' => $user->user_registered ) ), array( 'id' => 'ユーザーID', 'login' => 'ユーザー名', 'email' => 'メールアドレス', 'plan' => '現在のプラン', 'registered' => '登録日時' ) );
    $sections = array(
        'contracts' => array( 'Square契約情報（保存済み・最新100件）', array( 'id' => '契約ID', 'square_customer_id' => 'Customer ID', 'square_subscription_id' => 'Subscription ID', 'created_at' => '登録日時', 'verified_at' => '最終確認日時', 'admin_note' => '管理者メモ' ) ),
        'cancellations' => array( '解約申請（最新100件）', array( 'id' => '申請ID', 'requested_at' => '申請日時', 'end_date' => '利用終了日', 'completed_at' => 'WordPress側完了日時' ) ),
        'legacy' => array( '旧申込データ（最新100件）', array( 'id' => '申込レコードID', 'state' => '申込状態', 'created_at' => '記録日時' ) ),
        'posts' => array( '所有投稿・メディア', array( 'post_type' => '投稿タイプ（attachmentはメディア）', 'total' => '件数' ) ),
    );
    foreach ( $sections as $key => $section ) {
        echo '<h2>' . esc_html( $section[0] ) . '</h2>';
        if ( $key === 'cancellations' ) { echo '<p><strong>未完了申請件数：' . esc_html( $data['pending'] === null ? '読み取りエラー' : ( $data['pending'][0]['total'] ?? '確認できません' ) ) . '</strong></p>'; }
        if ( $data[$key] === null ) { echo '<p>読み取りエラー：取得できません。</p>'; }
        elseif ( $data[$key] === 'absent' ) { echo '<p>対象テーブルなし</p>'; }
        else { egov_law_monitor_render_retained_rows( $data[$key], $section[1] ); }
    }
    echo '<h2>法令ウォッチ・AI要約・所有データ件数</h2>';
    $counts = array( 'watches' => array( 'total' => 'キーワード総数', 'active' => '有効中', 'stopped' => '停止中' ), 'ai' => array( 'total' => 'AI個人利用履歴' ), 'comments' => array( 'total' => '本人が投稿したコメント' ), 'links' => array( 'total' => '所有リンク' ) );
    foreach ( $counts as $key => $columns ) {
        echo '<h3>' . esc_html( implode( '・', $columns ) ) . '</h3>';
        if ( $data[$key] === null || count( $data[$key] ) !== 1 ) { echo '<p>読み取りエラー：件数を確認できません。</p>'; }
        else { egov_law_monitor_render_retained_rows( $data[$key], $columns ); }
    }
    echo '<p>メール通知設定：' . esc_html( $notifications ) . ' ／ Freeお試し利用回数：' . esc_html( $data['meta'] === null ? '読み取りエラー' : $trial ) . '</p><h2>Standard利用規約への同意記録</h2>';
    if ( $data['meta'] === null ) { echo '<p>読み取りエラー</p>'; }
    elseif ( $consent === null ) { echo '<p>記録なし</p>'; }
    elseif ( $consent === false ) { echo '<p>記録形式を確認できません。</p>'; }
    else { egov_law_monitor_render_retained_rows( array( $consent ), array( 'agreed_at' => '同意日時（UTC・保存値）', 'terms_version' => '規約バージョン' ) ); }
    echo '<p>投稿・メディアは削除時の移管先や投稿タイプ設定により扱いが異なります。本人のコメントはユーザー削除だけで全削除されるとは限りません。所有投稿の削除に伴って消えるコメントもあります。UsersWPのプロフィール・画像や他プラグインの情報は別途確認してください。</p></div>';
}
