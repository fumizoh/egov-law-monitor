<?php
/** Offline preflight: only SELECT and user reads permitted. */
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
function is_multisite() { return false; }
function get_option( $key, $default = false ) { return $default; }
function add_action( ...$args ) { $GLOBALS['preflight_actions'][] = $args; }
function add_filter( ...$args ) {}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme ) { return 'test-salt'; } }
require __DIR__ . '/../wordpress-plugin/includes/user-withdrawal-policy.php';
require __DIR__ . '/withdrawal-test-bootstrap.php';
$allowed = true; $actor = 99; $caps = array();
$person = (object) array( 'ID' => 10, 'roles' => array( 'subscriber' ), 'user_login' => 'member', 'user_email' => 'member@example.test', 'user_registered' => '2026-11-01 00:00:00' );
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_current_user_id() { return $GLOBALS['actor']; }
function user_can( $user, $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_userdata( $id ) { return $id === 10 && empty( $GLOBALS['withdrawal_test_user_deleted'] ) ? $GLOBALS['person'] : false; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="test">'; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function update_user_meta( ...$args ) { throw new LogicException( 'forbidden write' ); }
function update_option( ...$args ) { throw new LogicException( 'forbidden write' ); }
function wp_safe_remote_get( ...$args ) { throw new LogicException( 'forbidden HTTP' ); }
class PreflightStore {
    public $prefix = 'wp_', $users = 'wp_users', $usermeta = 'wp_usermeta', $posts = 'wp_posts', $comments = 'wp_comments', $links = 'wp_links', $last_error = '';
    public $queries = array(), $meta = array(), $fail = '', $legacy_exists = false, $contracts = array(), $cancellations = array(), $posts_rows = array();
    public $pending = '0', $watch_total = '2', $active = '1', $stopped = '1', $ai = '4', $comments_total = '0', $links_total = '0';
    public $legacy_rows = array( array( 'id' => 6, 'state' => 'unknown', 'created_at' => '2026-11-01' ) );
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_results( $query, $mode ) {
        if ( withdrawal_test_query( $query ) ) {
            list( $sql, $args ) = is_array( $query ) ? $query : array( $query, array() );
            $this->queries[] = array( $sql, $args ); $this->last_error = '';
            return ( new WithdrawalReadStore() )->rows( $sql, $args );
        }
        list( $sql, $args ) = $query; $this->queries[] = $query; $this->last_error = '';
        if ( ! str_starts_with( $sql, 'SELECT ' ) ) { throw new LogicException( 'non-SELECT' ); }
        if ( $this->fail && str_contains( $sql, $this->fail ) ) { $this->last_error = 'private error'; return null; }
        if ( str_contains( $sql, 'information_schema' ) ) { return $this->legacy_exists ? array( array( 'TABLE_NAME' => 'wp_egov_square_checkout_attempts' ) ) : array(); }
        if ( $args[0] !== 10 ) { throw new LogicException( 'wrong owner' ); }
        if ( str_contains( $sql, 'wp_usermeta' ) ) { return str_contains( $sql, 'COUNT(*)' ) ? array( array( 'total' => (string) count( array_filter( $this->meta, static fn( $r ) => $r['meta_key'] === 'egov_law_monitor_notifications' ) ) ) ) : $this->meta; }
        if ( str_contains( $sql, 'egov_square_subscriptions' ) ) { return $this->contracts; }
        if ( str_contains( $sql, 'egov_square_cancellations' ) ) { return str_contains( $sql, 'COUNT' ) ? array( array( 'total' => $this->pending ) ) : $this->cancellations; }
        if ( str_contains( $sql, 'law_watch_settings' ) ) { return array( array( 'total' => $this->watch_total, 'active' => $this->active, 'stopped' => $this->stopped ) ); }
        if ( str_contains( $sql, 'egov_law_ai_summary_history' ) ) { return array( array( 'total' => $this->ai ) ); }
        if ( str_contains( $sql, 'wp_posts' ) ) { return $this->posts_rows; }
        if ( str_contains( $sql, 'wp_comments' ) ) { return array( array( 'total' => $this->comments_total ) ); }
        if ( str_contains( $sql, 'wp_links' ) ) { return array( array( 'total' => $this->links_total ) ); }
        if ( str_contains( $sql, 'checkout_attempts' ) ) { return $this->legacy_rows; }
        throw new LogicException( 'unexpected read' );
    }
    public function __call( $name, $args ) { throw new LogicException( 'forbidden DB operation' ); }
}
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-user-withdrawal-check.php';
$checks = 0; $wpdb = new PreflightStore(); $_GET = array( 'user_id' => '10' ); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
function preflight_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function render_preflight() { ob_start(); try { egov_law_monitor_render_withdrawal_check(); return ob_get_clean(); } catch ( Throwable $error ) { ob_end_clean(); throw $error; } }
$html = render_preflight();
foreach ( array( 'member@example.test', 'Free', '対象テーブルなし', 'キーワード総数', '有効中', '停止中', 'AI個人利用履歴', 'メール通知設定：ON', 'Freeお試し利用回数：0', '記録なし', 'Square側の契約・課金状態を保証するものではありません' ) as $text ) { preflight_check( str_contains( $html, $text ), 'Free preflight: ' . $text ); }
preflight_check( str_contains( $html, 'method="post"' ) && str_contains( $html, 'name="withdrawal_nonce"' ), 'no mutation controls' );
preflight_check( str_contains( $html, '<h1>退会管理</h1>' ) && str_contains( $html, '退会状態：未開始' ), 'management title and unstarted state' );
$position = -1;
foreach ( array( '退会申請・本人確認', 'Square契約終了・課金停止・利用終了日の確認', '退会前チェック', '退会処理開始', '法令ウォッチの削除', 'AI要約の個人履歴削除', 'WordPressユーザー削除', '残存データ確認・退会完了' ) as $index => $title ) {
    $next = strpos( $html, '<h2>' . ( $index + 1 ) . '. ' . $title . '</h2>' );
    preflight_check( $next !== false && $next > $position, 'ordered step ' . ( $index + 1 ) ); $position = $next;
}
preflight_check( ! str_contains( $html, '>退会完了</button>' ), 'completion unavailable before deletion' );
foreach ( array( 'started' => '処理開始', 'cleaned' => '旧段階・個別結果は未確認', 'deleting' => '削除済みとは断定しません', 'completed' => '完了（保存記録）' ) as $phase => $label ) {
    $withdrawal_records[10] = array( 'user_id' => 10, 'process_uuid' => '10000000-0000-4000-8000-000000000000', 'phase' => $phase, 'revision' => 2 );
    $state_html = render_preflight();
    preflight_check( str_contains( $state_html, $label ) && ( $phase === 'started' ? str_contains( $state_html, '法令ウォッチを削除</button>' ) : ! str_contains( $state_html, '<button' ) ), 'available actions by phase: ' . $phase );
}
reset_withdrawal_reads(); $withdrawal_read_failure = 'read';
preflight_check( str_contains( render_preflight(), '読み取りエラー：退会状態を確認できません' ), 'state read failure is not unstarted' );
reset_withdrawal_reads(); $withdrawal_records[10] = array( 'user_id' => 10, 'process_uuid' => '10000000-0000-4000-8000-000000000000', 'phase' => 'invalid', 'revision' => 1 );
preflight_check( str_contains( render_preflight(), '読み取りエラー' ), 'invalid phase is not success' );
reset_withdrawal_reads();
$data = egov_law_monitor_withdrawal_check_data( 10 );
preflight_check( $data['watches'][0] === array( 'total' => '2', 'active' => '1', 'stopped' => '1' ) && $data['ai'][0]['total'] === '4', 'counts preserved without entitlement helper' );
$wpdb->meta = array( array( 'meta_key' => 'egov_law_monitor_plan', 'meta_value' => 'standard' ), array( 'meta_key' => 'egov_law_monitor_notifications', 'meta_value' => '0' ), array( 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage', 'meta_value' => '2' ), array( 'meta_key' => 'egov_law_monitor_standard_consent', 'meta_value' => serialize( array( 'agreed_at' => '2026-11-14T00:00:00Z', 'terms_version' => '2026-11-14', 'conditions_version' => '2026-11-14' ) ) ) );
$wpdb->contracts = array( array( 'id' => 1, 'square_customer_id' => 'CUSTOMER', 'square_subscription_id' => 'SUB', 'admin_note' => '<script>note</script>' ) );
$wpdb->pending = '1'; $wpdb->cancellations = array( array( 'id' => 2, 'requested_at' => '2026-11-15', 'end_date' => '2026-12-12', 'completed_at' => null ) );
$wpdb->legacy_exists = true; $wpdb->posts_rows = array( array( 'post_type' => 'attachment', 'total' => '3' ) );
$html = render_preflight();
foreach ( array( 'Standard', 'CUSTOMER', 'SUB', '未完了申請件数：1', '2026-12-12', 'unknown', '2026-11-14T00:00:00Z', 'メール通知設定：OFF', 'Freeお試し利用回数：2', 'attachment' ) as $text ) { preflight_check( str_contains( $html, $text ), 'Standard detail: ' . $text ); }
preflight_check( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'stored text escaped' );
foreach ( array( 'wp_usermeta', 'information_schema', 'law_watch_settings', 'egov_square_subscriptions', 'egov_square_cancellations', 'egov_law_ai_summary_history', 'wp_posts', 'wp_comments' ) as $failure ) {
    $wpdb->fail = $failure; $html = render_preflight();
    preflight_check( str_contains( $html, '読み取りエラー' ) && ! str_contains( $html, 'private error' ), 'read error distinguished: ' . $failure );
}
$wpdb->fail = ''; $wpdb->ai = null;
preflight_check( str_contains( render_preflight(), '読み取りエラー' ), 'invalid aggregate is not zero' ); $wpdb->ai = '4';
$wpdb->meta[] = array( 'meta_key' => '_application_passwords', 'meta_value' => 'privatehash' );
$app_html = render_preflight(); preflight_check( str_contains( $app_html, '用途' ) && ! str_contains( $app_html, 'privatehash' ), 'application password requires review without excluding subscriber or exposing secrets' );
array_pop( $wpdb->meta );
foreach ( array( 'manage_options', 'edit_users', 'edit_posts', 'law_watch_internal_api' ) as $cap ) {
    $caps = array( $cap ); preflight_check( ! egov_law_monitor_withdrawal_check_target( $person ), 'protected capability: ' . $cap );
    try { render_preflight(); throw new LogicException( 'protected screen shown' ); } catch ( RuntimeException $error ) { preflight_check( str_contains( $error->getMessage(), '対象外' ), 'protected account cannot see workflow' ); }
}
$caps = array(); $actor = 10; preflight_check( ! egov_law_monitor_withdrawal_check_target( $person ), 'administrator self excluded' ); $actor = 99;
$person->user_login = 'law_watch_api'; preflight_check( ! egov_law_monitor_withdrawal_check_target( $person ), 'known API login protected' ); $person->user_login = 'member';
$person->roles = array( 'administrator' ); preflight_check( ! egov_law_monitor_withdrawal_check_target( $person ), 'non-member role protected' ); $person->roles = array( 'subscriber' );
foreach ( array( '-1', '0', '10evil', array( '10' ), '99999999999999999999999' ) as $invalid ) {
    $_GET['user_id'] = $invalid;
    try { render_preflight(); throw new LogicException( 'bad ID accepted' ); } catch ( RuntimeException $e ) { preflight_check( str_contains( $e->getMessage(), '有効なユーザーID' ), 'invalid ID rejected' ); }
}
$_GET['user_id'] = '404';
try { render_preflight(); throw new LogicException( 'missing user accepted' ); } catch ( RuntimeException $e ) { preflight_check( str_contains( $e->getMessage(), '存在しません' ), 'missing user rejected' ); }
$_GET['user_id'] = '10'; $allowed = false;
try { render_preflight(); throw new LogicException( 'unauthorized accepted' ); } catch ( RuntimeException $e ) { preflight_check( str_contains( $e->getMessage(), '権限' ), 'permission denied' ); }
$allowed = true; $_SERVER['REQUEST_METHOD'] = 'POST';
try { render_preflight(); throw new LogicException( 'POST accepted' ); } catch ( RuntimeException $e ) { preflight_check( str_contains( $e->getMessage(), '閲覧専用' ), 'all POST rejected' ); }
echo "{$checks} preflight checks passed; no DB writes, schema changes, mail or HTTP.\n";
