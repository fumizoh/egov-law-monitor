<?php
/** Fixed Dashboard checkout, consent and plan guidance. No Square API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Update these versions together with the published documents/conditions.
const EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION = '2026-11-14';
const EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION = '2026-11-14';

function egov_law_monitor_standard_payment_url() {
    return 'https://square.link/u/30s16R3f';
}

/** Process once before output; the only permitted external target is the fixed checkout. */
function egov_law_monitor_standard_consent_post( $redirect = null ) {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['egov_standard_consent'] ) ) { return; }
    if ( isset( $GLOBALS['egov_standard_consent_result'] ) ) { return $GLOBALS['egov_standard_consent_result']; }
    $GLOBALS['egov_standard_consent_result'] = new WP_Error( 'consent_processing', '処理中です。再送信せず画面を開き直してください。' );
    $fail = static function ( $code, $message ) { return $GLOBALS['egov_standard_consent_result'] = new WP_Error( $code, $message ); };
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 ) { return $fail( 'consent_login', 'ログインしてからお申し込みください。' ); }
    $nonce = $_POST['standard_consent_nonce'] ?? null;
    if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'egov_standard_consent_' . $user_id . '_' . EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION . '_' . EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION ) ) { return $fail( 'consent_nonce', '画面の有効期限が切れたか確認できません。画面を開き直してください。' ); }
    if ( ( $_POST['standard_agreed'] ?? null ) !== '1' ) { return $fail( 'consent_required', '利用規約、プライバシーポリシーおよび契約条件への同意が必要です。' ); }
    $view = egov_law_monitor_standard_plan_view( $user_id );
    if ( ! $view['can_pay'] ) { return $fail( 'consent_unavailable', $view['message'] ); }
    $url = egov_law_monitor_standard_payment_url();
    if ( $url !== 'https://square.link/u/30s16R3f' || ( $redirect === null && headers_sent() ) ) { return $fail( 'consent_destination', '決済画面へ移動できません。管理者へお問い合わせください。' ); }
    $record = array( 'agreed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'terms_version' => EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION, 'conditions_version' => EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION );
    $key = 'egov_law_monitor_standard_consent';
    // update_user_meta returns false for identical data as well as write errors.
    $saved = update_user_meta( $user_id, $key, $record );
    if ( $saved === false && get_user_meta( $user_id, $key, true ) !== $record ) { return $fail( 'consent_save', '同意情報を保存できませんでした。時間を置いて再操作してください。' ); }
    nocache_headers();
    if ( $redirect ) { return $GLOBALS['egov_standard_consent_result'] = $redirect( $url, 303 ); }
    // wp_safe_redirect would reject Square by default. Use wp_redirect only with this
    // server-owned exact URL; never widen allowed_redirect_hosts or accept a POST URL.
    if ( wp_redirect( $url, 303, 'e-Gov Law Monitor' ) ) { exit; }
    return $fail( 'consent_redirect', '同意情報は保存されましたが移動できません。画面を開き直してください。' );
}
add_action( 'template_redirect', 'egov_law_monitor_standard_consent_post', 9, 0 );

/** Preserve the existing development/publication gate. */
function egov_law_monitor_standard_application_allowed() {
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 ) { return false; }
    if ( current_user_can( 'manage_options' ) || ( defined( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' ) && EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC === true ) ) { return true; }
    $ids = defined( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' ) ? EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS : array();
    return is_array( $ids ) && in_array( $user_id, $ids, true );
}

/** Same-site destination, independent of credentials and retired issuance settings. */
function egov_law_monitor_standard_application_page_url() {
    $url = home_url( '/standard-application/' );
    $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
    if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! $parts || ! $home ||
        ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || ( $parts['host'] ?? '' ) !== ( $home['host'] ?? '' ) ||
        ( $parts['port'] ?? 443 ) !== ( $home['port'] ?? 443 ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return ''; }
    return $url;
}

/** Optional legacy table stays untouched; unresolved historical links might still be payable. */
function egov_law_monitor_standard_legacy_pending( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'egov_square_checkout_attempts';
    if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) { throw new RuntimeException(); }
    $tables = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ), ARRAY_A );
    if ( ! is_array( $tables ) || $wpdb->last_error !== '' || count( $tables ) > 1 ) { throw new RuntimeException(); }
    if ( ! $tables ) { return false; }
    if ( ( $tables[0]['TABLE_NAME'] ?? null ) !== $table ) { throw new RuntimeException(); }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE user_id = %d AND BINARY state <> %s LIMIT 1", $user_id, 'closed' ), ARRAY_A );
    if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { throw new RuntimeException(); }
    return ! empty( $rows );
}

/** A link must never imply a payment or a valid subscription has been confirmed. */
function egov_law_monitor_standard_plan_view( $user_id ) {
    $plan = egov_law_monitor_get_plan( $user_id );
    $view = array( 'plan' => $plan, 'can_pay' => false, 'message' => '' );
    if ( $plan === 'standard' ) { $view['message'] = 'Standardプランをご利用中です。'; return $view; }
    if ( $plan !== 'free' ) { $view['message'] = 'プラン情報を確認できません。管理者へお問い合わせください。'; return $view; }
    if ( ! egov_law_monitor_standard_application_allowed() ) { $view['message'] = 'Standardプランのお申し込みは現在準備中です。'; return $view; }
    global $wpdb;
    try {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}egov_square_subscriptions WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );
        if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { throw new RuntimeException(); }
        if ( $rows ) { $view['message'] = '登録済みのSquare契約があります。重複決済を避けるため、新しくお支払いせず管理者へお問い合わせください。'; return $view; }
        if ( egov_law_monitor_standard_legacy_pending( $user_id ) ) { $view['message'] = '以前のお申し込みに未確認の情報があります。新しくお支払いせず管理者へお問い合わせください。'; return $view; }
    } catch ( Throwable $error ) { $view['message'] = '契約・旧申込情報を確認できません。新しくお支払いせず管理者へお問い合わせください。'; return $view; }
    // The supplied fixed link is a production link; never present it as Sandbox checkout.
    if ( ! defined( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' ) || EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT !== 'production' ) { $view['message'] = 'Standardプランのお申し込みは現在準備中です。管理者へお問い合わせください。'; return $view; }
    $view['can_pay'] = true;
    return $view;
}

/** Shared customer-facing contact guidance. */
function egov_law_monitor_standard_contact_guidance() {
    return '<p>お支払い・契約に関するお問い合わせは、<a href="' . esc_url( 'https://egovlm.oogushioffice.com/contact/' ) . '">お問い合わせフォーム</a>からご連絡ください。</p>';
}

function egov_law_monitor_standard_application_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>Standardの申込にはログインが必要です。</p>'; }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }
    $view = egov_law_monitor_standard_plan_view( (int) get_current_user_id() );
    $html = '<section class="egov-standard-application"><h2>Standardプラン（月額500円・税込）</h2>';
    $error = $GLOBALS['egov_standard_consent_result'] ?? null;
    if ( is_wp_error( $error ) ) { $html .= '<p role="alert">' . esc_html( $error->get_error_message() ) . '</p>'; }
    if ( $view['can_pay'] ) {
        $html .= '<ul><li>月額500円（税込）</li><li>AI要約：月100回</li><li>法令ウォッチ：キーワード10件まで</li><li>支払方法：クレジットカード決済（Square）</li></ul><h3>契約条件</h3><ul><li>初回決済日を契約開始日とし、1か月ごとに自動更新します。</li><li>決済確認後、原則1営業日以内に管理者がStandard機能を有効化します。</li><li>解約はマイページから申請してください。次回決済日の3日前までの解約申出を原則とします。</li><li>期限後の申出も可能な限り次回決済の停止に対応しますが、間に合わない場合は次回分が請求されることがあります。</li><li>解約後も支払済みの契約期間終了まではStandard機能をご利用いただけます。</li><li>途中解約による日割り返金は原則行いません（法令上必要な場合等を除く）。</li></ul><p><a href="https://egovlm.oogushioffice.com/terms/">利用規約</a> ／ <a href="https://egovlm.oogushioffice.com/specified-commercial-transactions/">特定商取引法に基づく表記</a> ／ <a href="https://egovlm.oogushioffice.com/privacy-policy/">プライバシーポリシー</a></p>';
        $target = egov_law_monitor_standard_application_page_url();
        if ( $target !== '' ) {
            $html .= '<form method="post" action="' . esc_url( $target ) . '">';
            ob_start(); wp_nonce_field( 'egov_standard_consent_' . get_current_user_id() . '_' . EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION . '_' . EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION, 'standard_consent_nonce' ); $html .= ob_get_clean();
            $html .= '<p><label><input type="checkbox" name="standard_agreed" value="1" required> 利用規約、プライバシーポリシーおよび上記の契約条件を確認し、同意します。</label></p><p><button class="egov-law-button egov-standard-payment-button" type="submit" name="egov_standard_consent" value="1">同意してSquareでお支払いへ進む</button></p></form>';
        } else { $html .= '<p>申込ページを確認できません。管理者へお問い合わせください。</p>'; }
        $html .= '<p>すでにお支払い済みの場合は、再度決済せず、プランの切り替えをお待ちください。お問い合わせの際はSquareの請求書番号をお知らせください。</p>';
    } elseif ( $view['plan'] !== 'standard' || ! function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= '<p>' . esc_html( $view['message'] ) . '</p>'; }
    if ( function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= egov_law_monitor_subscription_cancellation_panel( (int) get_current_user_id() ); }
    return $html . egov_law_monitor_standard_contact_guidance() . '</section>';
}
add_shortcode( 'egov_standard_application', 'egov_law_monitor_standard_application_shortcode' );

function egov_law_monitor_plan_status_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>ご利用プランの確認にはログインが必要です。</p>'; }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }
    $view = egov_law_monitor_standard_plan_view( (int) get_current_user_id() );
    $plan = $view['plan']; $known_plan = in_array( $plan, array( 'free', 'standard' ), true );
    $html = '<section class="egov-plan-status"><h2>💳 ご利用プラン</h2><p class="egov-plan-status-current"><span>現在のプラン：</span><strong class="egov-plan-status-badge egov-plan-status-badge--' . ( $known_plan ? $plan : 'unknown' ) . '">' . esc_html( $known_plan ? ( $plan === 'standard' ? 'Standard' : 'Free' ) : '確認が必要です' ) . '</strong></p>';
    if ( $view['can_pay'] ) {
        $url = egov_law_monitor_standard_application_page_url();
        $html .= $url !== '' ? '<p><a class="egov-law-button" href="' . esc_url( $url ) . '">Standardプランに申し込む</a></p>' : '<p>申込ページを確認できません。管理者へお問い合わせください。</p>';
    } elseif ( $view['plan'] !== 'standard' || ! function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= '<p>' . esc_html( $view['message'] ) . '</p>'; }
    if ( function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= egov_law_monitor_subscription_cancellation_panel( (int) get_current_user_id() ); }
    if ( $plan === 'standard' ) { $html .= egov_law_monitor_standard_contact_guidance(); }
    return $html . '</section>';
}
add_shortcode( 'egov_plan_status', 'egov_law_monitor_plan_status_shortcode' );
