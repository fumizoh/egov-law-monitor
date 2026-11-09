<?php
/** Separate application page and read-only mypage plan status. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only snapshot shared by the application and plan-status shortcodes. */
function egov_law_monitor_checkout_view( $user_id ) {
    $plan = egov_law_monitor_get_plan( $user_id );
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_square_checkout_attempts WHERE user_id = %d AND state <> 'closed' ORDER BY id DESC LIMIT 2", $user_id ), ARRAY_A );
    $read_error = ! is_array( $rows ) || $wpdb->last_error !== '';
    // Display needs only the selected environment, not a token or issuance settings.
    $environment = defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) ? EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT : null;
    $display_error = '';
    $attempt = null;
    if ( $read_error ) { $display_error = '申込情報を取得できません。再発行せず管理者へお問い合わせください。'; }
    elseif ( $rows ) {
        if ( count( $rows ) !== 1 || (int) $rows[0]['user_id'] !== $user_id ) { $display_error = '申込情報の件数・所有者を確認できません。管理者へお問い合わせください。'; }
        elseif ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) || $rows[0]['environment'] !== $environment ) { $display_error = '申込の保存環境と現在の環境を確認できません。管理者へお問い合わせください。'; }
        else {
            $attempt = egov_law_monitor_checkout_public_result( $rows[0] );
            if ( is_wp_error( $attempt ) ) { $display_error = '保存済みの決済リンクまたは申込情報が欠落・不正です。再発行せず管理者へお問い合わせください。'; }
        }
    }
    $blocked = $read_error || ( $rows && ( count( $rows ) !== 1 || $attempt === null || is_wp_error( $attempt ) ) );
    // Avoid offering a new application even before POST when historical contracts exist.
    $contracts = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}egov_square_subscriptions WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );
    $contract_error = ! is_array( $contracts ) || $wpdb->last_error !== '';
    if ( $display_error === '' && $contract_error ) { $display_error = '契約情報を取得できないため、決済リンクを表示できません。管理者の確認が必要です。'; }
    elseif ( $display_error === '' && ! empty( $contracts ) ) { $display_error = '登録済みのSquare契約があるため、重複決済防止のため決済リンクを表示しません。管理者へお問い合わせください。'; }
    $blocked = $blocked || $contract_error || ! empty( $contracts );
    return compact( 'plan', 'rows', 'attempt', 'blocked', 'display_error' );
}

/** Process once before template output; injectable session/redirect are offline test seams. */
function egov_law_monitor_checkout_cancel_post( $factory = null, $redirect = null ) {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['egov_checkout_cancel_request'] ) ) { return null; }
    if ( isset( $GLOBALS['egov_checkout_cancel_post'] ) ) { return $GLOBALS['egov_checkout_cancel_post']['result']; }
    $GLOBALS['egov_checkout_cancel_post'] = array( 'result' => null );
    $target = egov_law_monitor_standard_application_page_url();
    if ( isset( $_POST['egov_square_checkout'] ) ) { $result = new WP_Error( 'checkout_mixed_action', '申込とキャンセル申請は別々に操作してください。' ); }
    elseif ( $target === '' || ( $redirect === null && headers_sent() ) ) { $result = new WP_Error( 'cancel_redirect_unavailable', '申請画面への移動を準備できません。画面を開き直してください。' ); }
    else {
        $fields = array();
        foreach ( array( 'checkout_cancel_id', 'checkout_cancel_nonce', 'checkout_cancel_token', 'checkout_cancel_confirmed' ) as $field ) { $fields[$field] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : ''; }
        $result = egov_law_monitor_request_checkout_cancel( $fields, $factory );
    }
    $GLOBALS['egov_checkout_cancel_post']['result'] = $result;
    if ( is_wp_error( $result ) ) { return $result; }
    nocache_headers();
    if ( $redirect !== null ) { return $redirect( $target, 303 ); }
    if ( wp_safe_redirect( $target, 303 ) ) { exit; }
    $result = new WP_Error( 'cancel_redirect_failed', 'キャンセル申請は受け付けましたが、画面を移動できませんでした。再送信せず、申込ページを開き直してください。' );
    $GLOBALS['egov_checkout_cancel_post']['result'] = $result;
    return $result;
}
add_action( 'template_redirect', 'egov_law_monitor_checkout_cancel_post', 10, 0 );

function egov_law_monitor_standard_application_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>Standardの申込にはログインが必要です。</p>'; }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }
    $user_id = (int) get_current_user_id();
    $cancel_result = $GLOBALS['egov_checkout_cancel_post']['result'] ?? null;
    $mixed = isset( $_POST['egov_square_checkout'], $_POST['egov_checkout_cancel_request'] );
    $settings = egov_law_monitor_checkout_settings();
    $result = null;
    if ( $mixed ) { if ( $cancel_result === null ) { $result = new WP_Error( 'checkout_mixed_action', '申込とキャンセル申請は別々に操作してください。' ); } }
    elseif ( isset( $_POST['egov_square_checkout'] ) ) {
        $nonce = isset( $_POST['egov_checkout_nonce'] ) && is_string( $_POST['egov_checkout_nonce'] ) ? wp_unslash( $_POST['egov_checkout_nonce'] ) : '';
        $uuid = isset( $_POST['application_uuid'] ) && is_string( $_POST['application_uuid'] ) ? wp_unslash( $_POST['application_uuid'] ) : '';
        $result = egov_law_monitor_submit_checkout( $nonce, $uuid );
    }
    $view = egov_law_monitor_checkout_view( $user_id );
    $free = $view['plan'] === 'free';
    $rows = $view['rows']; $attempt = $view['attempt'];
    $blocked = $view['blocked']; $display_error = $view['display_error'];
    ob_start();
    ?>
    <section class="egov-standard-application">
        <h2>Standardプラン（月額500円・税込）</h2>
        <?php if ( $free && ! $blocked && $attempt === null && ! is_wp_error( $settings ) ) : ?>
        <p>Standardプランをご利用いただくには、下のボタンからお申し込みください。</p>
        <p>お申し込み後、Squareの決済ページでお支払いいただけます。</p>
        <p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>
        <?php elseif ( is_array( $attempt ) && $attempt['state'] === 'ready' ) : ?>
        <p>下のボタンからSquareでお支払いをお願いします。</p>
        <p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>
        <p>切り替えが完了するまで、しばらくお待ちください。</p>
        <?php elseif ( ! ( is_array( $attempt ) && $attempt['state'] === 'cancel_requested' ) ) : ?>
        <p>Standardプランのお支払いは、Squareの決済ページで行います。</p>
        <p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。切り替えが完了するまで、しばらくお待ちください。</p>
        <?php endif; ?>
        <?php if ( is_wp_error( $result ) ) : ?><p><?php echo esc_html( $result->get_error_message() ); ?></p><?php endif; ?>
        <?php if ( is_wp_error( $cancel_result ) ) : ?><p><?php echo esc_html( $cancel_result->get_error_message() ); ?></p><?php endif; ?>
        <?php if ( $display_error !== '' ) : ?><p><?php echo esc_html( $display_error ); ?></p><?php endif; ?>
        <?php if ( is_array( $attempt ) && $attempt['state'] === 'cancel_requested' ) : ?>
            <div class="egov-standard-cancel-pending">
                <h3><svg class="egov-standard-pending-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>キャンセル手続き中</h3>
                <p>お申し込みのキャンセルを受け付けました。</p>
                <p>管理者がキャンセルの手続きを行います。手続き完了まで、しばらくお待ちください。</p>
                <p>キャンセル完了後は、改めてお申し込みいただけます。</p>
            </div>
        <?php elseif ( $blocked && ! ( is_array( $attempt ) && $attempt['state'] === 'ready' ) ) : ?>
            <p>既存契約または申込情報の確認が必要です。新しい申込を行わず管理者へお問い合わせください。</p>
        <?php elseif ( is_array( $attempt ) && $attempt['state'] === 'ready' ) : ?>
            <p>申込ID：<?php echo esc_html( $attempt['application_uuid'] ); ?></p>
            <?php if ( $free && ! $blocked ) : ?><p><a class="egov-law-button egov-standard-payment-button" href="<?php echo esc_url( $attempt['checkout_url'] ); ?>" rel="noreferrer">Squareでお支払いへ進む</a></p>
            <?php elseif ( ! $free ) : ?><p>現在Freeプランではないため、決済リンクを表示しません。</p><?php endif; ?>
            <p>すでにお支払い済みの場合は、再度決済せず、プランの切り替えをお待ちください。</p>
            <details><summary>申込をキャンセルする</summary>
                <p>お申し込みを取りやめる場合は、下のリンクからキャンセルを申請してください。</p>
                <p>管理者がキャンセルの手続きを行います。手続き完了まで、しばらくお待ちください。</p>
                <p>キャンセル完了後は、改めてお申し込みいただけます。</p>
                <p>お支払い済みの場合は、お問い合わせください。</p>
                <form method="post" class="egov-standard-cancel-form">
                    <?php wp_nonce_field( 'egov_checkout_cancel_request_' . $user_id . '_' . $rows[0]['id'], 'checkout_cancel_nonce' ); ?>
                    <input type="hidden" name="checkout_cancel_id" value="<?php echo esc_attr( $rows[0]['id'] ); ?>">
                    <input type="hidden" name="checkout_cancel_token" value="<?php echo esc_attr( egov_law_monitor_square_record_token( $rows[0] ) ); ?>">
                    <input type="hidden" name="egov_checkout_cancel_request" value="1">
                    <input type="hidden" name="checkout_cancel_confirmed" value="">
                    <p><button class="egov-standard-cancel-link" type="button" data-cancel-open disabled>キャンセルを申請する</button></p>
                    <p data-cancel-unavailable>キャンセル申請にはJavaScriptと対応ブラウザが必要です。操作できない場合は管理者へお問い合わせください。</p>
                    <dialog aria-label="Standardプランの申込キャンセル確認">
                        <p>Standardプランの申し込みをキャンセルしますか？</p>
                        <p>キャンセルの手続きは管理者が行います。</p>
                        <div class="egov-standard-cancel-actions">
                            <button type="button" data-cancel-back autofocus>戻る</button>
                            <button type="button" data-cancel-confirm>キャンセルを申請する</button>
                        </div>
                    </dialog>
                </form>
            </details>
        <?php elseif ( is_array( $attempt ) && in_array( $attempt['state'], array( 'closing', 'canceling', 'cancel_unknown' ), true ) ) : ?>
            <p>申込キャンセルは処理中、または結果の確認が必要です。再申込せず、管理者へお問い合わせください。</p>
        <?php elseif ( ! $free || is_wp_error( $settings ) ) : ?>
            <p>Standardをご利用中、または申込受付を停止しています。管理者へお問い合わせください。</p>
        <?php else : ?>
            <?php if ( is_array( $attempt ) ) : ?><p>申込ID：<?php echo esc_html( $attempt['application_uuid'] ); ?>。発行中または結果未確認です。短時間の再確認は同じ申込内容で行い、新しい申込UUIDは発行しません。</p><?php endif; ?>
            <form method="post" action="<?php echo esc_url( $settings['return_url'] ); ?>">
                <?php wp_nonce_field( 'egov_square_checkout_' . $user_id, 'egov_checkout_nonce' ); ?>
                <input type="hidden" name="application_uuid" value="<?php echo esc_attr( is_array( $attempt ) ? $attempt['application_uuid'] : '' ); ?>">
                <button type="submit" name="egov_square_checkout"><?php echo esc_html( is_array( $attempt ) ? '同じ申込の発行結果を再確認する' : 'Standard 月額500円（税込）を申し込む' ); ?></button>
            </form>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'egov_standard_application', 'egov_law_monitor_standard_application_shortcode' );

/** Same-site destination independent of API credentials and the saved return URL. */
function egov_law_monitor_standard_application_page_url() {
    $url = home_url( '/standard-application/' );
    $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
    if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! $parts || ! $home ||
        ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || ( $parts['host'] ?? '' ) !== ( $home['host'] ?? '' ) ||
        ( $parts['port'] ?? 443 ) !== ( $home['port'] ?? 443 ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return ''; }
    return $url;
}

/** Read-only mypage panel. Deliberately does not inspect POST or API settings. */
function egov_law_monitor_plan_status_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>ご利用プランの確認にはログインが必要です。</p>'; }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }
    $view = egov_law_monitor_checkout_view( (int) get_current_user_id() );
    $plan = $view['plan']; $attempt = $view['attempt'];
    $known_plan = in_array( $plan, array( 'free', 'standard' ), true );
    $html = '<section class="egov-plan-status"><h2>💳 ご利用プラン</h2><p class="egov-plan-status-current"><span>現在のプラン：</span><strong class="egov-plan-status-badge egov-plan-status-badge--' . ( $known_plan ? $plan : 'unknown' ) . '">' . esc_html( $known_plan ? ( $plan === 'standard' ? 'Standard' : 'Free' ) : '確認が必要です' ) . '</strong></p>';
    $label = '';
    if ( ! $known_plan ) { $html .= '<p>プラン情報を確認できません。管理者へお問い合わせください。</p>'; }
    elseif ( $plan === 'standard' ) {
        $html .= '<p>Standardプランをご利用中です。Squareの支払状況はこの画面では確認できません。契約については管理者へお問い合わせください。</p>';
        if ( is_array( $attempt ) && $attempt['state'] === 'cancel_requested' ) { $html .= '<p>キャンセル手続き中です。</p>'; $label = '申込状況を確認する'; }
    } elseif ( $view['blocked'] ) { $html .= '<p>契約または申込情報の確認が必要です。新しく申し込まず管理者へお問い合わせください。</p>'; }
    elseif ( $attempt === null ) { $label = 'Standardプランに申し込む'; }
    elseif ( is_array( $attempt ) ) {
        $messages = array( 'ready' => 'お申し込みを受け付けました。お支払いの確認後、管理者がStandardプランへの切り替えを行います。',
            'creating' => '申込処理中です。新しい申込は行わず、詳細ページで同じ申込をご確認ください。',
            'unknown' => '申込結果の確認が必要です。新しい申込は行わず、詳細ページをご確認ください。',
            'cancel_requested' => 'キャンセル手続き中です。管理者の手続き完了までお待ちください。' );
        if ( $attempt['state'] === 'ready' ) {
            $html .= '<p>お申し込みを受け付けました。</p><p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>';
        } else {
            $html .= '<p>' . esc_html( $messages[$attempt['state']] ?? '未解決の申込があります。管理者へお問い合わせください。' ) . '</p>';
        }
        if ( isset( $messages[$attempt['state']] ) ) { $label = '申込状況を確認する'; }
    }
    if ( $label !== '' ) {
        $url = egov_law_monitor_standard_application_page_url();
        $html .= $url !== '' ? '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>' : '<p>申込ページを確認できません。管理者へお問い合わせください。</p>';
    }
    return $html . '</section>';
}
add_shortcode( 'egov_plan_status', 'egov_law_monitor_plan_status_shortcode' );

/** Admin-only history and explicit cancellation form. GET never performs HTTP. */
function egov_law_monitor_render_checkout_attempts( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) || ! get_userdata( $user_id ) ) { return; }
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}egov_square_checkout_attempts WHERE user_id = %d ORDER BY id DESC LIMIT 50", $user_id ), ARRAY_A );
    echo '<h2>Standard申込情報（最新50件）</h2><p>キャンセル申請だけではSquareのリンクは無効になりません。管理者がSquare側で対象リンクを確認・削除し、未決済・契約未成立で未解決事項がないと十分に確認できた場合だけ完了登録してください。確認不足なら申請中のまま保留してください。WordPressからSquareの変更・解約・課金停止は行いません。</p>';
    if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { echo '<p>申込情報を読み取れません。申込テーブルの更新状況を確認してください。</p>'; return; }
    if ( ! $rows ) { echo '<p>申込記録はありません。</p>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>申込UUID</th><th>環境</th><th>申込状態</th><th>Payment Link ID</th><th>注文テンプレートID</th><th>作成日時（UTC）</th><th>更新日時（UTC）</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        if ( (int) $row['user_id'] !== (int) $user_id ) { continue; }
        echo '<tr>';
        foreach ( array( 'application_uuid', 'environment', 'state', 'payment_link_id', 'template_order_id', 'created_at', 'updated_at' ) as $key ) { echo '<td>' . esc_html( $row[$key] ?? '未取得' ) . '</td>'; }
        echo '</tr>';
        if ( $row['environment'] === 'sandbox' ) {
            echo '<tr><td colspan="7"><a href="https://developer.squareup.com/apps" target="_blank" rel="noopener noreferrer">Square Developer Consoleを開く（Sandbox）</a>：対象テストアカウントのDashboardを開いてください。Sandbox DashboardはSubscriptionの閲覧に対応していません。APIで見つからない場合は手動照合・サポート確認が必要です。</td></tr>';
        } elseif ( $row['environment'] === 'production' ) {
            echo '<tr><td colspan="7"><a href="https://squareup.com/dashboard/" target="_blank" rel="noopener noreferrer">Square Dashboardを開く（本番）</a>：上記の申込UUID・Payment Link ID・注文テンプレートIDで照合してください。詳細画面URLの自動生成は行いません。</td></tr>';
        }
        if ( $row['state'] === 'ready' && egov_law_monitor_square_api_id_valid( $row['payment_link_id'] ?? null ) && egov_law_monitor_square_api_id_valid( $row['template_order_id'] ?? null ) ) {
            echo '<tr><td colspan="7"><form method="post">';
            wp_nonce_field( 'egov_checkout_contract_lookup_' . $user_id . '_' . $row['id'], 'attempt_nonce' );
            echo '<input type="hidden" name="attempt_id" value="' . esc_attr( $row['id'] ) . '"><input type="hidden" name="attempt_token" value="' . esc_attr( egov_law_monitor_square_record_token( $row ) ) . '"><button class="button" type="submit" name="egov_checkout_contract_lookup">Square契約を照会</button></form></td></tr>';
        }
        if ( in_array( $row['state'], array( 'closing', 'canceling', 'cancel_unknown' ), true ) ) {
            echo '<tr><td colspan="7">キャンセル処理中・結果未確認：新規申込は停止中です。監査履歴とSquare側の決済・契約を確認してください。自動再試行は行いません。</td></tr>';
        }
        if ( $row['state'] === 'closed' ) { echo '<tr><td colspan="7">管理者によるキャンセル完了登録済み（他に未解決申込・既存契約がなければ再申込可能）</td></tr>'; }
        if ( $row['state'] === 'cancel_requested' && egov_law_monitor_square_api_id_valid( $row['payment_link_id'] ?? null ) && egov_law_monitor_square_api_id_valid( $row['template_order_id'] ?? null ) ) {
            echo '<tr><td colspan="7"><form method="post">';
            wp_nonce_field( 'egov_checkout_cancel_complete_' . $user_id . '_' . $row['id'], 'checkout_cancel_nonce' );
            echo '<input type="hidden" name="checkout_cancel_id" value="' . esc_attr( $row['id'] ) . '"><input type="hidden" name="checkout_cancel_token" value="' . esc_attr( egov_law_monitor_square_record_token( $row ) ) . '">';
            echo '<p>チェックはSquare側の状態を技術的に証明するものではありません。確認日時は登録時のUTC日時として監査履歴へ記録します。</p>';
            foreach ( array( 'link_deleted' => '対象リンクの削除を確認した', 'no_payment_subscription' => '関連する決済・Subscriptionを確認し、未決済・契約未成立であると確認した', 'no_unresolved_issue' => '未解決事項がないことを確認した' ) as $name => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" required>' . esc_html( $label ) . '</label></p>'; }
            echo '<label>処理理由・備考 <textarea name="checkout_cancel_reason" required maxlength="2000"></textarea></label> <button class="button" name="egov_checkout_cancel" type="submit">キャンセル完了</button></form></td></tr>';
        }
    }
    echo '</tbody></table>';
}
