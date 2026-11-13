<?php
/** Display-only audit presentation. Never writes or repairs stored history. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_square_audit_label( $action ) {
    $labels = array(
        'standard_approved' => 'Standard承認',
        'contract_environment_confirmed' => '契約環境確認',
        'plan_changed' => 'プラン変更',
        'subscription_created' => '契約登録',
        'subscription_updated' => '契約更新',
        'checkout_cancel_requested' => '申込キャンセル申請',
        'checkout_cancel_completed' => '申込キャンセル完了',
        'subscription_cancellation_requested' => 'Standard解約申請',
        'subscription_end_date_set' => '利用終了日登録',
        'subscription_end_date_changed' => '利用終了日変更',
        'subscription_cancellation_completed' => 'Standard利用終了・Free変更',
    );
    return $labels[$action] ?? $action;
}

/** Preserve object/array distinctions; invalid JSON is displayed as escaped source. */
function egov_law_monitor_square_audit_json( $raw ) {
    $raw = $raw === null ? 'null' : (string) $raw;
    $value = json_decode( $raw, false, 512, JSON_BIGINT_AS_STRING );
    if ( json_last_error() !== JSON_ERROR_NONE ) { return array( 'data' => null, 'text' => $raw, 'valid' => false ); }
    $text = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
    return array( 'data' => is_object( $value ) ? get_object_vars( $value ) : null, 'text' => $text === false ? $raw : $text, 'valid' => $text !== false );
}

/** Only summarize recognized actions with explicit, supported values. */
function egov_law_monitor_square_audit_summary( $action, $old, $new ) {
    $before = $old['data']; $after = $new['data'];
    $plans = array( 'free' => 'Free', 'standard' => 'Standard' );
    if ( in_array( $action, array( 'standard_approved', 'plan_changed', 'subscription_cancellation_completed' ), true ) && is_array( $before ) && is_array( $after ) && is_string( $before['plan'] ?? null ) && is_string( $after['plan'] ?? null ) && isset( $plans[$before['plan']], $plans[$after['plan']] ) ) {
        return $plans[$before['plan']] . ' → ' . $plans[$after['plan']];
    }
    if ( in_array( $action, array( 'subscription_end_date_set', 'subscription_end_date_changed' ), true ) && is_array( $before ) && is_array( $after ) && array_key_exists( 'end_date', $before ) && is_string( $after['end_date'] ?? null ) && preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $after['end_date'] ) && ( $before['end_date'] === null || ( is_string( $before['end_date'] ) && preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $before['end_date'] ) ) ) ) { return ( $before['end_date'] ?? '終了日未登録' ) . ' → ' . $after['end_date']; }
    if ( $action === 'contract_environment_confirmed' && is_array( $before ) && is_array( $after ) && array_key_exists( 'environment', $before ) && in_array( $before['environment'], array( null, 'sandbox', 'production' ), true ) && in_array( $after['environment'] ?? null, array( 'sandbox', 'production' ), true ) ) {
        return ( $before['environment'] ?? '環境未登録' ) . ' → ' . $after['environment'];
    }
    if ( $action === 'subscription_created' && is_array( $after ) && is_string( $after['square_subscription_id'] ?? null ) && $after['square_subscription_id'] !== '' ) {
        return 'Subscription ID: ' . $after['square_subscription_id'];
    }
    if ( $action === 'subscription_updated' && is_array( $before ) && is_array( $after ) ) {
        foreach ( array( 'square_subscription_id' => 'Subscription ID', 'square_customer_id' => 'Customer ID' ) as $key => $label ) {
            if ( is_string( $before[$key] ?? null ) && is_string( $after[$key] ?? null ) && $before[$key] !== $after[$key] ) { return $label . ': ' . $before[$key] . ' → ' . $after[$key]; }
        }
    }
    $states = array( 'ready' => 'リンク発行済み', 'cancel_requested' => 'キャンセル申請中', 'closed' => 'キャンセル完了' );
    if ( in_array( $action, array( 'checkout_cancel_requested', 'checkout_cancel_completed' ), true ) && is_array( $before ) && is_array( $after ) && is_string( $before['state'] ?? null ) && is_string( $after['state'] ?? null ) && isset( $states[$before['state']], $states[$after['state']] ) ) {
        return $states[$before['state']] . ' → ' . $states[$after['state']];
    }
    return egov_law_monitor_square_audit_label( $action );
}

function egov_law_monitor_render_square_audit( $logs, $read_error ) {
    ?>
    <style>
        .egov-square-audit {table-layout:fixed;width:100%}
        .egov-square-audit th,.egov-square-audit td {overflow-wrap:anywhere;vertical-align:top}
        .egov-square-audit .egov-audit-actor {margin-top:.4em;color:#50575e}
        .egov-square-audit .egov-audit-reason {white-space:pre-wrap}
        .egov-square-audit summary {cursor:pointer}
        .egov-square-audit pre {white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;max-height:24em;overflow:auto;max-width:100%;margin:.5em 0 1em;padding:.5em;background:#f6f7f7}
        @media (max-width:782px) {
            .egov-square-audit,.egov-square-audit tbody,.egov-square-audit tr,.egov-square-audit td {display:block;width:auto}
            .egov-square-audit thead {display:none}
            .egov-square-audit tr {border-bottom:1px solid #c3c4c7;padding:.5em 0}
            .egov-square-audit td::before {content:attr(data-label);display:block;font-weight:600;margin-bottom:.25em}
        }
    </style>
    <table class="widefat striped egov-square-audit">
        <thead><tr><th>日時</th><th>操作</th><th>変更内容</th><th>理由</th><th>詳細</th></tr></thead>
        <tbody>
        <?php foreach ( (array) $logs as $log ) : ?>
            <?php
            $action = (string) ( $log['action'] ?? '' );
            $actor_id = (int) ( $log['admin_user_id'] ?? 0 );
            $actor = $actor_id > 0 ? get_userdata( $actor_id ) : false;
            $actor_label = in_array( $action, array( 'checkout_cancel_requested', 'subscription_cancellation_requested' ), true ) && $actor_id === 0
                ? '利用者による申請 (ID: ' . (int) $log['user_id'] . ')'
                : ( $actor ? $actor->display_name : '削除済み管理者' ) . ' (ID: ' . $actor_id . ')';
            if ( $action === 'subscription_cancellation_completed' && $actor_id === 0 ) { $actor_label = 'システム（Free状態の確認）'; }
            $old = egov_law_monitor_square_audit_json( $log['old_value'] ?? null );
            $new = egov_law_monitor_square_audit_json( $log['new_value'] ?? null );
            ?>
            <tr>
                <td data-label="日時"><?php echo esc_html( $log['created_at'] ); ?><div class="egov-audit-actor">操作者：<?php echo esc_html( $actor_label ); ?></div></td>
                <td data-label="操作"><?php echo esc_html( egov_law_monitor_square_audit_label( $action ) ); ?></td>
                <td data-label="変更内容"><?php echo esc_html( egov_law_monitor_square_audit_summary( $action, $old, $new ) ); ?></td>
                <td data-label="理由" class="egov-audit-reason"><?php echo esc_html( $log['reason'] ?? '' ); ?></td>
                <td data-label="詳細"><details><summary>詳細を表示</summary><p>操作コード：<?php echo esc_html( $action ); ?></p>
                    <?php foreach ( array( '変更前（JSON）' => $old, '変更後（JSON）' => $new ) as $label => $json ) : ?>
                        <strong><?php echo esc_html( $label ); ?></strong>
                        <?php if ( ! $json['valid'] ) : ?><p>保存済みJSONが不正なため、原文を表示します。</p><?php endif; ?>
                        <pre><?php echo esc_html( $json['text'] ); ?></pre>
                    <?php endforeach; ?>
                </details></td>
            </tr>
        <?php endforeach; ?>
        <?php if ( ! $logs && ! $read_error ) : ?><tr><td colspan="5">操作履歴はありません。</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php
}
