<?php
/** Invoice-based approval, user-level revocation and read-only contract history. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/admin-square-audit.php';

function egov_law_monitor_render_square_subscriptions() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'このページにアクセスする権限がありません。' ); }
    global $wpdb;
    $user_id = isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $user = get_userdata( $user_id );
    if ( ! $user ) { wp_die( '対象ユーザーが存在しません。' ); }
    // Retired endpoints must never fall through to an active write operation.
    foreach ( array( 'egov_square_save', 'egov_square_lookup', 'egov_square_approve', 'egov_checkout_cancel', 'egov_checkout_contract_lookup', 'egov_checkout_contract_register', 'egov_square_checkout', 'egov_checkout_cancel_request' ) as $action ) {
        if ( isset( $_POST[$action] ) ) { wp_die( '旧Square操作は廃止しました。請求書番号による照会・承認を利用してください。' ); }
    }
    $table = $wpdb->prefix . 'egov_square_subscriptions';
    $free_message = null; $invoice_result = null; $invoice_approval = null;
    $actions = array_filter( array( isset( $_POST['egov_square_free'] ), isset( $_POST['egov_square_invoice_lookup'] ), isset( $_POST['egov_square_invoice_approve'] ) ) );
    if ( count( $actions ) > 1 ) { wp_die( 'Square照会・承認・承認解除は別々に操作してください。' ); }
    if ( isset( $_POST['egov_square_invoice_lookup'] ) ) {
        $number = isset( $_POST['invoice_number'] ) && is_string( $_POST['invoice_number'] ) ? wp_unslash( $_POST['invoice_number'] ) : '';
        $nonce = isset( $_POST['invoice_nonce'] ) && is_string( $_POST['invoice_nonce'] ) ? wp_unslash( $_POST['invoice_nonce'] ) : '';
        $invoice_result = egov_law_monitor_lookup_square_invoice( $user_id, $number, $nonce );
        $invoice_result = egov_law_monitor_invoice_candidate( $user_id, $invoice_result );
    }
    if ( isset( $_POST['egov_square_invoice_approve'] ) ) {
        $fields = array();
        foreach ( array( 'nonce' => 'invoice_approve_nonce', 'candidate' => 'invoice_candidate', 'signature' => 'invoice_signature', 'confirmed' => 'invoice_confirmed', 'reason' => 'invoice_reason', 'environment_confirmed' => 'invoice_environment_confirmed', 'environment_reason' => 'invoice_environment_reason' ) as $key => $field ) {
            $fields[$key] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : '';
        }
        $invoice_approval = egov_law_monitor_approve_square_invoice( $user_id, $fields );
    }
    if ( isset( $_POST['egov_square_free'] ) ) {
        $free_nonce = isset( $_POST['egov_square_free_nonce'] ) && is_string( $_POST['egov_square_free_nonce'] ) ? wp_unslash( $_POST['egov_square_free_nonce'] ) : '';
        $free_token = isset( $_POST['free_plan_token'] ) && is_string( $_POST['free_plan_token'] ) ? wp_unslash( $_POST['free_plan_token'] ) : '';
        $free_reason = isset( $_POST['free_reason'] ) && is_string( $_POST['free_reason'] ) ? wp_unslash( $_POST['free_reason'] ) : '';
        if ( ! wp_verify_nonce( $free_nonce, 'egov_square_free_' . $user_id ) ) {
            $free_message = new WP_Error( 'invalid_nonce', '操作を確認できません。画面を再読み込みしてください。' );
        } elseif ( ( $_POST['free_confirmed'] ?? null ) !== '1' ) {
            $free_message = new WP_Error( 'confirmation_required', 'Standard承認を解除することを確認してください。' );
        } else {
            $free_message = egov_law_monitor_square_make_free( $user_id, $free_token, $free_nonce, $free_reason );
        }
    }
    $contracts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A );
    $read_error = $wpdb->last_error !== '';
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}egov_square_admin_log WHERE user_id = %d ORDER BY id DESC LIMIT 100", $user_id
    ), ARRAY_A );
    $read_error = $read_error || $wpdb->last_error !== '';
    $url = add_query_arg( array( 'page' => 'egov-square-subscriptions', 'user_id' => $user_id ), admin_url( 'admin.php' ) );
    ?>
    <div class="wrap">
        <h1>Square契約管理</h1>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=egov-law-monitor' ) ); ?>">ユーザー管理へ戻る</a></p>
        <p>請求書番号で契約・支払いを照会し、本人確認後に契約登録とStandard承認を行ってください。照会だけでは利用権限は変わりません。</p>
        <p>WordPress User ID: <?php echo esc_html( $user_id ); ?> ／ ユーザー名: <?php echo esc_html( $user->user_login ); ?>（<?php echo esc_html( $user->display_name ); ?>） ／ メール: <?php echo esc_html( $user->user_email ); ?></p>
        <p>現在のプラン: <strong><?php echo esc_html( egov_law_monitor_get_plan_label( egov_law_monitor_get_plan( $user_id ) ) ); ?></strong></p>
        <?php egov_law_monitor_render_invoice_lookup( $user_id, $url, $invoice_result ); ?>
        <?php if ( $invoice_approval !== null ) : ?>
            <div class="notice <?php echo is_wp_error( $invoice_approval ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $invoice_approval ) ? $invoice_approval->get_error_message() : $invoice_approval['message'] ); ?></p></div>
        <?php endif; ?>
        <?php if ( $free_message !== null ) : ?>
            <div class="notice <?php echo is_wp_error( $free_message ) ? 'notice-error' : ( $free_message['warning'] ? 'notice-warning' : 'notice-success' ); ?>"><p><?php echo esc_html( is_wp_error( $free_message ) ? $free_message->get_error_message() : $free_message['message'] ); ?></p></div>
        <?php endif; ?>
        <?php if ( egov_law_monitor_get_plan( $user_id ) === 'standard' ) : ?>
            <h2>Standard承認を解除</h2>
            <p>この操作はWordPressの利用権限のみを変更し、Squareのサブスクリプションは解約しません。</p>
            <p>ユーザーの利用権限を変更します。Square側の解約・課金停止は行いません。契約・確認情報・承認履歴・AI要約利用回数は保持します。上限を超える有効ウォッチは停止されます。</p>
            <?php $free_token = egov_law_monitor_square_plan_form_token( $user_id ); ?>
            <?php if ( is_wp_error( $free_token ) ) : ?>
                <p><?php echo esc_html( $free_token->get_error_message() ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( $url ); ?>">
                    <?php wp_nonce_field( 'egov_square_free_' . $user_id, 'egov_square_free_nonce' ); ?>
                    <input type="hidden" name="free_plan_token" value="<?php echo esc_attr( $free_token ); ?>">
                    <p><label>承認解除の理由<textarea name="free_reason" rows="2" maxlength="2000" required></textarea></label></p>
                    <p><label><input type="checkbox" name="free_confirmed" value="1" required>このユーザーのStandard承認を解除し、Freeへ戻すことを確認しました。</label></p>
                    <button type="submit" name="egov_square_free" class="button">Standard承認を解除</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $read_error || ! egov_law_monitor_square_storage_ready() ) : ?><div class="notice notice-error"><p>契約情報・操作履歴または保存先を確認できません。管理者がDBの状態を確認してください。</p></div><?php endif; ?>
        <h2>登録済みSquare契約</h2>
        <table class="widefat striped"><thead><tr><th>ID</th><th>Customer ID</th><th>Subscription ID</th><th>最終確認日時</th><th>確認管理者</th><th>管理者メモ</th></tr></thead><tbody>
        <?php foreach ( (array) $contracts as $contract ) : ?>
            <?php $verifier = $contract['verified_by'] ? get_userdata( $contract['verified_by'] ) : false; ?>
            <tr>
                <td><?php echo esc_html( $contract['id'] ); ?></td>
                <td><?php echo esc_html( $contract['square_customer_id'] ); ?></td>
                <td><?php echo esc_html( $contract['square_subscription_id'] ); ?></td>
                <td><?php echo esc_html( $contract['verified_at'] ?: '未確認' ); ?></td>
                <td><?php echo esc_html( $contract['verified_by'] ? ( $verifier ? $verifier->display_name : '削除済み管理者' ) . ' (ID: ' . $contract['verified_by'] . ')' : '—' ); ?></td>
                <td style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( $contract['admin_note'] ?? '' ); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ( ! $contracts && ! $read_error ) : ?><tr><td colspan="6">登録済み契約はありません。</td></tr><?php endif; ?>
        </tbody></table>
        <h2>Square操作履歴（最新100件・旧操作の履歴を含む）</h2>
        <?php egov_law_monitor_render_square_audit( $logs, $read_error ); ?>
    </div>
    <?php
}
