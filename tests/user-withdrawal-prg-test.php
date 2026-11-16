<?php
/** Actual load handler, seven business operations and GET renderer; offline WP/DB boundaries. */
require __DIR__ . '/user-withdrawal-recovery-test.php';
class WithdrawalPRGRedirect extends RuntimeException {
    public $url; public $status;
    function __construct( $url, $status ) { $this->url = $url; $this->status = $status; }
}
$prg_notices = array(); $prg_session = 'admin-session'; $prg_storage_failure = false;
function wp_get_session_token() { return $GLOBALS['prg_session']; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function set_transient( $key, $value, $ttl ) {
    preflight_check( $ttl === 300, 'bounded result lifetime' );
    if ( $GLOBALS['prg_storage_failure'] ) { throw new RuntimeException( 'private storage error' ); }
    $GLOBALS['prg_notices'][$key] = $value; return true;
}
function get_transient( $key ) { return $GLOBALS['prg_notices'][$key] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['prg_notices'][$key] ); return true; }
function wp_safe_redirect( $url, $status ) { throw new WithdrawalPRGRedirect( $url, $status ); }
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ) { return 'admin_page_' . $slug; }
function add_menu_page( ...$args ) {}
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-menu.php';
egov_law_monitor_admin_menu();
preflight_check( in_array( array( 'load-admin_page_egov-user-withdrawal-check', 'egov_law_monitor_withdrawal_handle_post' ), $preflight_actions, true ), 'POST handler registered on actual returned page hook' );

function withdrawal_prg_submit( $action, $fields, $db ) {
    $_GET = array( 'page' => 'egov-user-withdrawal-check', 'user_id' => '10' );
    $_POST = $fields + array( 'withdrawal_action' => $action, 'user_id' => '20', 'redirect_to' => 'https://attacker.test/' );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    try { egov_law_monitor_withdrawal_handle_post( fn() => $db ); throw new LogicException( 'missing PRG redirect' ); }
    catch ( WithdrawalPRGRedirect $redirect ) { $output = ob_get_clean(); }
    catch ( Throwable $error ) { ob_end_clean(); throw $error; }
    preflight_check( $output === '' && $redirect->status === 303, '303 before HTML: ' . ( is_string( $action ) ? $action : 'invalid action' ) );
    $parts = parse_url( $redirect->url ); parse_str( $parts['query'], $query );
    preflight_check( $parts['host'] === 'example.test' && $query['page'] === 'egov-user-withdrawal-check' && $query['user_id'] === '10' && count( $query ) === 3 && preg_match( '/\A[a-f0-9]{32}\z/', $query['withdrawal_result'] ), 'canonical target and opaque receipt, no POST redirect/PII/nonce in URL' );
    $_GET = $query; $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
    return $query;
}
function withdrawal_prg_get( $expected, $success ) {
    global $user_db, $withdrawal_records, $user_deletion_calls;
    $before = array( $withdrawal_records, $user_db->audit_rows, $user_deletion_calls, $user_db->watches, $user_db->history );
    $html = render_preflight();
    preflight_check( str_contains( $html, $expected ) && str_contains( $html, $success ? 'notice-success' : 'notice-error' ), 'server-side GET result accurate: ' . $expected );
    $reload = render_preflight();
    preflight_check( str_contains( $reload, '表示期限切れ、表示済み' ) && ! str_contains( $reload, 'notice-success' ), 'GET reload does not replay success notice' );
    egov_law_monitor_withdrawal_handle_post();
    preflight_check( $before === array( $withdrawal_records, $user_db->audit_rows, $user_deletion_calls, $user_db->watches, $user_db->history ), 'GET rendering/reload/load handler never replays a mutation' );
}

$db = withdrawal_flow_fixture();
$start = array( 'withdrawal_nonce' => 'valid:egov_withdrawal_start_10', 'withdrawal_reason' => 'Square confirmed' );
foreach ( egov_law_monitor_withdrawal_start_confirmations() as $key => $label ) { $start['confirm_' . $key] = '1'; }
withdrawal_prg_submit( 'start', $start, $db ); withdrawal_prg_get( '退会処理を開始しました', true );
$logs = $db->audit_rows;
withdrawal_prg_submit( 'start', $start, $db ); withdrawal_prg_get( '既に退会処理が開始', false );
preflight_check( $logs === $db->audit_rows, 'explicit repeated start does not duplicate audit' );

$state = $withdrawal_records[10];
$watch = array( 'watch_nonce' => 'valid:egov_withdrawal_watches_10', 'confirm_watch_delete' => '1', 'watch_token' => egov_law_monitor_withdrawal_watch_token( 10, $state, egov_law_monitor_withdrawal_watch_snapshot( 10, $state, $db ) ) );
withdrawal_prg_submit( 'watches', $watch, $db ); withdrawal_prg_get( '法令ウォッチ・通知設定の削除', true );
$logs = $db->audit_rows; withdrawal_prg_submit( 'watches', $watch, $db ); withdrawal_prg_get( '件数または処理状態が変わりました', false );
preflight_check( $logs === $db->audit_rows, 'stale watch POST remains rejected' );
$state = $withdrawal_records[10];
$ai = array( 'ai_nonce' => 'valid:egov_withdrawal_ai_10', 'confirm_ai_delete' => '1', 'ai_token' => egov_law_monitor_withdrawal_ai_token( 10, $state, egov_law_monitor_withdrawal_ai_snapshot( 10, $state, $db ) ) );
withdrawal_prg_submit( 'ai', $ai, $db ); withdrawal_prg_get( 'AI個人履歴・Free試用回数の削除', true );
$logs = $db->audit_rows; withdrawal_prg_submit( 'ai', $ai, $db ); withdrawal_prg_get( '件数または処理状態が変わりました', false );
preflight_check( $logs === $db->audit_rows, 'stale AI POST remains rejected' );
$state = $withdrawal_records[10];
$user = array( 'user_nonce' => 'valid:egov_withdrawal_user_10', 'confirm_user_delete' => '1', 'user_token' => egov_law_monitor_withdrawal_user_token( 10, $state, egov_law_monitor_withdrawal_user_evidence( 10, $state, $db ) ) );
withdrawal_prg_submit( 'user', $user, $db ); withdrawal_prg_get( 'WordPressユーザー削除と結果監査', true );
preflight_check( $withdrawal_test_user_deleted && $user_deletion_calls === 1, 'redirected GET continues for deleted user' );
$logs = $db->audit_rows; withdrawal_prg_submit( 'user', $user, $db ); withdrawal_prg_get( '削除済みユーザーは削除できません', false );
preflight_check( $logs === $db->audit_rows && $user_deletion_calls === 1, 'old user deletion form cannot delete again' );
$state = $withdrawal_records[10]; $report = egov_law_monitor_withdrawal_completion_snapshot( 10, $state, $db );
$complete = array( 'complete_nonce' => 'valid:egov_withdrawal_complete_10', 'confirm_complete' => '1', 'complete_token' => egov_law_monitor_withdrawal_completion_token( 10, $state, $report ) );
withdrawal_prg_submit( 'complete', $complete, $db ); withdrawal_prg_get( '退会完了を記録しました', true );
$logs = $db->audit_rows; withdrawal_prg_submit( 'complete', $complete, $db ); withdrawal_prg_get( '完了済みは再実行できません', false );
preflight_check( $logs === $db->audit_rows, 'completed POST does not duplicate audit' );

list( $db, $fields ) = recovery_fixture();
withdrawal_prg_submit( 'reclean', $fields, $db ); withdrawal_prg_get( '削除後の残存データを再清掃しました', true );
$logs = $db->audit_rows; withdrawal_prg_submit( 'reclean', $fields, $db ); withdrawal_prg_get( 'revisionまたは残存情報が変更', false );
preflight_check( $logs === $db->audit_rows, 'stale reclean POST remains rejected' );
list( $db, $fields ) = user_delete_fixture(); $user_delete_mode = 'false';
egov_law_monitor_delete_withdrawal_user( 10, $fields, fn() => $db );
$state = $withdrawal_records[10]; $evidence = egov_law_monitor_withdrawal_user_recovery_evidence( 10, $state, $db );
$rearm = array( 'rearm_nonce' => 'valid:egov_withdrawal_rearm_10', 'confirm_rearm' => '1', 'rearm_token' => egov_law_monitor_withdrawal_user_token( 10, $state, $evidence ) );
withdrawal_prg_submit( 'user_rearm', $rearm, $db ); withdrawal_prg_get( '削除失敗を再確認してstartedへ戻しました', true );
preflight_check( $user_deletion_calls === 1 && ! $withdrawal_test_user_deleted, 'rearm PRG never calls deletion again' );
$logs = $db->audit_rows; withdrawal_prg_submit( 'user_rearm', $rearm, $db ); withdrawal_prg_get( '削除再準備の対象ではありません', false );
preflight_check( $logs === $db->audit_rows, 'stale rearm POST remains rejected' );

list( $db, $fields ) = user_delete_fixture(); $db->failure = 'result_audit';
withdrawal_prg_submit( 'user', $fields, $db ); $db->failure = '';
withdrawal_prg_get( '削除結果の記録を確定できません', false );
preflight_check( $withdrawal_test_user_deleted && $user_deletion_calls === 1, 'result-record failure is not a success flash even though user is absent' );
list( $db, $fields ) = completion_fixture(); $db->failure = 'commit';
withdrawal_prg_submit( 'complete', $fields, $db ); $db->failure = '';
withdrawal_prg_get( '退会完了の確定・取消結果が不明', false );

$db = withdrawal_flow_fixture(); $bad = $start; $bad['withdrawal_nonce'] = 'bad';
withdrawal_prg_submit( 'start', $bad, $db ); withdrawal_prg_get( '操作を確認できません', false );
foreach ( array( 'unknown', array( 'start' ) ) as $action ) { withdrawal_prg_submit( $action, array(), $db ); withdrawal_prg_get( '未対応の操作', false ); }
$allowed = false;
try { withdrawal_prg_submit( 'start', $start, $db ); preflight_check( false, 'permission accepted' ); } catch ( RuntimeException $error ) { preflight_check( str_contains( $error->getMessage(), '権限' ), 'unauthorized POST rejected before dispatch' ); }
$allowed = true;
withdrawal_prg_submit( 'start', $start, $db ); $receipt_get = $_GET;
ob_start(); egov_law_monitor_render_withdrawal_notice( 20 ); $other_target = ob_get_clean();
preflight_check( ! str_contains( $other_target, 'notice-success' ), 'another target cannot read receipt' );
$actor = 98; $html = render_preflight(); preflight_check( ! str_contains( $html, 'notice-success' ), 'another administrator cannot read receipt' ); $actor = 99;
$prg_session = 'other-session'; $html = render_preflight(); preflight_check( ! str_contains( $html, 'notice-success' ), 'another session cannot read receipt' ); $prg_session = 'admin-session';
withdrawal_prg_get( '退会処理を開始しました', true );
$_GET = $receipt_get; $_GET['withdrawal_result'] = str_repeat( 'a', 32 ); $_GET['success'] = '1';
preflight_check( ! str_contains( render_preflight(), 'notice-success' ), 'forged GET outcome cannot create success' );
$db = withdrawal_flow_fixture(); $prg_storage_failure = true;
withdrawal_prg_submit( 'start', $start, $db ); $prg_storage_failure = false;
preflight_check( str_contains( render_preflight(), '結果を取得できません' ), 'notice persistence failure redirects but never invents success' );
$_GET['withdrawal_result'] = array( 'malformed' );
preflight_check( ! str_contains( render_preflight(), 'notice-success' ), 'malformed receipt never creates success' );
$_GET['withdrawal_result'] = str_repeat( 'b', 32 );
$prg_notices[egov_law_monitor_withdrawal_notice_key( 10, $_GET['withdrawal_result'] )] = array( 'type' => 'error', 'message' => '<script>alert(1)</script>' );
$html = render_preflight();
preflight_check( str_contains( $html, '&lt;script&gt;' ) && ! str_contains( $html, '<script>' ), 'server-side notice output escaped' );
echo "Withdrawal PRG tests passed for all seven real handlers and GET reloads; simulated WP/DB only.\n";
