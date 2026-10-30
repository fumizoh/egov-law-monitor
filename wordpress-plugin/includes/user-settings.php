<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EGOV_LAW_MONITOR_META_PLAN', 'egov_law_monitor_plan' );
define( 'EGOV_LAW_MONITOR_META_NOTIFICATIONS', 'egov_law_monitor_notifications' );

define( 'EGOV_LAW_MONITOR_PLAN_FREE', 'free' );
define( 'EGOV_LAW_MONITOR_PLAN_STANDARD', 'standard' );

/**
 * Get a complete effective-entitlement result from the contract service.
 *
 * Consumers must check `is_wp_error( $result )` before reading any fields.
 * Errors are returned unchanged and are never converted into a Free or
 * Standard plan. Successful results contain effective_plan, watch_limit,
 * ai_summary_limit, ai_summary_period, reason_code, and recheck_required.
 *
 * @param int                 $user_id WordPress user ID.
 * @param DateTimeImmutable|null $as_of Optional fixed decision time.
 * @return array|WP_Error
 */
function egov_law_monitor_get_effective_entitlement( $user_id, DateTimeImmutable $as_of = null ) {
	if ( ! function_exists( 'egov_law_monitor_entitlement_service' ) ) {
		return new WP_Error( 'entitlement_service_unavailable', 'プラン情報を確認できません。' );
	}

	try {
		$result = egov_law_monitor_entitlement_service()->resolve_for_user( $user_id, $as_of );
	} catch ( Throwable $error ) {
		return new WP_Error( 'entitlement_resolution_failed', 'プラン情報を確認できません。' );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$expected = array(
		'free'     => array( 5, 3, 'lifetime' ),
		'standard' => array( 10, 100, 'calendar_month' ),
	);
	if (
		! is_array( $result )
		|| ! isset( $expected[ $result['effective_plan'] ?? '' ] )
		|| ! isset( $result['reason_code'] )
		|| ! is_string( $result['reason_code'] )
		|| '' === $result['reason_code']
		|| ! array_key_exists( 'recheck_required', $result )
		|| ! is_bool( $result['recheck_required'] )
	) {
		return new WP_Error( 'entitlement_result_invalid', 'プラン情報を確認できません。' );
	}

	$limits = $expected[ $result['effective_plan'] ];
	if (
		! isset( $result['watch_limit'], $result['ai_summary_limit'], $result['ai_summary_period'] )
		|| $limits[0] !== $result['watch_limit']
		|| $limits[1] !== $result['ai_summary_limit']
		|| $limits[2] !== $result['ai_summary_period']
	) {
		return new WP_Error( 'entitlement_result_invalid', 'プラン情報を確認できません。' );
	}

	return $result;
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
