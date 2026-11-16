<?php
/** Actual coordinator + existing cancellation transactions/mail; SQL/Options/WP boundaries doubled. */
function user_can( $user, $cap ) { return false; }
function remove_query_arg( $keys, $url ) { $p = parse_url( $url ); parse_str( $p['query'] ?? '', $q ); foreach ( $keys as $key ) { unset( $q[$key] ); } return $p['scheme'] . '://' . $p['host'] . ( $p['path'] ?? '/' ) . ( $q ? '?' . http_build_query( $q ) : '' ); }
require __DIR__ . '/square-cancellation-test.php';
require __DIR__ . '/../wordpress-plugin/includes/user-withdrawal-request.php';
require_once __DIR__ . '/../wordpress-plugin/includes/user-withdrawal-start.php';

class IntegrationOptions implements ArrayAccess {
    public $values = array(), $failure = '', $writes = 0;
    function offsetExists( $key ): bool { if ( $this->failure === 'read' && str_starts_with( $key, 'egov_law_monitor_withdrawal_request_' ) ) { throw new RuntimeException(); } return isset( $this->values[$key] ); }
    function offsetGet( $key ): mixed { return $this->values[$key] ?? null; }
    function offsetSet( $key, $value ): void {
        if ( str_starts_with( $key, 'egov_law_monitor_withdrawal_request_' ) ) {
            ++$this->writes;
            if ( $this->failure === 'received' && $value['status'] === 'received' ) { return; }
            if ( $this->failure === 'attempt' && ( $value['cancellation_attempt'] ?? '' ) === 'uncertain' ) { return; }
        }
        $this->values[$key] = $value;
    }
    function offsetUnset( $key ): void { unset( $this->values[$key] ); }
}
function add_option( $key, $value, $unused = '', $autoload = false ) {
    $options = $GLOBALS['cancel_options'];
    check( $autoload === false, 'receipt autoload disabled' );
    if ( $options->failure === 'add' || isset( $options[$key] ) ) { return false; }
    $options[$key] = $value; return true;
}
function integration_fixture( $plan = 'standard' ) {
    reset_cancellation(); $GLOBALS['allowed'] = false;
    $GLOBALS['cancel_options'] = new IntegrationOptions(); $GLOBALS['cancel_options']['admin_email'] = 'admin@example.test';
    if ( $plan === 'free' ) { $GLOBALS['wpdb']->meta[1]['meta_value'] = 'free'; }
    $fields = array( 'withdrawal_request_nonce' => 'valid:egov_withdrawal_request_10', 'withdrawal_request_user_id' => '10', 'confirm_withdrawal_request' => '1' );
    if ( $plan === 'standard' ) { $fields += array( 'withdrawal_cancel_nonce' => 'valid:egov_standard_cancel_10', 'withdrawal_cancel_token' => egov_law_monitor_cancellation_context( 10 )['plan_token'] ); }
    return $fields;
}
function integration_submit( $fields ) { return egov_law_monitor_submit_withdrawal_request( $fields, 'cancel_factory' ); }
$integration_start = $checks;
foreach ( array( 'new', 'existing', 'scheduled' ) as $case ) {
    $fields = integration_fixture();
    if ( $case !== 'new' ) { request_cancellation(); }
    if ( $case === 'scheduled' ) { $allowed = true; set_cancel_date( '2026-12-12' ); $allowed = false; }
    $before = $wpdb->cancellations; $plan_before = $wpdb->meta;
    $_GET = array( 'egov_withdrawal_confirm' => '1' ); $_SERVER['REQUEST_URI'] = '/account/?tab=plan';
    $html = egov_law_monitor_withdrawal_request_panel( 10 );
    check( str_contains( $html, 'Standard解約申請も含まれます' ) && str_contains( $html, 'withdrawal_cancel_nonce' ) && str_contains( $html, 'withdrawal_cancel_token' ), 'combined confirmation contains existing nonce/token: ' . $case );
    $result = integration_submit( $fields );
    check( ! is_wp_error( $result ) && $result['status'] === 'received' && $result['approval_id'] === (int) $wpdb->cancellations[1]['approval_id'] && $result['contract_id'] === 1 && $result['cancellation_id'] === 1 && count( $wpdb->cancellations ) === 1, 'combined receipt and exact existing/new cancellation: ' . $case );
    check( $wpdb->meta === $plan_before && ! $withdrawal_records, 'no plan change or withdrawal start' );
    if ( $case === 'scheduled' ) { check( $wpdb->cancellations[1]['end_date'] === $before[1]['end_date'], 'scheduled end date never overwritten' ); }
    $stamp = $result['requested_at']; $logs = count( $wpdb->logs ); integration_submit( $fields );
    check( egov_law_monitor_get_withdrawal_request( 10 )['requested_at'] === $stamp && count( $wpdb->cancellations ) === 1 && count( $wpdb->logs ) === $logs, 'duplicate receipt and cancellation remain single' );
    check( egov_law_monitor_withdrawal_check_save( 10 ) === true, 'pending retirement does not stop ordinary writes' );
}
foreach ( array( false, true ) as $history ) {
    $fields = integration_fixture(); if ( $history ) { request_cancellation(); }
    $allowed = true; free_cancellation(); $allowed = false;
    $before = $wpdb->cancellations; $sessions = count( $cancel_sessions );
    $result = integration_submit( $fields );
    check( ! is_wp_error( $result ) && count( $result ) === 3 && $before === $wpdb->cancellations && count( $cancel_sessions ) === $sessions, 'Free receipt only; historical cancellation untouched' );
}
$fields = integration_fixture(); $cancel_options->failure = 'add'; $sessions = count( $cancel_sessions );
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations && count( $cancel_sessions ) === $sessions, 'option add failure never calls cancellation' );
$fields = integration_fixture(); $cancel_options->failure = 'attempt';
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'attempt marker must persist before cancellation' );
$cancel_options->failure = ''; check( ! is_wp_error( integration_submit( $fields ) ), 'explicit retry after unexecuted attempt works' );
$fields = integration_fixture(); $wpdb->fail = 'cancel_write';
check( is_wp_error( integration_submit( $fields ) ) && egov_law_monitor_get_withdrawal_request( 10 )['status'] === 'pending_cancellation' && ! $wpdb->cancellations, 'cancellation failure leaves visible pending receipt' );
$wpdb->fail = ''; check( ! is_wp_error( integration_submit( $fields ) ) && count( $wpdb->cancellations ) === 1, 'explicit retry of confirmed failure uses existing function' );
$fields = integration_fixture(); $cancel_options->failure = 'received';
check( is_wp_error( integration_submit( $fields ) ) && count( $wpdb->cancellations ) === 1 && egov_law_monitor_get_withdrawal_request( 10 )['status'] === 'pending_cancellation', 'cancellation succeeds but final option save fails' );
$cancel_options->failure = ''; $sessions = count( $cancel_sessions );
check( ! is_wp_error( integration_submit( $fields ) ) && count( $wpdb->cancellations ) === 1 && count( $cancel_sessions ) >= $sessions, 'explicit reconciliation reuses saved cancellation' );
foreach ( array( false, true ) as $applied ) {
    $fields = integration_fixture(); $wpdb->fail = 'commit'; $wpdb->commit_applied = $applied;
    check( is_wp_error( integration_submit( $fields ) ) && egov_law_monitor_get_withdrawal_request( 10 )['status'] === 'pending_cancellation' && egov_law_monitor_get_withdrawal_request( 10 )['cancellation_attempt'] === 'uncertain', 'unknown COMMIT not success, regardless whether committed' );
    $wpdb->fail = ''; $sessions = count( $cancel_sessions ); $result = integration_submit( $fields );
    check( $applied ? ! is_wp_error( $result ) : is_wp_error( $result ), 'explicit reconciliation requires actual matching cancellation row' );
    if ( ! $applied ) { check( count( $cancel_sessions ) === $sessions && ! $wpdb->cancellations, 'uncertain absence never retries mutation' ); }
}
$fields = integration_fixture(); $cancel_options->failure = 'read';
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'option read failure never initiates cancellation' );
$fields = integration_fixture(); $fields['withdrawal_cancel_nonce'] = 'invalid';
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'combined cancellation consent nonce required' );
$fields = integration_fixture(); $wpdb->contracts[1]['square_customer_id'] = 'CHANGED';
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'changed contract identity rejects stale confirmation' );
foreach ( array( 'owner', 'contract' ) as $fault ) {
    $fields = integration_fixture(); request_cancellation();
    if ( $fault === 'owner' ) { $wpdb->cancellations[1]['user_id'] = 20; } else { $wpdb->cancellations[1]['contract_id'] = 2; }
    $before = $wpdb->cancellations;
    check( is_wp_error( integration_submit( $fields ) ) && $wpdb->cancellations === $before, 'mismatching existing cancellation is not reused or changed: ' . $fault );
}
$fields = integration_fixture(); $wpdb->read_fail = 'site_egov_square_cancellations';
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'cancellation read error is not an absent/new request' );
$fields = integration_fixture(); $allowed = true; free_cancellation(); change(); $allowed = false;
check( is_wp_error( integration_submit( $fields ) ) && ! $wpdb->cancellations, 'changed approval rejects old form' );
$fields = integration_fixture(); $cancel_options->failure = 'received'; integration_submit( $fields ); $cancel_options->failure = '';
$allowed = true; free_cancellation(); change(); $allowed = false;
$fields['withdrawal_cancel_token'] = egov_law_monitor_cancellation_context( 10 )['plan_token'];
check( is_wp_error( integration_submit( $fields ) ) && count( $wpdb->cancellations ) === 1, 'pending original approval never silently relinks to a new contract cycle' );
foreach ( array( 'free', 'standard' ) as $plan ) {
    $fields = integration_fixture( $plan ); $old = array( 'user_id' => 10, 'requested_at' => '2026-11-01T00:00:00Z', 'status' => 'received' );
    $cancel_options->values[egov_law_monitor_withdrawal_request_key( 10 )] = $old;
    check( egov_law_monitor_get_withdrawal_request( 10 ) === $old, 'old three-field option reads unchanged' );
    $result = integration_submit( $fields );
    check( ! is_wp_error( $result ) && $result['requested_at'] === $old['requested_at'] && ( $plan === 'free' ? count( $result ) === 3 : $result['cancellation_id'] === 1 ), 'explicit legacy reconciliation preserves original timestamp' );
}
$fields = integration_fixture(); $cancel_mail_fail = true;
check( ! is_wp_error( integration_submit( $fields ) ) && $wpdb->cancellations[1]['admin_notified_at'] === null && egov_law_monitor_get_withdrawal_request( 10 )['status'] === 'received', 'mail failure does not erase or invalidate both receipts' );
$fields = integration_fixture(); $wpdb->fail = 'cancel_write'; integration_submit( $fields ); $wpdb->fail = '';
$_GET = array(); $_SERVER['REQUEST_URI'] = '/account/';
check( str_contains( egov_law_monitor_withdrawal_request_panel( 10 ), '連携は未完了' ) && str_contains( egov_law_monitor_withdrawal_request_panel( 10 ), '再照合' ), 'pending displayed with explicit reconciliation' );
$allowed = true; ob_start(); egov_law_monitor_render_withdrawal_request_receipt( 10 ); $html = ob_get_clean();
check( str_contains( $html, '連携未完了' ), 'administrator sees partial receipt' );
free_cancellation();
check( is_wp_error( egov_law_monitor_withdrawal_request_start_check( 10 ) ), 'manual Free change does not hide unresolved pending receipt' );
$checkout_user = 99;
$start_fields = array( 'withdrawal_nonce' => 'valid:egov_withdrawal_start_10', 'withdrawal_reason' => 'manual checks completed' );
foreach ( egov_law_monitor_withdrawal_start_confirmations() as $key => $label ) { $start_fields['confirm_' . $key] = '1'; }
check( is_wp_error( egov_law_monitor_start_withdrawal( 10, $start_fields ) ) && ! $withdrawal_records, 'actual start handler refuses unresolved pending before transaction' );
$fields = integration_fixture(); integration_submit( $fields ); $allowed = true; set_cancel_date( '2026-11-12' ); free_cancellation();
check( egov_law_monitor_withdrawal_request_start_check( 10 ) === true, 'linked cancellation completed after paid period permits existing start checks' );
unset( $wpdb->cancellations[1] ); check( is_wp_error( egov_law_monitor_withdrawal_request_start_check( 10 ) ), 'lost linked cancellation refuses start' );
echo ( $checks - $integration_start ) . " withdrawal/cancellation integration checks passed; real business functions, simulated WP/SQL/mail only.\n";
