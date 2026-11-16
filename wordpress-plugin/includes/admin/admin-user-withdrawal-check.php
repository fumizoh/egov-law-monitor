<?php
/** Existing preflight with individually authorized manual withdrawal steps. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/admin-withdrawal-history.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-policy.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-start.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-watches.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-ai.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-user.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-completion.php';
require_once dirname( __DIR__ ) . '/user-withdrawal-recovery.php';
require_once __DIR__ . '/admin-withdrawal-post.php';

function egov_law_monitor_withdrawal_check_target( $user ) {
    return egov_law_monitor_withdrawal_target( $user );
}

/** Unlike several legacy getters, a failed read is never returned as an empty list. */
function egov_law_monitor_withdrawal_check_rows( $sql, $params, $db = null ) {
    global $wpdb;
    $rows = $db ? $db->rows( str_replace( array( '%d', '%s' ), '?', $sql ), $params ) : $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
    if ( ! is_array( $rows ) || ( $db ?: $wpdb )->last_error !== '' || ( $db && $db->connection_lost ) ) { throw new RuntimeException(); }
    return $rows;
}

function egov_law_monitor_withdrawal_check_data( $user_id, $db = null ) {
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
        try { $result[$key] = egov_law_monitor_withdrawal_check_rows( $query[0], $query[1], $db ); }
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
        $exists = egov_law_monitor_withdrawal_check_rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $table ), $db );
        if ( count( $exists ) > 1 || ( $exists && ( $exists[0]['TABLE_NAME'] ?? '' ) !== $table ) ) { throw new RuntimeException(); }
        $result['legacy'] = $exists ? egov_law_monitor_withdrawal_check_rows( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT 100", array( $user_id ), $db ) : 'absent';
    } catch ( Throwable $error ) { $result['legacy'] = null; }
    return $result;
}

function egov_law_monitor_render_withdrawal_check() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'このページにアクセスする権限がありません。' ); }
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' || ! empty( $_POST ) ) { wp_die( '表示は閲覧専用です。POSTは出力前の専用処理が必要です。' ); }
    $raw = (string) egov_law_monitor_withdrawal_page_user_id();
    egov_law_monitor_render_withdrawal_notice( (int) $raw );
    $user = get_userdata( (int) $raw );
    $saved_progress = null;
    try { $saved_progress = egov_law_monitor_withdrawal_state( (int) $raw ); } catch ( Throwable $ignored ) {}
    if ( $saved_progress && ( ! $user || in_array( $saved_progress['phase'], array( 'deleting', 'completed' ), true ) ) ) { egov_law_monitor_render_withdrawal_after_delete( (int) $raw, $saved_progress ); return; }
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
    $progress = null; $progress_error = false;
    try { $progress = egov_law_monitor_withdrawal_state( (int) $raw ); }
    catch ( Throwable $error ) { $progress_error = true; }
    $errors = in_array( null, $data, true ) || $progress_error;
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
    echo '<div class="wrap"><h1>退会管理</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=egov-law-monitor' ) ) . '">ユーザー管理へ戻る</a></p><div class="notice ' . ( $errors ? 'notice-error' : 'notice-warning' ) . ' inline"><p><strong>' . ( $errors ? '読み取りエラー・確認できない情報あり' : ( $attention ? '確認事項あり' : '契約等の確認対象記録なし' ) ) . '</strong></p></div><p>退会前チェックはWordPress側の保存情報です。Square側の契約・課金状態を保証するものではありません。</p><p>本人確認・Square Dashboardでの課金停止と利用終了の確認は別途必要です。この画面は退会可能と判定せず、削除・プラン変更を行いません。</p><p>投稿用アカウントの実際のSecrets設定はWordPressから確認できません。一般会員として表示されてもサービス用でないことを管理者が確認してください。</p>';
    $labels = array( 'started' => '処理開始', 'cleaned' => '個人データ清掃済み（旧段階・個別結果は未確認）', 'deleting' => 'WordPressユーザー削除段階（削除済みとは断定しません）', 'completed' => '完了（保存記録）' );
    echo '<p><strong>退会状態：' . esc_html( $progress_error ? '読み取りエラー：退会状態を確認できません' : ( $progress === null ? '未開始' : $labels[$progress['phase']] ) ) . '</strong></p>';
    if ( $progress !== null ) {
        egov_law_monitor_render_retained_rows( array( $progress ), array( 'process_uuid' => '処理UUID', 'revision' => 'revision', 'created_at' => '作成日時（UTC）', 'updated_at' => '更新日時（UTC）', 'completed_at' => '完了日時（UTC・保存値）' ) );
    }
    echo '<p>各段階は個別の手動操作です。手順8で残存・保持情報を再確認してから明示的に退会完了を記録します。自動連続実行は行いません。</p>';
    // GET offers only operations supported by the freshly read state.
    $can_start = ! $errors && $progress === null;
    if ( $can_start && function_exists( 'egov_law_monitor_withdrawal_request_start_check' ) ) {
        $ready = egov_law_monitor_withdrawal_request_start_check( (int) $raw );
        if ( is_wp_error( $ready ) ) { $can_start = false; echo '<p>開始条件：' . esc_html( $ready->get_error_message() ) . '</p>'; }
    }
    try { egov_law_monitor_withdrawal_start_evidence( $data ); } catch ( Throwable $error ) { $can_start = false; echo '<p>開始条件：' . esc_html( $error->getMessage() ) . '</p>'; }
    if ( $can_start ) { echo '<form method="post">'; wp_nonce_field( 'egov_withdrawal_start_' . $raw, 'withdrawal_nonce' ); echo '<input type="hidden" name="withdrawal_action" value="start">'; }
    echo '<section><h2>1. 退会申請・本人確認</h2><p>問い合わせ窓口の退会申請と本人の意思を確認してください。サービス用アカウントでないことも確認してください。</p></section>';
    if ( function_exists( 'egov_law_monitor_render_withdrawal_request_receipt' ) ) { egov_law_monitor_render_withdrawal_request_receipt( (int) $raw ); }
    if ( $can_start ) { egov_law_monitor_render_start_checks( array( 'request', 'identity' ) ); }
    echo '<section><h2>2. Square契約終了・課金停止・利用終了日の確認</h2><p>Free・Standardの両方でSquare Dashboardの契約・課金停止と支払済み期間の終了を確認してください。必要なFree変更は既存のSquare契約管理で手動実施してください。保存情報だけではSquare側の終了を証明できません。</p><p><a href="' . esc_url( admin_url( 'admin.php?page=egov-square-subscriptions&user_id=' . $raw ) ) . '">Square契約管理を確認</a></p></section>';
    if ( $can_start ) {
        egov_law_monitor_render_start_checks( array( 'square', 'cancelled', 'period' ) );
        echo '<p>管理者の確認記録であり、Squareの実際の状態を技術的に証明しません。</p><p><label>確認根拠（Subscription ID・Dashboard確認日時・利用終了日。不要な個人情報は記載しない）<br><textarea name="withdrawal_reason" class="large-text" required maxlength="3000" rows="3"></textarea></label></p>';
    }
    echo '<section><h2>3. 退会前チェック</h2><p>以下は現在の保存情報です。再確認する場合はこの画面を再読み込みしてください。契約・監査・同意記録の保持と所有データの扱いを確認してください。</p>';
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
    echo '<p>投稿・メディアは削除時の移管先や投稿タイプ設定により扱いが異なります。本人のコメントはユーザー削除だけで全削除されるとは限りません。所有投稿の削除に伴って消えるコメントもあります。UsersWPのプロフィール・画像や他プラグインの情報は別途確認してください。</p></section>';
    echo '<section><h2>4. 退会処理開始</h2>';
    if ( $can_start ) { echo '<p>開始後は通常保存を停止します。開始取消機能はありません。必要な通知は先に完了してください。</p><button type="submit" class="button button-primary">退会処理を開始</button></section></form>'; }
    else { echo '<p>' . esc_html( $progress !== null ? '開始済みのため再実行できません。' : '開始条件を満たしていない、または状態を確認できないため開始できません。' ) . '</p></section>'; }
    egov_law_monitor_render_withdrawal_watch_step( (int) $raw, $progress, $progress_error, null );
    egov_law_monitor_render_withdrawal_ai_step( (int) $raw, $progress, $progress_error, null );
    egov_law_monitor_render_withdrawal_user_step( (int) $raw, $progress, $progress_error, null );
    egov_law_monitor_render_withdrawal_completion_step( (int) $raw, $progress, $progress_error, null );
    echo '</div>';
}

function egov_law_monitor_render_start_checks( $keys ) {
    $labels = egov_law_monitor_withdrawal_start_confirmations();
    foreach ( $keys as $key ) { echo '<p><label><input type="checkbox" required name="confirm_' . esc_html( $key ) . '" value="1"> ' . esc_html( $labels[$key] ) . '</label></p>'; }
}

function egov_law_monitor_render_withdrawal_watch_step( $user_id, $state, $error, $result ) {
    echo '<section><h2>5. 法令ウォッチの削除</h2>';
    if ( $error || ! $state || $state['phase'] !== 'started' ) { echo '<p>退会状態がstartedであることを確認できる場合のみ実行できます。</p></section>'; return; }
    try {
        $snapshot = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state );
        echo '<p>ウォッチ登録（有効・停止中の合計）：' . esc_html( $snapshot['counts']['watches'] ) . '件 ／ 通知設定メタ：' . esc_html( $snapshot['counts']['notifications'] ) . '件</p>';
        if ( $snapshot['recorded'] ) { echo '<p>' . ( $snapshot['counts'] === array( 'watches' => 0, 'notifications' => 0 ) ? '法令ウォッチ削除済み。現在の残存確認：0件。' : '削除記録がありますが残存データがあります。件数を確認して再削除してください。' ) . '</p>'; }
        if ( is_wp_error( $result ) ) { echo '<p>再読み込みして状態・件数・監査を確認してください。</p></section>'; return; }
        if ( $snapshot['recorded'] && $snapshot['counts'] === array( 'watches' => 0, 'notifications' => 0 ) ) { echo '</section>'; return; }
        echo '<p>通知設定も削除します。未設定時の表示はONに戻りますが、ウォッチ0件では通知対象はありません。処理中の通知は既に取得した情報で送られる場合があります。</p><form method="post">';
        wp_nonce_field( 'egov_withdrawal_watches_' . $user_id, 'watch_nonce' );
        echo '<input type="hidden" name="withdrawal_action" value="watches"><input type="hidden" name="watch_token" value="' . esc_html( egov_law_monitor_withdrawal_watch_token( $user_id, $state, $snapshot ) ) . '"><p><label><input type="checkbox" required name="confirm_watch_delete" value="1"> 対象件数を確認し、このユーザーのウォッチ全件・通知設定を削除します</label></p><button type="submit" class="button">法令ウォッチを削除</button></form>';
    } catch ( Throwable $ignored ) { echo '<p>読み取りエラー：削除対象・残存情報を確認できません。</p>'; }
    echo '</section>';
}

function egov_law_monitor_render_withdrawal_ai_step( $user_id, $state, $error, $result ) {
    echo '<section><h2>6. AI要約の個人履歴削除</h2>';
    if ( $error || ! $state || $state['phase'] !== 'started' ) { echo '<p>退会状態がstartedであることを確認できる場合のみ実行できます。</p></section>'; return; }
    try {
        $snapshot = egov_law_monitor_withdrawal_ai_snapshot( $user_id, $state );
        $watches = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state );
        echo '<p>AI個人履歴：' . esc_html( $snapshot['counts']['history'] ) . '件 ／ Free試用回数メタ：' . esc_html( $snapshot['counts']['trial'] ) . '件</p><p>対象ユーザーの個人履歴とFree試用回数のみ削除します。共有AIキャッシュ・Standard同意記録・契約・ユーザー本体は保持します。</p>';
        if ( $snapshot['recorded'] ) { echo '<p>' . ( $snapshot['counts'] === array( 'history' => 0, 'trial' => 0 ) ? 'AI個人履歴・Free試用回数は削除済み。現在の残存確認：0件。' : 'AI削除記録がありますが残存データがあります。手順5を確認して再削除してください。' ) . '</p>'; }
        if ( ! $watches['recorded'] || $watches['counts'] !== array( 'watches' => 0, 'notifications' => 0 ) ) { echo '<p>ウォッチ削除が未完了、または残存があります。手順5から実行してください。</p></section>'; return; }
        if ( is_wp_error( $result ) ) { echo '<p>再読み込みして状態・件数・監査を確認してください。</p></section>'; return; }
        if ( $snapshot['recorded'] && $snapshot['counts'] === array( 'history' => 0, 'trial' => 0 ) ) { echo '</section>'; return; }
        echo '<form method="post">'; wp_nonce_field( 'egov_withdrawal_ai_' . $user_id, 'ai_nonce' );
        echo '<input type="hidden" name="withdrawal_action" value="ai"><input type="hidden" name="ai_token" value="' . esc_html( egov_law_monitor_withdrawal_ai_token( $user_id, $state, $snapshot ) ) . '"><p><label><input type="checkbox" required name="confirm_ai_delete" value="1"> 対象件数を確認し、AI個人履歴とFree試用回数を削除します</label></p><button type="submit" class="button">AI要約の個人履歴を削除</button></form>';
    } catch ( Throwable $ignored ) { echo '<p>読み取りエラー：AI履歴・利用回数・先行段階を確認できません。</p>'; }
    echo '</section>';
}

function egov_law_monitor_render_withdrawal_user_step( $user_id, $state, $error, $result ) {
    echo '<section><h2>7. WordPressユーザー削除</h2>';
    if ( $error || ! $state || $state['phase'] !== 'started' || ! current_user_can( 'delete_users' ) || is_wp_error( $result ) ) { echo '<p>開始済みの状態・削除権限と先行段階を確認してください。</p></section>'; return; }
    try {
        $evidence = egov_law_monitor_withdrawal_user_evidence( $user_id, $state );
        if ( ! egov_law_monitor_withdrawal_user_exists( $user_id ) ) { throw new RuntimeException(); }
        echo '<p>確認結果：ウォッチ・AI個人履歴・試用回数の残存0件、同じ退会処理の削除監査あり、Free変更済み、Square関連の保存情報に未解決事項なし、所有投稿・メディア・コメント・リンクなし。</p><p>Standard同意記録は削除前監査へ保持します。契約・監査・共有AIキャッシュは削除しません。WordPress標準削除と他プラグインの削除フックが実行されます。自動的に退会完了にはしません。</p><form method="post">';
        wp_nonce_field( 'egov_withdrawal_user_' . $user_id, 'user_nonce' );
        echo '<input type="hidden" name="withdrawal_action" value="user"><input type="hidden" name="user_token" value="' . esc_html( egov_law_monitor_withdrawal_user_token( $user_id, $state, $evidence ) ) . '"><p><label><input type="checkbox" required name="confirm_user_delete" value="1"> 対象ユーザーID ' . esc_html( $user_id ) . ' の確認結果と同意記録の保持を確認し、WordPressユーザーを削除します（元に戻せません）</label></p><button type="submit" class="button">WordPressユーザーを削除</button></form>';
    } catch ( Throwable $error ) { echo '<p>削除条件を満たしていません：' . esc_html( $error instanceof EGOV_Law_Monitor_Withdrawal_User_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Start_Error || $error instanceof EGOV_Law_Monitor_Withdrawal_Retention_Error ? $error->getMessage() : '読み取り・先行段階を確認できません。' ) . '</p>'; }
    echo '</section>';
}

/** Same route, including after wp_delete_user removed all usermeta. No replay or completion. */
function egov_law_monitor_render_withdrawal_after_delete( $user_id, $state, $result = null, $completion_result = null, $recovery_result = null ) {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( '権限がありません。' ); }
    $labels = array( 'started' => '処理開始（ユーザー状態の確認が必要）', 'cleaned' => '個人データ清掃済み（旧段階・個別結果は未確認）', 'deleting' => 'WordPressユーザー削除段階（削除済みとは断定しません）', 'completed' => '完了（保存記録）' );
    echo '<div class="wrap"><h1>退会管理</h1><p>対象ユーザーID：' . esc_html( $user_id ) . '</p><p>退会状態：' . esc_html( $labels[$state['phase']] ) . '</p>';
    if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; }
    elseif ( $result !== null ) { echo '<p>WordPressユーザーの削除を確認し、削除結果を監査へ記録しました。退会完了ではありません。</p>'; }
    echo '<h2>1. 退会申請・本人確認</h2><p>開始時の確認は保持された監査記録を参照してください。</p>';
    if ( function_exists( 'egov_law_monitor_render_withdrawal_request_receipt' ) ) { egov_law_monitor_render_withdrawal_request_receipt( $user_id ); }
    echo '<h2>2. Square契約終了・課金停止・利用終了日の確認</h2><p>Square側の状態は自動照会しません。契約・解約履歴を保持します。</p><h2>3. 退会前チェック</h2>';
    try {
        $exists = egov_law_monitor_withdrawal_user_exists( $user_id );
        echo '<p>' . ( $exists ? 'WordPressユーザーが存在します。削除失敗・中断・実行中の可能性があります。再削除はできません。監査と元の処理を確認してください。' : 'WordPressユーザー不存在を読み取りで確認しました。削除済みユーザーとして同じ処理を表示しています。' ) . '</p>';
        $data = egov_law_monitor_withdrawal_check_data( $user_id );
        if ( in_array( null, $data, true ) ) { throw new RuntimeException(); }
        foreach ( array( 'contracts' => array( 'id' => '契約ID', 'square_subscription_id' => 'Subscription ID' ), 'cancellations' => array( 'id' => '解約申請ID', 'end_date' => '利用終了日', 'completed_at' => '完了記録' ) ) as $key => $columns ) { egov_law_monitor_render_retained_rows( $data[$key], $columns ); }
        echo '<h3>所有データの残存</h3>';
        egov_law_monitor_render_retained_rows( $data['posts'], array( 'post_type' => '投稿・メディアの種類', 'total' => '残存件数' ) );
        egov_law_monitor_render_retained_rows( $data['comments'], array( 'total' => '本人コメントの残存件数' ) );
        egov_law_monitor_render_retained_rows( $data['links'], array( 'total' => '所有リンクの残存件数' ) );
        global $wpdb;
        $meta_counts = egov_law_monitor_withdrawal_rows( "SELECT COUNT(*) AS total FROM `{$wpdb->usermeta}` WHERE user_id = ?", array( $user_id ) );
        $total = $meta_counts[0]['total'] ?? null;
        if ( count( $meta_counts ) !== 1 || ! ( is_int( $total ) || is_string( $total ) ) || ! ctype_digit( (string) $total ) ) { throw new RuntimeException(); }
        echo '<p>全ユーザーメタ残存件数：' . esc_html( $total ) . '</p>';
    } catch ( Throwable $ignored ) { echo '<p>読み取りエラー：存在・契約・残存情報を確認できません。問題なしとは判断しないでください。</p>'; }
    echo '<h2>4. 退会処理開始</h2><p>開始済みです。開始を再実行しません。</p><h2>5. 法令ウォッチの削除</h2>';
    try { $watch = egov_law_monitor_withdrawal_watch_snapshot( $user_id, $state ); echo '<p>現在の残存ウォッチ：' . esc_html( $watch['counts']['watches'] ) . '件 ／ 通知設定：' . esc_html( $watch['counts']['notifications'] ) . '件</p>'; } catch ( Throwable $ignored ) { echo '<p>読み取りエラー</p>'; }
    echo '<h2>6. AI要約の個人履歴削除</h2>';
    try { $ai = egov_law_monitor_withdrawal_ai_snapshot( $user_id, $state ); echo '<p>現在の残存AI履歴：' . esc_html( $ai['counts']['history'] ) . '件 ／ 試用回数メタ：' . esc_html( $ai['counts']['trial'] ) . '件</p>'; } catch ( Throwable $ignored ) { echo '<p>読み取りエラー</p>'; }
    echo '<h2>7. WordPressユーザー削除</h2><p>deleting移行後は二重削除を禁止します。失敗・中断時は元の処理が終了したことと標準削除フックの結果を確認してください。時間経過だけで再実行しません。</p>';
    if ( $state['phase'] === 'deleting' && ! is_wp_error( $result ) && current_user_can( 'delete_users' ) ) {
        try {
            $evidence = egov_law_monitor_withdrawal_user_recovery_evidence( $user_id, $state );
            echo '<p>WordPress削除がfalseを返して終了した記録と、削除前条件の一致を確認しました。再準備だけを行い、実際の削除は別のボタン操作で行います。</p><form method="post">';
            wp_nonce_field( 'egov_withdrawal_rearm_' . $user_id, 'rearm_nonce' );
            echo '<input type="hidden" name="withdrawal_action" value="user_rearm"><input type="hidden" name="rearm_token" value="' . esc_html( egov_law_monitor_withdrawal_user_token( $user_id, $state, $evidence ) ) . '"><p><label><input type="checkbox" required name="confirm_rearm" value="1"> 失敗原因を確認し、削除を再準備します</label></p><button class="button" type="submit">削除失敗を確認して再準備</button></form>';
        } catch ( Throwable $ignored ) { echo '<p>実行中・結果不明・例外終了・条件変更の場合は再準備できません。保持監査と標準削除フックの結果を調査してください。</p>'; }
    }
    try {
        $archive = egov_law_monitor_withdrawal_user_archive( $user_id, $state );
        echo '<h3>保持したStandard同意記録</h3>';
        if ( $archive['evidence']['consent'] === null ) { echo '<p>削除前の同意記録なし（保存値）。推測して補完しません。</p>'; }
        else { egov_law_monitor_render_retained_rows( array( $archive['evidence']['consent'] ), array( 'agreed_at' => '同意日時（UTC）', 'terms_version' => '規約バージョン', 'conditions_version' => '旧契約条件バージョン' ) ); }
    } catch ( Throwable $ignored ) { echo '<p>削除前の保持監査を確認できません。監査履歴の確認が必要です。</p>'; }
    echo '<p>' . esc_html( egov_law_monitor_withdrawal_delete_status( $user_id, $state ) ) . '</p>';
    if ( $recovery_result !== null ) { echo '<p>' . esc_html( is_wp_error( $recovery_result ) ? $recovery_result->get_error_message() : '再清掃と監査保存を確定しました。残存データを再確認してください。退会完了は別操作です。' ) . '</p>'; }
    if ( $state['phase'] === 'deleting' && current_user_can( 'delete_users' ) ) {
        try {
            $recovery = egov_law_monitor_withdrawal_recovery_snapshot( $user_id, $state );
            if ( ! $recovery['ok'] ) {
                echo '<h3>削除後の残存データ再清掃</h3><p>対象はこのユーザーIDの法令ウォッチ・通知設定・AI個人履歴・Free試用回数のみです。契約・同意・監査・共有キャッシュは保持します。</p><form method="post">';
                wp_nonce_field( 'egov_withdrawal_recovery_' . $user_id, 'recovery_nonce' );
                echo '<input type="hidden" name="withdrawal_action" value="reclean"><input type="hidden" name="recovery_token" value="' . esc_html( egov_law_monitor_withdrawal_completion_token( $user_id, $state, $recovery ) ) . '"><label><input required type="checkbox" name="confirm_recovery" value="1"> 上記の残存件数と削除範囲を確認しました</label><p><button class="button" type="submit">削除後の残存データを再清掃</button></p></form>';
            }
        } catch ( Throwable $error ) { echo '<p>再清掃不可：' . esc_html( $error instanceof EGOV_Law_Monitor_Withdrawal_User_Error ? $error->getMessage() : '監査・保持情報を確認できません。手動調査が必要です。' ) . '</p>'; }
    }
    egov_law_monitor_render_withdrawal_completion_step( $user_id, $state, false, $completion_result );
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=egov-square-subscriptions&user_id=' . $user_id ) ) . '">契約・監査履歴を確認</a></p></div>';
}

function egov_law_monitor_render_withdrawal_completion_step( $user_id, $state, $error, $result = null ) {
    echo '<section><h2>8. 残存データ確認・退会完了</h2><p>完了処理は追加のデータ削除を行いません。</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=egov-user-withdrawal-check&user_id=' . $user_id ) ) . '">残存データを再確認</a></p>';
    if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; }
    elseif ( $result === true ) { echo '<div class="notice notice-success"><p>退会完了を記録しました。</p></div>'; }
    if ( $error || ! $state || ! in_array( $state['phase'], array( 'deleting', 'completed' ), true ) ) { echo '<p>先行段階とWordPressユーザー削除を完了してから利用できます。状態の読み取り失敗時は操作できません。</p></section>'; return; }
    try {
        $report = egov_law_monitor_withdrawal_completion_snapshot( $user_id, $state );
        echo '<h3>削除対象の現在の残存</h3>';
        $rows = array(); foreach ( $report['counts'] as $label => $count ) { $rows[] = array( 'item' => $label, 'result' => $count === null ? '読み取りエラー' : $count . '件' ); }
        egov_law_monitor_render_retained_rows( $rows, array( 'item' => '削除対象', 'result' => '残存状況' ) );
        echo '<h3>保持対象の確認</h3>';
        $rows = array(); foreach ( $report['retention'] as $label => $value ) { $rows[] = array( 'item' => $label, 'result' => $value ); }
        if ( $rows ) { egov_law_monitor_render_retained_rows( $rows, array( 'item' => '保持対象', 'result' => '確認状況' ) ); }
        else { echo '<p>保持情報を確認できません。下の監査・契約の検証エラーを確認してください。</p>'; }
        foreach ( $report['errors'] as $message ) { echo '<p><strong>完了不可・要確認：</strong>' . esc_html( $message ) . '</p>'; }
        if ( $state['phase'] === 'completed' ) {
            $done = $report['completed'];
            echo '<p>完了済みです。再実行できません。完了日時（UTC）：' . esc_html( $state['completed_at'] ?? '確認できません' ) . ' ／ 操作管理者ID：' . esc_html( $done['admin_id'] ?? '監査を確認できません' ) . ' ／ 完了監査ID：' . esc_html( $state['last_audit_id'] ?? '確認できません' ) . '</p>';
        } elseif ( $report['ok'] && ! is_wp_error( $result ) ) {
            echo '<p>WordPressユーザー不存在と、削除成功監査・保持記録を確認しました。</p><form method="post">'; wp_nonce_field( 'egov_withdrawal_complete_' . $user_id, 'complete_nonce' );
            echo '<input type="hidden" name="withdrawal_action" value="complete"><input type="hidden" name="complete_token" value="' . esc_html( egov_law_monitor_withdrawal_completion_token( $user_id, $state, $report ) ) . '"><p><label><input type="checkbox" required name="confirm_complete" value="1"> 残存0件、必要な監査・契約・同意記録の保持とユーザー削除結果を確認し、退会完了を記録します</label></p><button type="submit" class="button button-primary">退会完了</button></form>';
        } else { echo '<p>再読み込みして確認してください。先行削除を利用できる状態なら手順5・6へ戻り、それ以外の残存・欠落・削除結果不明は保持記録と元の処理を調査してください。完了処理から削除は実行しません。</p>'; }
    } catch ( Throwable $ignored ) { echo '<p>読み取りエラー：残存・保持情報を確認できません。完了できません。</p>'; }
    echo '</section>';
}
