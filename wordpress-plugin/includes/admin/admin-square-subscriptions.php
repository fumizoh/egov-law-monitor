<?php
/** Administrator-only manual contract registration; never changes plans. */
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
    if ( isset( $_POST['egov_square_lookup'], $_POST['egov_square_save'] ) ) {
        wp_die( '契約保存とSquare照会は別々に操作してください。' );
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
        <p>契約情報の登録はStandardの承認やSquareでの支払確認を意味しません。この画面ではプランと確認済み情報を変更しません。</p>
        <p>WordPress User ID: <?php echo esc_html( $user_id ); ?> ／ ユーザー名: <?php echo esc_html( $user->user_login ); ?>（<?php echo esc_html( $user->display_name ); ?>） ／ メール: <?php echo esc_html( $user->user_email ); ?></p>
        <p>現在のプラン: <strong><?php echo esc_html( egov_law_monitor_get_plan_label( egov_law_monitor_get_plan( $user_id ) ) ); ?></strong></p>
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
            <p>IDは半角英数字・ハイフン・アンダースコアで入力してください。</p>
            <?php submit_button( '契約情報を保存', 'primary', 'egov_square_save' ); ?>
        </form>
        <p><a href="<?php echo esc_url( $url ); ?>">別の契約を新規登録</a></p>
        <h2>登録・更新の操作履歴（最新100件）</h2>
        <table class="widefat striped">
            <thead><tr><th>日時</th><th>管理者</th><th>操作</th><th>変更前（JSON）</th><th>変更後（JSON）</th><th>理由</th></tr></thead>
            <tbody>
            <?php foreach ( (array) $logs as $log ) : ?>
                <?php $actor = get_userdata( $log['admin_user_id'] ); ?>
                <tr>
                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    <td><?php echo esc_html( ( $actor ? $actor->display_name : '削除済み管理者' ) . ' (ID: ' . $log['admin_user_id'] . ')' ); ?></td>
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
