<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EGOV_LAW_MONITOR_META_PLAN', 'egov_law_monitor_plan' );
define( 'EGOV_LAW_MONITOR_META_NOTIFICATIONS', 'egov_law_monitor_notifications' );

define( 'EGOV_LAW_MONITOR_PLAN_FREE', 'free' );
define( 'EGOV_LAW_MONITOR_PLAN_STANDARD', 'standard' );

/**
 * プランごとのウォッチキーワード上限を取得
 */
function egov_law_monitor_get_watch_limit( $user_id ) {
    $plan = egov_law_monitor_get_plan( $user_id );

    $limits = array(
        EGOV_LAW_MONITOR_PLAN_FREE     => 5,
        EGOV_LAW_MONITOR_PLAN_STANDARD => 10,
    );

    return $limits[ $plan ] ?? $limits[ EGOV_LAW_MONITOR_PLAN_FREE ];
}

/**
 * プランごとのAI要約月間利用上限を取得
 */
function egov_law_monitor_get_ai_summary_limit( $user_id ) {
    $plan = egov_law_monitor_get_plan( $user_id );

    $limits = array(
        EGOV_LAW_MONITOR_PLAN_FREE     => 3,
        EGOV_LAW_MONITOR_PLAN_STANDARD => 100,
    );

    return $limits[ $plan ] ?? $limits[ EGOV_LAW_MONITOR_PLAN_FREE ];
}

/**
 * 料金プランを取得
 */
function egov_law_monitor_get_plan( $user_id ) {
    $plan = get_user_meta(
        $user_id,
        EGOV_LAW_MONITOR_META_PLAN,
        true
    );

    if ( ! $plan ) {
        return EGOV_LAW_MONITOR_PLAN_FREE;
    }

    return $plan;
}

/**
 * 料金プランの表示名を取得
 *
 * @param string $plan プラン識別子。
 * @return string
 */
function egov_law_monitor_get_plan_label( $plan ) {

    $labels = array(
        EGOV_LAW_MONITOR_PLAN_FREE     => 'Free',
        EGOV_LAW_MONITOR_PLAN_STANDARD => 'Standard',
    );

    return $labels[ $plan ] ?? $plan;
}

/**
 * 通知設定を取得
 */
function egov_law_monitor_get_notifications( $user_id ) {
    $value = get_user_meta(
        $user_id,
        EGOV_LAW_MONITOR_META_NOTIFICATIONS,
        true
    );

    if ( $value === '' ) {
        return true;
    }

    return (bool) $value;
}

/** Save only the user's global watch-mail preference, never individual watches. */
function egov_law_monitor_set_notifications( $user_id, $enabled ) {
    $value = $enabled ? '1' : '0';
    $saved = update_user_meta( $user_id, EGOV_LAW_MONITOR_META_NOTIFICATIONS, $value );
    if ( $saved === false && get_user_meta( $user_id, EGOV_LAW_MONITOR_META_NOTIFICATIONS, true ) !== $value ) {
        return new WP_Error( 'watch_notifications_save_failed', 'メール通知設定を保存できませんでした。再度お試しください。', array( 'status' => 500 ) );
    }
    return array( 'notifications' => egov_law_monitor_get_notifications( $user_id ) );
}
