<?php
/** PRG transport only. Authorization and business validation remain in each operation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_withdrawal_post_actions() {
    return array(
        'start' => array( 'egov_law_monitor_start_withdrawal', '退会処理を開始しました。次の段階は自動実行しません。' ),
        'watches' => array( 'egov_law_monitor_delete_withdrawal_watches', '法令ウォッチ・通知設定の削除と残存0件を確認しました。' ),
        'ai' => array( 'egov_law_monitor_delete_withdrawal_ai_history', 'AI個人履歴・Free試用回数の削除と残存0件を確認しました。' ),
        'user' => array( 'egov_law_monitor_delete_withdrawal_user', 'WordPressユーザー削除と結果監査の保存を確認しました。退会完了は別操作です。' ),
        'user_rearm' => array( 'egov_law_monitor_rearm_withdrawal_user', '削除失敗を再確認してstartedへ戻しました。ユーザー削除は再実行していません。' ),
        'reclean' => array( 'egov_law_monitor_reclean_withdrawal', '削除後の残存データを再清掃しました。残存データを再確認してください。' ),
        'complete' => array( 'egov_law_monitor_complete_withdrawal', '退会完了を記録しました。' ),
    );
}

function egov_law_monitor_withdrawal_page_user_id() {
    $raw = $_GET['user_id'] ?? null;
    if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]*\z/', $raw ) || (string) (int) $raw !== $raw ) { wp_die( '有効なユーザーIDを指定してください。' ); }
    return (int) $raw;
}

/** One opaque receipt per POST, isolated by administrator, login session and target. */
function egov_law_monitor_withdrawal_notice_key( $user_id, $receipt ) {
    return 'egov_wd_result_' . hash_hmac( 'sha256', wp_json_encode( array( get_current_user_id(), wp_get_session_token(), $user_id, $receipt ) ), wp_salt( 'auth' ) );
}

/** Runs on load-$page_hook, before the admin header. No renderer executes POSTs. */
function egov_law_monitor_withdrawal_handle_post( $factory = null ) {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'POST' ) { return; }
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'このページにアクセスする権限がありません。' ); }
    $user_id = egov_law_monitor_withdrawal_page_user_id();
    $actions = egov_law_monitor_withdrawal_post_actions();
    $action = $_POST['withdrawal_action'] ?? null;
    $notice = array( 'type' => 'error', 'message' => '未対応の操作です。処理は実行していません。' );
    if ( is_string( $action ) && isset( $actions[$action] ) ) {
        try {
            // WP unslashes request fields once; do not accept user IDs or redirect URLs from POST.
            $result = $actions[$action][0]( $user_id, wp_unslash( $_POST ), $factory );
            $success = $result === true || ( is_array( $result ) && in_array( $action, array( 'watches', 'ai', 'user' ), true ) );
            $notice = is_wp_error( $result )
                ? array( 'type' => 'error', 'message' => $result->get_error_message() )
                : array( 'type' => $success ? 'success' : 'error', 'message' => $success ? $actions[$action][1] : '操作結果を確認できません。成功と判断せず、状態と監査を再確認してください。' );
        } catch ( Throwable $ignored ) {
            $notice = array( 'type' => 'error', 'message' => '操作結果が不明です。再実行せず、最新状態・監査記録を確認してください。' );
        }
    }
    $receipt = bin2hex( random_bytes( 16 ) );
    // A missing/failed/expired transient can never become a success notice on GET.
    try { set_transient( egov_law_monitor_withdrawal_notice_key( $user_id, $receipt ), $notice, 300 ); }
    catch ( Throwable $ignored ) { /* Still redirect; GET will display an unavailable-result warning. */ }
    $url = admin_url( 'admin.php?page=egov-user-withdrawal-check&user_id=' . $user_id . '&withdrawal_result=' . $receipt );
    if ( ! wp_safe_redirect( $url, 303 ) ) { wp_die( 'リダイレクトできません。操作を再送信せず、退会管理を開き直して状態と監査を確認してください。' ); }
    exit;
}

/** GET parameters identify a server-side notice; they never supply the outcome. */
function egov_law_monitor_render_withdrawal_notice( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['withdrawal_result'] ) ) { return; }
    $receipt = $_GET['withdrawal_result']; $notice = false;
    if ( is_string( $receipt ) && preg_match( '/\A[a-f0-9]{32}\z/', $receipt ) ) {
        $key = egov_law_monitor_withdrawal_notice_key( $user_id, $receipt );
        try { $notice = get_transient( $key ); delete_transient( $key ); }
        catch ( Throwable $ignored ) { $notice = false; }
    }
    if ( ! is_array( $notice ) || ! in_array( $notice['type'] ?? null, array( 'success', 'error' ), true ) || ! is_string( $notice['message'] ?? null ) ) {
        $notice = array( 'type' => 'warning', 'message' => '操作結果の表示期限切れ、表示済み、または結果を取得できません。再実行せず、最新状態・監査記録を確認してください。' );
    }
    echo '<div class="notice notice-' . esc_html( $notice['type'] ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
}
