<?php
/** Read-only, batched cancellation markers for the existing user list. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function egov_law_monitor_user_cancellation_markers( $users ) {
    global $wpdb;
    $ids = array();
    foreach ( $users as $user ) { if ( egov_law_monitor_get_plan( $user->ID ) === 'standard' ) { $ids[] = (int) $user->ID; } }
    if ( ! $ids ) { return array(); }
    $markers = array();
    try {
        $prefix = $wpdb->prefix;
        if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $prefix ) ) { throw new RuntimeException(); }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, l.action AS approval_action, l.new_value AS approval_value,
                    s.user_id AS contract_user_id, s.square_customer_id, s.square_subscription_id
             FROM {$prefix}egov_square_admin_log l
             INNER JOIN (SELECT user_id, MAX(id) AS id FROM {$prefix}egov_square_admin_log
                         WHERE action IN ('standard_approved', 'plan_changed') AND user_id IN ({$placeholders}) GROUP BY user_id) latest
                ON l.id = latest.id AND l.user_id = latest.user_id
             INNER JOIN {$prefix}egov_square_cancellations r ON r.approval_id = l.id AND r.user_id = l.user_id
             LEFT JOIN {$prefix}egov_square_subscriptions s ON s.id = r.contract_id",
            ...$ids
        ), ARRAY_A );
        if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) { throw new RuntimeException(); }
        foreach ( $rows as $row ) {
            $id = (int) ( $row['user_id'] ?? 0 );
            if ( ! in_array( $id, $ids, true ) ) { continue; }
            $markers[$id] = array( 'state' => 'unknown', 'date' => '' );
            $data = json_decode( $row['approval_value'] ?? '', true );
            $contract = is_array( $data ) ? ( $data['contract'] ?? null ) : null;
            if ( ( $row['approval_action'] ?? '' ) !== 'standard_approved' || ! is_array( $contract ) ||
                (int) ( $contract['user_id'] ?? 0 ) !== $id || (int) ( $row['contract_user_id'] ?? 0 ) !== $id ||
                (int) ( $contract['id'] ?? 0 ) !== (int) $row['contract_id'] ||
                ( $contract['square_customer_id'] ?? null ) !== $row['square_customer_id'] ||
                ( $contract['square_subscription_id'] ?? null ) !== $row['square_subscription_id'] || $row['completed_at'] !== null ) { continue; }
            $state = egov_law_monitor_cancellation_state( $row, 'standard' );
            if ( ! in_array( $state, array( 'requested', 'scheduled', 'due' ), true ) ) { continue; }
            $markers[$id] = array( 'state' => $state, 'date' => $row['end_date'] === null ? '' : egov_law_monitor_cancellation_date_label( $row['end_date'] ) );
        }
    } catch ( Throwable $error ) {
        foreach ( $ids as $id ) { $markers[$id] = array( 'state' => 'unknown', 'date' => '' ); }
    }
    return $markers;
}

function egov_law_monitor_user_cancellation_label( $marker ) {
    if ( ! $marker ) { return ''; }
    $labels = array( 'requested' => '解約申請あり', 'scheduled' => '解約予定', 'due' => 'Free変更待ち', 'unknown' => '解約状況の確認が必要' );
    $label = $labels[$marker['state']] ?? $labels['unknown'];
    $class = $marker['state'] === 'due' ? 'egov-user-free-pending' : 'egov-user-cancellation';
    return '<div class="' . $class . '"><strong>' . esc_html( $label ) . '</strong>' . ( $marker['date'] !== '' ? '<br>利用終了日：' . esc_html( $marker['date'] ) : '' ) . '</div>';
}
