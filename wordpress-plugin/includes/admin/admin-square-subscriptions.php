<?php
/** Administrator-only contract registration, lookup and explicit manual approval. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function egov_law_monitor_render_square_subscriptions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'このページにアクセスする権限がありません。' );
    }
    global $wpdb;
    $user_id = isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        wp_die( '対象ユーザーが存在しません。' );
    }
    $id = isset( $_GET['subscription_id'] ) && is_scalar( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
    $table = $wpdb->prefix . 'egov_square_subscriptions';
    $editing = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ), ARRAY_A ) : null;
    if ( $id && ! $editing ) {
        wp_die( '対象ユーザーの契約が見つかりません。' );
    }
    $values = $editing ?: array( 'square_customer_id' => '', 'square_subscription_id' => '', 'admin_note' => '' );
    $form_token = $editing ? egov_law_monitor_square_record_token( $editing ) : '';
    $message = null;
    $lookup_result = null;
    $lookup_record_id = 0;
    $lookup_requested_at = '';
    $approval_message = null;
    $free_message = null;
    $checkout_contract_result = null;
    $invoice_result = null;
    $invoice_approval = null;
    $actions = array_filter( array( isset( $_POST['egov_square_lookup'] ), isset( $_POST['egov_square_save'] ), isset( $_POST['egov_square_approve'] ), isset( $_POST['egov_square_free'] ), isset( $_POST['egov_checkout_cancel'] ), isset( $_POST['egov_checkout_contract_lookup'] ), isset( $_POST['egov_checkout_contract_register'] ) ) );
    if ( count( $actions ) + ( isset( $_POST['egov_square_invoice_lookup'] ) ? 1 : 0 ) + ( isset( $_POST['egov_square_invoice_approve'] ) ? 1 : 0 ) > 1 ) {
        wp_die( '契約保存・Square照会・プラン変更は別々に操作してください。' );
    }
    if ( isset( $_POST['egov_square_invoice_lookup'] ) ) {
        $number = isset( $_POST['invoice_number'] ) && is_string( $_POST['invoice_number'] ) ? wp_unslash( $_POST['invoice_number'] ) : '';
        $nonce = isset( $_POST['invoice_nonce'] ) && is_string( $_POST['invoice_nonce'] ) ? wp_unslash( $_POST['invoice_nonce'] ) : '';
        $invoice_result = egov_law_monitor_lookup_square_invoice( $user_id, $number, $nonce );
        $invoice_result = egov_law_monitor_invoice_candidate( $user_id, $invoice_result );
    }
    if ( isset( $_POST['egov_square_invoice_approve'] ) ) {
        $fields = array();
        foreach ( array( 'nonce' => 'invoice_approve_nonce', 'candidate' => 'invoice_candidate', 'signature' => 'invoice_signature', 'confirmed' => 'invoice_confirmed', 'reason' => 'invoice_reason' ) as $key => $field ) {
            $fields[$key] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : '';
        }
        $invoice_approval = egov_law_monitor_approve_square_invoice( $user_id, $fields );
    }
    if ( isset( $_POST['egov_checkout_contract_lookup'] ) || isset( $_POST['egov_checkout_contract_register'] ) ) {
        $fields = array();
        foreach ( array( 'attempt_id', 'attempt_token', 'attempt_nonce', 'candidate', 'candidate_signature' ) as $field ) { $fields[$field] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : ''; }
        $checkout_contract_result = egov_law_monitor_checkout_contract_operation( $user_id, $fields, isset( $_POST['egov_checkout_contract_register'] ) );
    }
    if ( isset( $_POST['egov_checkout_cancel'] ) ) {
        $cancel_fields = array();
        foreach ( array( 'checkout_cancel_id', 'checkout_cancel_nonce', 'checkout_cancel_token', 'checkout_cancel_reason', 'link_deleted', 'no_payment_subscription', 'no_unresolved_issue' ) as $field ) {
            $cancel_fields[$field] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : '';
        }
        $cancel_result = egov_law_monitor_complete_checkout_cancel( $user_id, $cancel_fields );
        echo '<div class="notice notice-warning"><p>' . esc_html( is_wp_error( $cancel_result ) ? $cancel_result->get_error_message() : '管理者によるキャンセル完了を登録しました。Square側への通信やプラン変更は行っていません。' ) . '</p></div>';
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
    if ( isset( $_POST['egov_square_approve'] ) ) {
        $approval_id = isset( $_POST['approval_record_id'] ) && is_string( $_POST['approval_record_id'] ) && ctype_digit( $_POST['approval_record_id'] ) ? (int) $_POST['approval_record_id'] : 0;
        $approval_nonce = isset( $_POST['egov_square_approve_nonce'] ) && is_string( $_POST['egov_square_approve_nonce'] ) ? wp_unslash( $_POST['egov_square_approve_nonce'] ) : '';
        $approval_token = isset( $_POST['approval_token'] ) && is_string( $_POST['approval_token'] ) ? wp_unslash( $_POST['approval_token'] ) : '';
        $approval_reason = isset( $_POST['approval_reason'] ) && is_string( $_POST['approval_reason'] ) ? wp_unslash( $_POST['approval_reason'] ) : '';
        $approval_message = $approval_id > 0 ? egov_law_monitor_square_change_plan( $user_id, 'standard', $approval_token, $approval_nonce, $approval_id, ( $_POST['dashboard_confirmed'] ?? null ) === '1', $approval_reason ) : new WP_Error( 'invalid_contract', '承認対象の契約を指定してください。' );
    }
    if ( isset( $_POST['egov_square_lookup'] ) ) {
        $lookup_record_id = isset( $_POST['lookup_record_id'] ) && is_scalar( $_POST['lookup_record_id'] ) ? absint( $_POST['lookup_record_id'] ) : 0;
        $lookup_nonce = isset( $_POST['egov_square_lookup_nonce'] ) && is_string( $_POST['egov_square_lookup_nonce'] ) ? wp_unslash( $_POST['egov_square_lookup_nonce'] ) : '';
        $lookup_token = isset( $_POST['lookup_record_token'] ) && is_string( $_POST['lookup_record_token'] ) ? wp_unslash( $_POST['lookup_record_token'] ) : '';
        $lookup_result = egov_law_monitor_lookup_square_subscription( $user_id, $lookup_record_id, $lookup_nonce, $lookup_token );
        $lookup_requested_at = current_time( 'mysql' );
    }
    if ( isset( $_POST['egov_square_save'] ) ) {
        check_admin_referer( 'egov_square_save_' . $user_id . '_' . $id, 'egov_square_nonce' );
        foreach ( array( 'square_customer_id', 'square_subscription_id', 'admin_note' ) as $key ) {
            $raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
            $values[ $key ] = $key === 'admin_note' ? sanitize_textarea_field( $raw ) : trim( $raw );
        }
        $form_token = isset( $_POST['record_token'] ) && is_string( $_POST['record_token'] ) ? wp_unslash( $_POST['record_token'] ) : '';
        $message = egov_law_monitor_save_square_subscription( $user_id, $id, $values['square_customer_id'], $values['square_subscription_id'], $values['admin_note'], $form_token );
        if ( ! is_wp_error( $message ) ) {
            $id = (int) $message;
            $editing = true;
            $saved_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id ), ARRAY_A );
            // Never authorize a later overwrite without a freshly read snapshot.
            $form_token = $saved_record ? egov_law_monitor_square_record_token( $saved_record ) : '';
            if ( $saved_record ) {
                $values = $saved_record;
            }
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
        <p>契約登録・API照会だけではStandardになりません。請求書照会後に本人確認して一括登録・承認するか、Dashboardで契約・支払状況を確認した後、対象契約の手動承認を行ってください。確認記録は過去の記録であり、現在のプラン状態とは別です。</p>
        <p>WordPress User ID: <?php echo esc_html( $user_id ); ?> ／ ユーザー名: <?php echo esc_html( $user->user_login ); ?>（<?php echo esc_html( $user->display_name ); ?>） ／ メール: <?php echo esc_html( $user->user_email ); ?></p>
        <p>現在のプラン: <strong><?php echo esc_html( egov_law_monitor_get_plan_label( egov_law_monitor_get_plan( $user_id ) ) ); ?></strong></p>
        <?php egov_law_monitor_render_invoice_lookup( $user_id, $url, $invoice_result ); ?>
        <?php if ( $invoice_approval !== null ) : ?>
            <div class="notice <?php echo is_wp_error( $invoice_approval ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $invoice_approval ) ? $invoice_approval->get_error_message() : $invoice_approval['message'] ); ?></p></div>
        <?php endif; ?>
        <?php egov_law_monitor_render_checkout_attempts( $user_id ); ?>
        <?php if ( $checkout_contract_result !== null ) { egov_law_monitor_render_checkout_contract_result( $user_id, $checkout_contract_result ); } ?>
        <?php if ( $approval_message !== null ) : ?>
            <div class="notice <?php echo is_wp_error( $approval_message ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $approval_message ) ? $approval_message->get_error_message() : 'Standardを手動承認し、契約確認記録と監査履歴を保存しました。' ); ?></p></div>
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
        <?php if ( $message !== null ) : ?>
            <div class="notice <?php echo is_wp_error( $message ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $message ) ? $message->get_error_message() : '契約情報と操作履歴を保存しました。' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $lookup_result !== null ) : ?>
            <h2>契約レコード ID <?php echo esc_html( $lookup_record_id ); ?> のSquare照会結果</h2>
            <p>照会操作日時（サイト時刻）：<?php echo esc_html( $lookup_requested_at ); ?></p>
            <?php if ( is_wp_error( $lookup_result ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $lookup_result->get_error_message() ); ?></p></div>
            <?php else : ?>
                <div class="notice <?php echo $lookup_result['customer_matches'] === true ? 'notice-success' : 'notice-warning'; ?>"><p><?php echo esc_html( $lookup_result['customer_matches'] === true ? 'Customer IDは登録内容と一致しています。' : ( $lookup_result['customer_matches'] === false ? 'Customer IDが一致しません。ユーザーと契約の紐付けを確認してください。' : 'Customer IDが応答にないため、一致を確認できません。' ) ); ?></p></div>
                <table class="widefat striped"><tbody>
                <?php foreach ( array( 'environment' => 'Square環境', 'subscription_id' => 'Subscription ID', 'status' => '契約ステータス', 'registered_customer_id' => '登録済みCustomer ID', 'customer_id' => 'Square応答のCustomer ID', 'plan_variation_id' => 'Square Plan Variation ID', 'plan_id' => 'Square Plan ID（旧形式）', 'queried_at' => '照会日時（サイト時刻）' ) as $key => $label ) : ?>
                    <tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $lookup_result[$key] !== '' ? $lookup_result[$key] : '未取得' ); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <p>契約ステータスは支払済みの判定ではありません。この照会では、プラン名・請求書・決済履歴を取得せず、WordPressのプランと確認済み情報も変更しません。</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $read_error || ! egov_law_monitor_square_storage_ready() ) : ?>
            <div class="notice notice-error"><p>契約・履歴データの読み取り、またはカラム・主キー・インデックス・UNIQUE制約・InnoDBの確認に失敗しました。DBの構成・更新状況を確認してください。</p></div>
        <?php endif; ?>
        <h2>登録済み契約</h2>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>Customer ID</th><th>Subscription ID</th><th>最終確認日時</th><th>確認管理者</th><th>管理者メモ</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ( (array) $contracts as $contract ) : ?>
                <?php $verifier = $contract['verified_by'] ? get_userdata( $contract['verified_by'] ) : false; ?>
                <tr>
                    <td><?php echo esc_html( $contract['id'] ); ?></td>
                    <td><?php echo esc_html( $contract['square_customer_id'] ); ?></td>
                    <td><?php echo esc_html( $contract['square_subscription_id'] ); ?></td>
                    <td><?php echo esc_html( $contract['verified_at'] ?: '未確認' ); ?></td>
                    <td><?php echo esc_html( $contract['verified_by'] ? ( $verifier ? $verifier->display_name : '削除済み管理者' ) . ' (ID: ' . $contract['verified_by'] . ')' : '—' ); ?></td>
                    <td style="white-space:pre-wrap"><?php echo esc_html( $contract['admin_note'] ?? '' ); ?></td>
                    <td><a href="<?php echo esc_url( add_query_arg( 'subscription_id', $contract['id'], $url ) ); ?>">編集</a>
                        <form method="post" action="<?php echo esc_url( $url ); ?>">
                            <?php wp_nonce_field( 'egov_square_lookup_' . $user_id . '_' . $contract['id'], 'egov_square_lookup_nonce' ); ?>
                            <input type="hidden" name="lookup_record_id" value="<?php echo esc_attr( $contract['id'] ); ?>">
                            <input type="hidden" name="lookup_record_token" value="<?php echo esc_attr( egov_law_monitor_square_record_token( $contract ) ); ?>">
                            <button type="submit" name="egov_square_lookup" class="button">Square契約状況を確認</button>
                        </form>
                        <?php $approval_token = egov_law_monitor_square_plan_form_token( $user_id, $contract ); ?>
                        <?php if ( is_wp_error( $approval_token ) ) : ?>
                            <p><?php echo esc_html( $approval_token->get_error_message() ); ?></p>
                        <?php elseif ( egov_law_monitor_get_plan( $user_id ) === 'standard' ) : ?>
                            <p>現在Standardです。再承認はできません。</p>
                        <?php else : ?>
                            <details><summary>この契約でStandardを手動承認</summary>
                            <form method="post" action="<?php echo esc_url( $url ); ?>">
                                <?php wp_nonce_field( 'egov_square_approve_' . $user_id . '_' . $contract['id'], 'egov_square_approve_nonce' ); ?>
                                <input type="hidden" name="approval_record_id" value="<?php echo esc_attr( $contract['id'] ); ?>">
                                <input type="hidden" name="approval_token" value="<?php echo esc_attr( $approval_token ); ?>">
                                <p>対象Subscription ID：<?php echo esc_html( $contract['square_subscription_id'] ); ?></p>
                                <p><label><input type="checkbox" name="dashboard_confirmed" value="1" required>Square Dashboardで、このユーザーの契約・支払状況とCustomer／Subscription IDを確認しました。</label></p>
                                <p><label>確認理由・メモ<textarea name="approval_reason" rows="2" maxlength="2000" required></textarea></label></p>
                                <button type="submit" name="egov_square_approve" class="button">この契約でStandardを承認する</button>
                            </form></details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( ! $contracts && ! $read_error ) : ?><tr><td colspan="7">登録済み契約はありません。</td></tr><?php endif; ?>
            </tbody>
        </table>
        <h2><?php echo $editing ? '契約情報の編集' : '契約情報の登録'; ?></h2>
        <form method="post" action="<?php echo esc_url( $id ? add_query_arg( 'subscription_id', $id, $url ) : $url ); ?>">
            <?php wp_nonce_field( 'egov_square_save_' . $user_id . '_' . $id, 'egov_square_nonce' ); ?>
            <input type="hidden" name="record_token" value="<?php echo esc_attr( $form_token ); ?>">
            <table class="form-table">
                <?php foreach ( array( 'square_customer_id' => 'Square Customer ID', 'square_subscription_id' => 'Square Subscription ID' ) as $key => $label ) : ?>
                    <tr><th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $values[ $key ] ); ?>" maxlength="255" pattern="[A-Za-z0-9_-]+" required></td></tr>
                <?php endforeach; ?>
                <tr><th><label for="admin_note">管理者メモ</label></th><td><textarea class="large-text" rows="4" id="admin_note" name="admin_note"><?php echo esc_textarea( $values['admin_note'] ?? '' ); ?></textarea></td></tr>
            </table>
            <p>IDは半角英数字・ハイフン・アンダースコアで入力してください。確認済み契約のID変更はできません。別契約として新規登録してください。</p>
            <?php submit_button( '契約情報を保存', 'primary', 'egov_square_save' ); ?>
        </form>
        <p><a href="<?php echo esc_url( $url ); ?>">別の契約を新規登録</a></p>
        <h2>契約・承認・プラン変更・申込キャンセルの操作履歴（最新100件）</h2>
        <table class="widefat striped">
            <thead><tr><th>日時</th><th>操作者</th><th>操作</th><th>変更前（JSON）</th><th>変更後（JSON）</th><th>理由</th></tr></thead>
            <tbody>
            <?php foreach ( (array) $logs as $log ) : ?>
                <?php $actor = get_userdata( $log['admin_user_id'] ); ?>
                <tr>
                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    <td><?php echo esc_html( $log['action'] === 'checkout_cancel_requested' && (int) $log['admin_user_id'] === 0 ? '利用者による申請 (ID: ' . $log['user_id'] . ')' : ( $actor ? $actor->display_name : '削除済み管理者' ) . ' (ID: ' . $log['admin_user_id'] . ')' ); ?></td>
                    <td><?php echo esc_html( $log['action'] ); ?></td>
                    <td style="overflow-wrap:anywhere"><?php echo esc_html( $log['old_value'] ?? 'null' ); ?></td>
                    <td style="overflow-wrap:anywhere"><?php echo esc_html( $log['new_value'] ?? 'null' ); ?></td>
                    <td><?php echo esc_html( $log['reason'] ?? '' ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( ! $logs && ! $read_error ) : ?><tr><td colspan="6">操作履歴はありません。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
