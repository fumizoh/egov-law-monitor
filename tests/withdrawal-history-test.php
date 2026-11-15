<?php
/** Offline retained-history controller tests; every mutation and API is forbidden. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
$allowed = true;
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_userdata( $id ) { return false; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function update_option( ...$args ) { throw new LogicException( 'forbidden option write' ); }
function update_user_meta( ...$args ) { throw new LogicException( 'forbidden meta write' ); }
function wp_safe_remote_get( ...$args ) { throw new LogicException( 'forbidden Square call' ); }
class HistoryStore {
    public $prefix = 'wp_'; public $last_error = ''; public $calls = array(); public $fail = ''; public $empty = false;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_results( $query, $mode ) {
        list( $sql, $args ) = $query; $this->calls[] = $query; $this->last_error = '';
        if ( ! str_starts_with( $sql, 'SELECT * FROM wp_egov_square_' ) || ! str_contains( $sql, 'WHERE user_id = %d' ) || $args !== array( 404 ) ) { throw new LogicException( 'unexpected read' ); }
        if ( $this->fail && str_contains( $sql, $this->fail ) ) { $this->last_error = 'private DB details'; return null; }
        if ( $this->empty ) { return array(); }
        if ( str_contains( $sql, 'admin_log' ) ) { return array( array( 'id' => 3, 'user_id' => 404, 'admin_user_id' => 2, 'action' => 'standard_approved', 'old_value' => '{"plan":"free"}', 'new_value' => '{"plan":"standard"}', 'created_at' => '2026-11-01 12:00:00', 'reason' => '<script>audit</script>' ) ); }
        if ( str_contains( $sql, 'cancellations' ) ) { return array( array( 'id' => 5, 'contract_id' => 7, 'approval_id' => 3, 'requested_at' => '2026-11-02 12:00:00', 'end_date' => '2026-11-14', 'updated_at' => '2026-11-15 12:00:00', 'completed_at' => null ) ); }
        return array( array( 'id' => 7, 'square_customer_id' => 'CUSTOMER', 'square_subscription_id' => 'SUBSCRIPTION', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-15 12:00:00', 'verified_at' => null, 'verified_by' => 2, 'admin_note' => '<script>note</script>' ) );
    }
    public function __call( $method, $args ) { throw new LogicException( 'forbidden DB operation: ' . $method ); }
}
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-square-subscriptions.php';
$checks = 0; $wpdb = new HistoryStore(); $_GET = array( 'user_id' => '404' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
function check_history( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function render_history() { ob_start(); try { egov_law_monitor_render_square_subscriptions(); return ob_get_clean(); } catch ( Throwable $error ) { ob_end_clean(); throw $error; } }
$html = render_history();
foreach ( array( '削除済みユーザー（ID: 404）', 'CUSTOMER', 'SUBSCRIPTION', '2026-11-14', '2026-11-01 12:00:00', 'Standard承認', '履歴を検索' ) as $text ) { check_history( str_contains( $html, $text ), 'retained data displayed: ' . $text ); }
check_history( str_contains( $html, '&lt;script&gt;note' ) && str_contains( $html, '&lt;script&gt;audit' ) && ! str_contains( $html, '<script>' ), 'notes and existing audit escaped' );
check_history( ! str_contains( $html, 'method="post"' ) && ! str_contains( $html, 'invoice_number' ) && ! str_contains( $html, 'egov_square_free' ), 'no write or API forms for deleted user' );
check_history( count( $wpdb->calls ) === 3 && str_contains( $html, '契約状態の専用保存項目はありません' ), 'three parameterized reads; no inferred live contract state' );
foreach ( array( 'egov_square_invoice_lookup', 'egov_square_invoice_approve', 'egov_square_free', 'egov_subscription_cancel_date', 'egov_square_save', 'unexpected' ) as $action ) {
    $wpdb->calls = array(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( $action => '1' );
    try { render_history(); throw new LogicException( 'POST accepted' ); } catch ( RuntimeException $e ) { check_history( str_contains( $e->getMessage(), '閲覧専用' ) && ! $wpdb->calls, 'POST rejected before reads: ' . $action ); }
}
$_POST = array();
try { render_history(); throw new LogicException( 'empty POST accepted' ); } catch ( RuntimeException $e ) { check_history( str_contains( $e->getMessage(), '閲覧専用' ), 'empty POST also rejected' ); }
$_SERVER['REQUEST_METHOD'] = 'GET'; $allowed = false; $wpdb->calls = array();
try { render_history(); throw new LogicException( 'unauthorized view' ); } catch ( RuntimeException $e ) { check_history( ! $wpdb->calls, 'permission denied without data reads' ); }
$allowed = true;
foreach ( array( '-404', '0', '404abc', array( 404 ), '9999999999999999999999999999' ) as $invalid ) {
    $_GET['user_id'] = $invalid;
    try { render_history(); throw new LogicException( 'invalid ID accepted' ); } catch ( RuntimeException $e ) { check_history( str_contains( $e->getMessage(), '有効なユーザーID' ), 'invalid IDs rejected' ); }
}
$_GET = array(); check_history( str_contains( render_history(), '履歴を検索' ) && ! $wpdb->calls, 'search entry without ID needs no DB access' );
$_GET['user_id'] = '404'; $wpdb->empty = true;
check_history( str_contains( render_history(), '保存済みの記録はありません' ), 'no records renders without error' );
$wpdb->empty = false; $wpdb->fail = 'cancellations';
$html = render_history();
check_history( str_contains( $html, '記録を取得できません' ) && ! str_contains( $html, 'private DB details' ) && str_contains( $html, 'SUBSCRIPTION' ), 'read failure distinguished and other records retained' );
echo "{$checks} retained-history checks passed; no real DB, schema changes or HTTP.\n";
