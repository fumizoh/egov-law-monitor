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
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function update_option( ...$args ) { throw new LogicException( 'forbidden option write' ); }
function update_user_meta( ...$args ) { throw new LogicException( 'forbidden meta write' ); }
function wp_safe_remote_get( ...$args ) { throw new LogicException( 'forbidden Square call' ); }
class HistoryStore {
    public $prefix = 'wp_'; public $users = 'wp_users'; public $last_error = ''; public $calls = array(); public $fail = ''; public $empty = false; public $list_rows = array(); public $history_ids = null; public $live_ids = array();
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_results( $query, $mode ) {
        list( $sql, $args ) = $query; $this->calls[] = $query; $this->last_error = '';
        if ( str_contains( $sql, 'deleted_history_users' ) ) {
            if ( $this->fail ) { $this->last_error = 'private DB details'; return null; }
            if ( substr_count( $sql, 'SELECT DISTINCT r.user_id' ) !== 3 || substr_count( $sql, 'NOT EXISTS' ) !== 3 || substr_count( $sql, ' UNION ' ) !== 2 || $args !== array( (int) ( $_GET['deleted_after'] ?? 0 ), 101, (int) ( $_GET['deleted_after'] ?? 0 ), 101, (int) ( $_GET['deleted_after'] ?? 0 ), 101, 101 ) ) { throw new LogicException( 'unbounded or incorrect list query' ); }
            if ( $this->history_ids === null ) { return $this->list_rows; }
            $ids = array_values( array_filter( array_unique( array_merge( ...$this->history_ids ) ), fn( $id ) => $id > $args[0] && ! in_array( $id, $this->live_ids, true ) ) );
            sort( $ids );
            return array_map( static fn( $id ) => array( 'user_id' => $id ), array_slice( $ids, 0, 101 ) );
        }
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
foreach ( array( '削除済みユーザー（ID: 404）', 'CUSTOMER', 'SUBSCRIPTION', '2026-11-14', '2026-11-01 12:00:00', 'Standard承認' ) as $text ) { check_history( str_contains( $html, $text ), 'retained data displayed: ' . $text ); }
check_history( ! str_contains( $html, 'name="user_id"' ), 'detail has no ID search form' );
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
$_GET = array(); check_history( str_contains( render_history(), 'ユーザー管理へ戻る' ) && ! $wpdb->calls, 'entry without ID points to user list without reads' );
$_GET['user_id'] = '404'; $wpdb->empty = true;
check_history( str_contains( render_history(), '保存済みの記録はありません' ), 'no records renders without error' );
$wpdb->empty = false; $wpdb->fail = 'cancellations';
$html = render_history();
check_history( str_contains( $html, '記録を取得できません' ) && ! str_contains( $html, 'private DB details' ) && str_contains( $html, 'SUBSCRIPTION' ), 'read failure distinguished and other records retained' );

function render_deleted_list() { ob_start(); try { egov_law_monitor_render_deleted_user_list(); return ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); throw $e; } }
$_GET = array(); $wpdb->fail = ''; $wpdb->calls = array();
$html = render_deleted_list();
check_history( str_contains( $html, '該当する削除済みユーザーの記録はありません' ) && count( $wpdb->calls ) === 1, 'empty list uses one bounded read' );
$wpdb->list_rows = array( array( 'user_id' => 404 ), array( 'user_id' => 405 ) );
$html = render_deleted_list();
check_history( substr_count( $html, '履歴を表示</a>' ) === 2 && str_contains( $html, 'page=egov-square-subscriptions&amp;user_id=404' ), 'rows link to existing read-only route' );
$query = end( $wpdb->calls )[0];
check_history( ! str_contains( $query, 'UNION ALL' ) && str_contains( $query, 'u.ID = r.user_id' ) && str_contains( $query, 'r.user_id > %d' ), 'SQL deduplicates tables, excludes live users, uses keyset cursor' );
$wpdb->history_ids = array( array( 10, 404 ), array( 404, 405, 10 ), array( 404, 406 ) ); $wpdb->live_ids = array( 10 );
$html = render_deleted_list();
check_history( substr_count( $html, 'user_id=404' ) === 1 && str_contains( $html, 'user_id=405' ) && str_contains( $html, 'user_id=406' ) && ! str_contains( $html, 'user_id=10' ) && ! str_contains( $html, 'user_id=407' ), 'fixture union shows each retained deleted ID once, excludes live and absent IDs' );
$wpdb->history_ids = null;
$wpdb->list_rows = array_map( static fn( $id ) => array( 'user_id' => $id ), range( 1, 101 ) );
$html = render_deleted_list();
check_history( substr_count( $html, '履歴を表示</a>' ) === 100 && str_contains( $html, 'deleted_after=100' ), '100 visible records and bounded next-page link' );
$_GET = array( 'deleted_after' => '100' ); render_deleted_list();
check_history( end( $wpdb->calls )[1][0] === 100, 'next-page cursor parameterized' );
$_GET['deleted_after'] = '-1';
try { render_deleted_list(); throw new LogicException( 'invalid cursor accepted' ); } catch ( RuntimeException $e ) { check_history( str_contains( $e->getMessage(), '不正' ), 'invalid cursor rejected' ); }
$_GET = array(); $allowed = false; $before = count( $wpdb->calls );
check_history( render_deleted_list() === '' && count( $wpdb->calls ) === $before, 'non-admin gets no list or reads' );
$allowed = true; $wpdb->fail = 'all'; $html = render_deleted_list();
check_history( str_contains( $html, '履歴一覧を取得できません' ) && ! str_contains( $html, 'private DB details' ), 'list read failure distinguished from no records' );
echo "{$checks} retained-history checks passed; no real DB, schema changes or HTTP.\n";
