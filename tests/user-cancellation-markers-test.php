<?php
/** Read-only batched list marker tests, with existing cancellation service doubles. */
require __DIR__ . '/square-cancellation-test.php';
class MarkerStore extends CancellationStore {
    public $marker_queries = 0, $marker_read_failure = false;
    public function get_results( $query, $mode ) {
        if ( is_array( $query ) && str_contains( $query[0], 'MAX(id)' ) ) {
            ++$this->marker_queries; $this->last_error = $this->marker_read_failure ? 'test-only failure' : '';
            if ( $this->marker_read_failure ) { return null; }
            $rows = array();
            foreach ( $query[1] as $user_id ) {
                $logs = array_values( array_filter( $this->logs, static fn( $l ) => (int) $l['user_id'] === $user_id && in_array( $l['action'], array( 'standard_approved', 'plan_changed' ), true ) ) );
                $latest = $logs ? end( $logs ) : null;
                foreach ( $this->cancellations as $r ) {
                    if ( ! $latest || (int) $r['user_id'] !== $user_id || (int) $r['approval_id'] !== (int) $latest['id'] ) { continue; }
                    $s = $this->contracts[$r['contract_id']] ?? array();
                    $rows[] = $r + array( 'approval_action' => $latest['action'], 'approval_value' => $latest['new_value'], 'contract_user_id' => $s['user_id'] ?? null, 'square_customer_id' => $s['square_customer_id'] ?? null, 'square_subscription_id' => $s['square_subscription_id'] ?? null );
                }
            }
            return $rows;
        }
        return parent::get_results( $query, $mode );
    }
}
function reset_markers() {
    reset_cancellation();
    $old = $GLOBALS['wpdb']; $new = new MarkerStore();
    foreach ( get_object_vars( $old ) as $key => $value ) { $new->$key = $value; }
    $GLOBALS['wpdb'] = $new;
}
function markers() { return egov_law_monitor_user_cancellation_markers( array( (object) array( 'ID' => 10 ), (object) array( 'ID' => 20 ) ) ); }
$marker_start = $checks;
reset_markers(); check( markers() === array(), 'no application adds no marker' );
request_cancellation(); $m = markers(); check( $m[10]['state'] === 'requested' && str_contains( egov_law_monitor_user_cancellation_label( $m[10] ), '解約申請あり' ), 'requested marker without date' );
set_cancel_date( '2026-12-12' );
foreach ( array( '2026-12-11T23:59:59+09:00' => 'scheduled', '2026-12-12T00:00:00+09:00' => 'scheduled', '2026-12-12T23:59:59+09:00' => 'scheduled', '2026-12-13T00:00:00+09:00' => 'due' ) as $cancel_clock => $state ) {
    $before = serialize( array( $wpdb->meta, $wpdb->contracts, $wpdb->logs, $wpdb->cancellations, $cancel_mails ) );
    $queries = $wpdb->marker_queries; $m = markers();
    check( $m[10]['state'] === $state && $m[10]['date'] === '2026年12月12日', 'Tokyo end-date boundary: ' . $cancel_clock );
    check( $wpdb->marker_queries === $queries + 1 && $before === serialize( array( $wpdb->meta, $wpdb->contracts, $wpdb->logs, $wpdb->cancellations, $cancel_mails ) ), 'one aggregate query and no mutations or mail' );
}
check( str_contains( egov_law_monitor_user_cancellation_label( $m[10] ), 'egov-user-free-pending' ) && str_contains( egov_law_monitor_user_cancellation_label( $m[10] ), 'Free変更待ち' ), 'due warning has scoped color class and explicit label' );
$cancel_timezone = 'America/Los_Angeles'; check( markers()[10]['state'] === 'scheduled', 'same instant still end date in site timezone' ); $cancel_timezone = 'Asia/Tokyo';
free_cancellation(); check( markers() === array(), 'formally Free never marked pending' );
change(); check( markers() === array(), 'new approval ignores old cancellation history' );
request_cancellation();
egov_law_monitor_save_cancellation_date( 10, 2, '2026-12-15', 'valid:egov_cancel_date_10_2', egov_law_monitor_cancellation_token( $wpdb->cancellations[2] ), true, 'Dashboard確認', 'cancel_factory' );
check( markers()[10]['state'] === 'scheduled', 'new approval uses only its own request date' );
$wpdb->cancellations[2]['end_date'] = '2026-02-30'; check( markers()[10]['state'] === 'unknown', 'invalid date is not pending Free' );
$wpdb->cancellations[2]['end_date'] = '2026-12-12'; $wpdb->cancellations[2]['contract_id'] = 999;
check( markers()[10]['state'] === 'unknown', 'missing or different contract cannot use date' );
$wpdb->marker_read_failure = true; check( markers()[10]['state'] === 'unknown', 'read failure shows need for confirmation' );
check( ! str_contains( egov_law_monitor_user_cancellation_label( array( 'state' => 'scheduled', 'date' => '<script>' ) ), '<script>' ), 'date output escaped' );
$admin_source = file_get_contents( __DIR__ . '/../wordpress-plugin/includes/admin/admin-users.php' );
check( str_contains( $admin_source, "'cancellation_status' => 'due'" ) && str_contains( $admin_source, '$pending_only' ) && ! str_contains( $admin_source, '<form' ), 'read-only pending filter retains no save form' );
echo ( $checks - $marker_start ) . " user cancellation marker checks passed; no real DB/API used.\n";
