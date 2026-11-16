<?php
/** Member request receipt only. Never starts withdrawal or changes entitlements. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/withdrawal-cancellation-integration.php';

function egov_law_monitor_withdrawal_request_key( $user_id ) {
    if ( ! is_int( $user_id ) || $user_id < 1 ) { throw new RuntimeException(); }
    return 'egov_law_monitor_withdrawal_request_' . $user_id;
}

/** Options API with strict validation: missing, malformed and failed reads are distinct. */
function egov_law_monitor_get_withdrawal_request( $user_id ) {
    global $wpdb;
    try {
        $key = egov_law_monitor_withdrawal_request_key( $user_id );
        $wpdb->last_error = '';
        $record = get_option( $key, '__egov_withdrawal_request_absent__' );
        if ( $wpdb->last_error !== '' ) { throw new RuntimeException(); }
        if ( $record === '__egov_withdrawal_request_absent__' ) { return null; }
        $date = is_array( $record ) ? ( $record['requested_at'] ?? null ) : null;
        $parsed = is_string( $date ) && preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $date, new DateTimeZone( 'UTC' ) ) : false;
        $legacy = is_array( $record ) && count( $record ) === 3 && ( $record['status'] ?? null ) === 'received';
        $linked = is_array( $record ) && count( $record ) === 7 && is_int( $record['approval_id'] ?? null ) && $record['approval_id'] > 0 && is_int( $record['contract_id'] ?? null ) && $record['contract_id'] > 0 && is_int( $record['cancellation_id'] ?? null ) && (
            ( ( $record['status'] ?? null ) === 'pending_cancellation' && $record['cancellation_id'] === 0 && in_array( $record['cancellation_attempt'] ?? null, array( 'ready', 'uncertain', 'failed' ), true ) ) ||
            ( ( $record['status'] ?? null ) === 'received' && $record['cancellation_id'] > 0 && ( $record['cancellation_attempt'] ?? null ) === 'confirmed' ) );
        if ( ! ( $legacy || $linked ) || ( $record['user_id'] ?? null ) !== $user_id || ! $parsed || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $date ) { throw new RuntimeException(); }
        return $record;
    } catch ( Throwable $ignored ) { return new WP_Error( 'withdrawal_request_read', '退会申請の受付状況を確認できません。管理者へお問い合わせください。' ); }
}

/** ID is always derived from the authenticated session; posted IDs are only checked. */
function egov_law_monitor_submit_withdrawal_request( $fields, $factory = null ) {
    $user_id = (int) get_current_user_id();
    if ( ! is_user_logged_in() || $user_id < 1 ) { return new WP_Error( 'withdrawal_request_login', 'ログインしてから退会を申請してください。' ); }
    if ( ! is_array( $fields ) || ! is_string( $fields['withdrawal_request_nonce'] ?? null ) || ! wp_verify_nonce( $fields['withdrawal_request_nonce'], 'egov_withdrawal_request_' . $user_id ) ) { return new WP_Error( 'withdrawal_request_nonce', '操作を確認できません。画面を開き直してください。' ); }
    if ( ( $fields['withdrawal_request_user_id'] ?? null ) !== (string) $user_id || ( $fields['confirm_withdrawal_request'] ?? null ) !== '1' ) { return new WP_Error( 'withdrawal_request_confirm', '対象ユーザーと退会申請の確認が必要です。' ); }
    // The administrator-oriented target() excludes its actor and must not be used here.
    $plan = egov_law_monitor_get_plan( $user_id );
    if ( ! egov_law_monitor_withdrawal_member( get_userdata( $user_id ) ) || ! in_array( $plan, array( 'free', 'standard' ), true ) ) { return new WP_Error( 'withdrawal_request_target', 'このアカウントからは退会を申請できません。管理者へお問い合わせください。' ); }
    $existing = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $existing ) ) { return $existing; }
    if ( $existing !== null && $plan === 'free' && ! isset( $existing['approval_id'] ) ) { return $existing; }
    try {
        if ( egov_law_monitor_withdrawal_state( $user_id ) !== null ) { return new WP_Error( 'withdrawal_request_started', '退会手続きは既に開始されています。管理者へお問い合わせください。' ); }
        if ( $plan === 'standard' || isset( $existing['approval_id'] ) ) { return egov_law_monitor_link_withdrawal_cancellation( $user_id, $existing, $fields, $factory ); }
        $record = array( 'user_id' => $user_id, 'requested_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'status' => 'received' );
        // option_name is UNIQUE: concurrent requests cannot overwrite the first receipt.
        $added = add_option( egov_law_monitor_withdrawal_request_key( $user_id ), $record, '', false );
        $saved = egov_law_monitor_get_withdrawal_request( $user_id );
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( $saved === null || ( $added && $saved !== $record ) ) { throw new RuntimeException(); }
        return $saved; // A concurrently inserted, valid first receipt is also accepted.
    } catch ( Throwable $ignored ) { return new WP_Error( 'withdrawal_request_save', '退会申請の保存を確認できません。受付状況を再確認してください。' ); }
}

/** Same-site current page; Gutenberg selects the plan tab through its verified fragment. */
function egov_law_monitor_withdrawal_request_url() {
    $home = wp_parse_url( home_url( '/' ) );
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( ! $home || ! in_array( $home['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $home['host'] ) || ! is_string( $uri ) || strlen( $uri ) > 2048 || ! preg_match( '~\A/(?!/)~', $uri ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) ) { return ''; }
    $url = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $uri;
    // Fragments are not sent with HTTP requests. Set it explicitly for links, forms and PRG.
    $url = explode( '#', $url, 2 )[0];
    return remove_query_arg( array( 'egov_withdrawal_confirm', '_wpnonce' ), $url ) . '#plan-status';
}

function egov_law_monitor_withdrawal_request_post( $redirect = null ) {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['egov_withdrawal_request'] ) ) { return; }
    if ( isset( $GLOBALS['egov_withdrawal_request_result'] ) ) { return $GLOBALS['egov_withdrawal_request_result']; }
    $url = egov_law_monitor_withdrawal_request_url();
    if ( $url === '' || ( $redirect === null && headers_sent() ) || ( $_POST['egov_withdrawal_request'] ?? null ) !== '1' ) { return $GLOBALS['egov_withdrawal_request_result'] = new WP_Error( 'withdrawal_request_page', '安全な申請画面を確認できません。画面を開き直してください。' ); }
    $result = egov_law_monitor_submit_withdrawal_request( wp_unslash( $_POST ) );
    $GLOBALS['egov_withdrawal_request_result'] = $result;
    if ( is_wp_error( $result ) ) { return $result; }
    nocache_headers();
    if ( $redirect ) { return $redirect( $url, 303 ); }
    if ( wp_safe_redirect( $url, 303 ) ) { exit; }
    return $GLOBALS['egov_withdrawal_request_result'] = new WP_Error( 'withdrawal_request_redirect', '申請は保存されましたが移動できません。再送信せず受付状況をご確認ください。' );
}
add_action( 'template_redirect', 'egov_law_monitor_withdrawal_request_post', 10, 0 );

function egov_law_monitor_withdrawal_request_panel( $user_id ) {
    if ( ! is_user_logged_in() || $user_id !== (int) get_current_user_id() || ! egov_law_monitor_withdrawal_member( get_userdata( $user_id ) ) ) { return ''; }
    $html = '<section class="egov-withdrawal-request"><h3>アカウントの退会</h3>';
    $error = $GLOBALS['egov_withdrawal_request_result'] ?? null;
    if ( is_wp_error( $error ) ) { $html .= '<p role="alert">' . esc_html( $error->get_error_message() ) . '</p>'; }
    $record = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $record ) ) { return $html . '<p role="alert">' . esc_html( $record->get_error_message() ) . '</p></section>'; }
    $standard = egov_law_monitor_get_plan( $user_id ) === 'standard'; $context = null; $row = null;
    $warning = $standard ? '<p>退会申請にはStandard解約申請も含まれます。Squareでの実際の解約・課金停止は管理者が手動で実施します。申請だけではSquare契約は終了せず、課金も停止しません。利用終了日まではStandardをご利用いただけます。</p>' : '<p>Freeプランの退会申請は、アカウントの退会のみを申し込みます。申請だけではアカウントや個人履歴を削除しません。</p>';
    try {
        if ( $standard ) { $context = egov_law_monitor_withdrawal_link_context( $user_id ); }
        if ( isset( $record['approval_id'] ) ) {
            if ( $standard && ( $record['approval_id'] !== $context['approval_id'] || $record['contract_id'] !== $context['contract_id'] ) ) { throw new RuntimeException(); }
            $row = egov_law_monitor_withdrawal_link_row( $user_id, $record );
            if ( $row && $standard && $row['completed_at'] !== null ) { throw new RuntimeException(); }
        }
        $complete = $record !== null && $record['status'] === 'received' && ( isset( $record['approval_id'] ) ? $row !== null : ! $standard );
        if ( $complete ) { return $html . '<p>退会申請を受け付けました。' . ( isset( $record['approval_id'] ) ? 'Standard解約申請との連携も確認済みです。' : '' ) . '管理者が確認のうえ、手続きを進めます。申請だけでは通常機能は変更されません。管理者が退会処理を開始した後は保存操作が制限されます。</p>' . $warning . '</section>'; }
        if ( egov_law_monitor_withdrawal_state( $user_id ) !== null ) { return $html . '<p>管理者による退会手続きが開始されています。管理者へお問い合わせください。</p></section>'; }
    } catch ( Throwable $ignored ) { return $html . '<p role="alert">承認対象の契約・解約申請・退会状態を確認できません。成功とは判断せず管理者へお問い合わせください。</p></section>'; }
    if ( $record !== null ) { $html .= '<p role="alert">退会意思は保存済みですが、Standard解約申請との連携は未完了です。受付内容を再照合してください。結果不明の申請を自動再送することはありません。</p>'; }
    $url = egov_law_monitor_withdrawal_request_url();
    if ( $url === '' ) { return $html . '<p>退会申請画面を確認できません。</p></section>'; }
    $confirm = ( $_GET['egov_withdrawal_confirm'] ?? null ) === '1' || isset( $_POST['egov_withdrawal_request'] );
    $guidance = $standard ? '退会を申請すると、Standardプランの解約も同時に申し込まれます。Standardプランは利用終了日までご利用いただけます。利用期間終了後、管理者が退会手続きを行います。' : '退会を希望される方は、以下のボタンからお申し込みください。お申し込み後、管理者が退会手続きを行います。';
    if ( ! $confirm ) { return $html . '<p>' . $guidance . '</p><p><a data-egov-withdrawal-navigation class="egov-law-button" href="' . esc_url( add_query_arg( 'egov_withdrawal_confirm', '1', $url ) ) . '">' . ( $record !== null ? '受付内容を再照合する' : '退会を申請する' ) . '</a></p></section>'; }
    $html .= '<h4>退会申請の確認</h4><ul><li>申請後、管理者が退会手続きを行います。</li><li>退会完了後はアカウントが削除され、法令ウォッチやAI要約の個人履歴が利用できなくなります。</li>';
    if ( $standard ) { $html .= '<li>退会申請にはStandardプランの解約申請も含まれます。</li><li>Squareでの実際の解約・課金停止は管理者が行うため、申請時点では完了しません。</li><li>利用終了日まではStandardプランをご利用いただけます。</li>'; }
    $html .= '</ul><form method="post" action="' . esc_url( $url ) . '">';
    ob_start(); wp_nonce_field( 'egov_withdrawal_request_' . $user_id, 'withdrawal_request_nonce' ); $html .= ob_get_clean();
    if ( $context !== null ) {
        ob_start(); wp_nonce_field( 'egov_standard_cancel_' . $user_id, 'withdrawal_cancel_nonce' ); $html .= ob_get_clean();
        $html .= '<input type="hidden" name="withdrawal_cancel_token" value="' . esc_html( $context['token'] ) . '">';
    }
    return $html . '<input type="hidden" name="egov_withdrawal_request" value="1"><input type="hidden" name="withdrawal_request_user_id" value="' . esc_html( $user_id ) . '"><p><label><input type="checkbox" required name="confirm_withdrawal_request" value="1"> 上記を確認し、退会を申請します</label></p><p><button class="egov-law-button" type="submit">' . ( $record !== null ? '確認して再照合する' : '確定して退会を申請する' ) . '</button></p></form><p><a data-egov-withdrawal-navigation href="' . esc_url( $url ) . '">戻る</a></p></section>';
}

function egov_law_monitor_render_withdrawal_request_receipt( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $record = egov_law_monitor_get_withdrawal_request( $user_id );
    if ( is_wp_error( $record ) ) { echo '<p>申請受付の読み取りエラー：' . esc_html( $record->get_error_message() ) . '</p>'; return; }
    if ( $record === null ) { echo '<p>マイページからの退会申請：記録なし。問い合わせ窓口で受けた申請は従来どおり確認してください。</p>'; return; }
    if ( isset( $record['approval_id'] ) ) {
        try { $row = egov_law_monitor_withdrawal_link_row( $user_id, $record ); echo '<p>連携対象：承認ID ' . esc_html( $record['approval_id'] ) . ' ／ 契約ID ' . esc_html( $record['contract_id'] ) . ' ／ 解約申請ID ' . esc_html( $row ? $row['id'] : '未確認' ) . ' ／ ' . esc_html( $record['status'] === 'received' && $row ? '連携確認済み' : '連携未完了・要確認' ) . '</p>'; }
        catch ( Throwable $ignored ) { echo '<p>解約申請の連携を読み取れません。退会開始前に確認してください。</p>'; }
    }
    $date = ( new DateTimeImmutable( $record['requested_at'] ) )->setTimezone( wp_timezone() )->format( 'Y年m月d日 H:i:s' );
    echo '<p><strong>マイページからの退会申請：' . ( $record['status'] === 'pending_cancellation' ? 'Standard解約申請との連携未完了' : '受付済み' ) . '</strong> ／ ユーザーID：' . esc_html( $record['user_id'] ) . ' ／ 申請日時：' . esc_html( $date ) . '</p><p>受付記録は本人確認の完了を意味しません。「退会申請を受け付けている」と本人確認のチェックは管理者が明示的に行ってください。</p>';
}
