<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EGOV_LAW_MONITOR_META_PLAN', 'egov_law_monitor_plan' );
define( 'EGOV_LAW_MONITOR_META_NOTIFICATIONS', 'egov_law_monitor_notifications' );

define( 'EGOV_LAW_MONITOR_PLAN_FREE', 'free' );

/**
 * プランごとのウォッチキーワード上限を取得
 */
function egov_law_monitor_get_watch_limit( $user_id ) {
    $plan = egov_law_monitor_get_plan( $user_id );

    $limits = array(
        EGOV_LAW_MONITOR_PLAN_FREE => 1,
    );

    return $limits[ $plan ] ?? 0;
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