<?php
/** Fixed Dashboard link and read-only plan guidance. No POST handlers or Square API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
    if ( $plan === 'standard' ) { $view['message'] = 'Standardプランをご利用中です。Squareの支払状況はこの画面では確認できません。契約については管理者へお問い合わせください。'; return $view; }
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

function egov_law_monitor_standard_application_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>Standardの申込にはログインが必要です。</p>'; }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) { nocache_headers(); }
    $view = egov_law_monitor_standard_plan_view( (int) get_current_user_id() );
    $html = '<section class="egov-standard-application"><h2>Standardプラン（月額500円・税込）</h2>';
    if ( $view['can_pay'] ) {
        $html .= '<p>Standardプランのお支払いは、Squareの決済ページで行います。</p><p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。切り替えが完了するまで、しばらくお待ちください。</p><p><a class="egov-law-button egov-standard-payment-button" href="https://square.link/u/30s16R3f" rel="noreferrer">Squareでお支払いへ進む</a></p><p>すでにお支払い済みの場合は、再度決済せず、プランの切り替えをお待ちください。お問い合わせの際はSquareの請求書番号をお知らせください。</p>';
    } else { $html .= '<p>' . esc_html( $view['message'] ) . '</p>'; }
    if ( function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= egov_law_monitor_subscription_cancellation_panel( (int) get_current_user_id() ); }
    return $html . '<p>お支払い・契約・キャンセルについては管理者へお問い合わせください。この画面からSquareの契約変更・解約は行いません。</p></section>';
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
        $html .= $url !== '' ? '<p><a href="' . esc_url( $url ) . '">Standardプランに申し込む</a></p>' : '<p>申込ページを確認できません。管理者へお問い合わせください。</p>';
    } else { $html .= '<p>' . esc_html( $view['message'] ) . '</p>'; }
    if ( function_exists( 'egov_law_monitor_subscription_cancellation_panel' ) ) { $html .= egov_law_monitor_subscription_cancellation_panel( (int) get_current_user_id() ); }
    return $html . '</section>';
}
add_shortcode( 'egov_plan_status', 'egov_law_monitor_plan_status_shortcode' );
