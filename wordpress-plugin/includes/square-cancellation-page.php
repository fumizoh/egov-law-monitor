<?php
/** Confirmation page, PRG request handler and administrator date form. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_cancellation_date_label( $date ) {
    if ( ! egov_law_monitor_cancellation_date_valid( $date ) ) { return '未登録'; }
    return DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() )->format( 'Y年m月d日' );
}

function egov_law_monitor_subscription_cancel_post( $factory = null, $redirect = null ) {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['egov_standard_cancel_request'] ) ) { return null; }
    if ( isset( $GLOBALS['egov_standard_cancel_result'] ) ) { return $GLOBALS['egov_standard_cancel_result']; }
    $GLOBALS['egov_standard_cancel_result'] = new WP_Error( 'cancellation_processing', '申請を処理中です。' );
    $target = egov_law_monitor_standard_application_page_url();
    if ( $target === '' || ( $redirect === null && headers_sent() ) ) { $result = new WP_Error( 'cancellation_redirect', '安全な申請画面へ移動できません。画面を開き直してください。' ); }
    else {
        $nonce = isset( $_POST['standard_cancel_nonce'] ) && is_string( $_POST['standard_cancel_nonce'] ) ? wp_unslash( $_POST['standard_cancel_nonce'] ) : '';
        $token = isset( $_POST['standard_cancel_token'] ) && is_string( $_POST['standard_cancel_token'] ) ? wp_unslash( $_POST['standard_cancel_token'] ) : '';
        $result = egov_law_monitor_request_subscription_cancellation( $nonce, $token, $factory );
    }
    $GLOBALS['egov_standard_cancel_result'] = $result;
    if ( is_wp_error( $result ) ) { return $result; }
    egov_law_monitor_process_cancellation_mail( $result['id'], $factory );
    nocache_headers();
    if ( $redirect ) { return $redirect( $target, 303 ); }
    if ( wp_safe_redirect( $target, 303 ) ) { exit; }
    return $GLOBALS['egov_standard_cancel_result'] = new WP_Error( 'cancellation_redirect', '申請は保存されましたが画面を移動できません。再送信せず申請状況をご確認ください。' );
}
add_action( 'template_redirect', 'egov_law_monitor_subscription_cancel_post', 10, 0 );

function egov_law_monitor_subscription_cancellation_panel( $user_id ) {
    if ( ! is_user_logged_in() || (int) get_current_user_id() !== $user_id ) { return ''; }
    $plan = egov_law_monitor_get_plan( $user_id );
    $html = '';
    $error = $GLOBALS['egov_standard_cancel_result'] ?? null;
    if ( is_wp_error( $error ) ) { $html .= '<p role="alert">' . esc_html( $error->get_error_message() ) . '</p>'; }
    try {
        if ( ! egov_law_monitor_cancellation_storage_ready() ) {
            return $html . ( $plan === 'standard' ? '<p>解約申請の受付を確認できません。管理者へお問い合わせください。</p>' : '' );
        }
        $table = egov_law_monitor_cancellation_table(); $context = null;
        if ( $plan === 'standard' ) {
            $context = egov_law_monitor_cancellation_context( $user_id );
            $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE user_id = ? AND approval_id = ?", array( $user_id, $context['approval_id'] ) )[0] ?? null;
        } else {
            $row = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE user_id = ? ORDER BY id DESC LIMIT 1", array( $user_id ) )[0] ?? null;
        }
        $state = egov_law_monitor_cancellation_state( $row, $plan );
        if ( $state === 'completed' ) { return $html . '<p>解約申請の利用終了処理は完了し、Freeへ変更済みです。</p>'; }
        if ( $state === 'requested' ) { return $html . '<p>解約申請を受け付けました。手続き完了後に利用終了日をお知らせします。</p>'; }
        if ( in_array( $state, array( 'scheduled', 'due' ), true ) ) {
            $html .= $state === 'due' ? '<p>Standardプランの利用終了日を過ぎています。管理者による手続き中です。</p>' : '<p>Standardプランは解約予定です。</p>';
            $html .= '<p>利用終了日：<strong>' . esc_html( egov_law_monitor_cancellation_date_label( $row['end_date'] ) ) . '</strong></p>';
            if ( $state === 'scheduled' ) { $html .= '<p>利用終了日まではStandardプランをご利用いただけます。</p>'; }
            return $html;
        }
        if ( $state !== 'none' || $plan !== 'standard' ) { return $html; }
        $html .= '<p>Standardプランをご利用中です。</p>';
        $url = egov_law_monitor_standard_application_page_url();
        if ( $url === '' ) { throw new RuntimeException(); }
        if ( ( $_GET['egov_standard_cancel_confirm'] ?? null ) === '1' ) {
            $html .= '<section class="egov-standard-cancellation"><h3>Standardプランの解約を申請しますか？</h3><p>管理者がSquare Dashboardで手続きを行います。申請だけではSquareの解約や利用終了は完了せず、現在のStandardプランは変更しません。</p><form method="post" action="' . esc_url( $url ) . '">';
            ob_start(); wp_nonce_field( 'egov_standard_cancel_' . $user_id, 'standard_cancel_nonce' ); $html .= ob_get_clean();
            $html .= '<input type="hidden" name="standard_cancel_token" value="' . esc_attr( $context['plan_token'] ) . '"><p><button type="submit" name="egov_standard_cancel_request" value="1">確定して解約を申請する</button> <a href="' . esc_url( $url ) . '">戻る</a></p></form></section>';
        } else { $html .= '<p><a class="egov-law-button" href="' . esc_url( add_query_arg( 'egov_standard_cancel_confirm', '1', $url ) ) . '">Standardを解約する</a></p>'; }
        return $html;
    } catch ( Throwable $error ) { return $html . '<p>承認対象契約または解約申請を確認できません。管理者へお問い合わせください。</p>'; }
}

function egov_law_monitor_render_subscription_cancellations( $user_id, $url ) {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    echo '<h2>Standard解約申請</h2><p>SquareのキャンセルはDashboardで手動操作してください。日付登録だけではSquareの解約を確認したことにはなりません。WordPressのプランは自動変更しません。</p>';
    try {
        if ( ! egov_law_monitor_cancellation_storage_ready() ) { throw new RuntimeException(); }
        $table = egov_law_monitor_cancellation_table();
        $rows = egov_law_monitor_cancellation_rows( "SELECT * FROM `{$table}` WHERE user_id = ? ORDER BY id DESC LIMIT 50", array( $user_id ) );
        $snapshot = egov_law_monitor_square_plan_snapshot( $user_id, egov_law_monitor_square_plan_tables() ); $plan = $snapshot['plan'];
        if ( ! $rows ) { echo '<p>解約申請はありません。</p>'; }
        foreach ( $rows as $row ) {
            $state = egov_law_monitor_cancellation_state( $row, $plan );
            if ( $state !== 'completed' && (int) $snapshot['revision'] !== (int) $row['approval_id'] ) { $state = 'approval_changed'; }
            $labels = array( 'requested' => '未処理：利用終了日未登録', 'scheduled' => '解約予定日登録済み', 'due' => '終了日到来・Free変更待ち', 'completed' => 'Free変更済み', 'invalid' => '日付を確認してください', 'approval_changed' => '現在の承認履歴と一致しません。管理者確認が必要です' );
            echo '<details open><summary>申請 #' . esc_html( $row['id'] ) . '：' . esc_html( $labels[$state] ?? '確認が必要' ) . '</summary><p>対象契約レコード ID: ' . esc_html( $row['contract_id'] ) . ' ／ 承認履歴 ID: ' . esc_html( $row['approval_id'] ) . '</p><p>申請日時：' . esc_html( wp_date( 'Y年m月d日 H:i', strtotime( $row['requested_at'] . ' UTC' ), wp_timezone() ) ) . ' ／ Standard利用終了日：' . esc_html( egov_law_monitor_cancellation_date_label( $row['end_date'] ) ) . '</p><p>管理者通知：' . esc_html( $row['admin_notified_at'] === null ? '送信待ち・失敗時は再試行' : '送信処理受付済み' ) . ' ／ 期限通知の最終試行日：' . esc_html( $row['last_due_attempt_date'] ?? 'なし' ) . ' ／ 期限通知の最終受付日：' . esc_html( $row['last_due_sent_date'] ?? 'なし' ) . '</p>';
            if ( ! in_array( $state, array( 'completed', 'approval_changed' ), true ) ) {
                echo '<form method="post" action="' . esc_url( $url ) . '">';
                wp_nonce_field( 'egov_cancel_date_' . $user_id . '_' . $row['id'], 'subscription_cancel_date_nonce' );
                echo '<input type="hidden" name="subscription_cancel_id" value="' . esc_attr( $row['id'] ) . '"><input type="hidden" name="subscription_cancel_token" value="' . esc_attr( egov_law_monitor_cancellation_token( $row ) ) . '"><p><label>Squareで確認したStandard利用終了日 <input type="date" name="subscription_end_date" value="' . esc_attr( $row['end_date'] ?? '' ) . '" required></label></p><p><label><input type="checkbox" name="subscription_dashboard_confirmed" value="1" required>Square Dashboardでキャンセル操作と契約終了日を確認しました</label></p><p><label>確認理由・変更理由<textarea name="subscription_cancel_reason" maxlength="2000" rows="2" required></textarea></label></p><button class="button" name="egov_subscription_cancel_date" type="submit">利用終了日を保存</button></form>';
                echo '<p>利用終了日はサイトのタイムゾーンで当日23:59までです。終了後に上記の「Standard承認を解除」を実行してください。</p>';
            }
            echo '</details>';
        }
    } catch ( Throwable $error ) { echo '<p>解約申請の保存先・状態を確認できません。管理者がスキーマと承認履歴を確認してください。</p>'; }
}
