<?php
/** Actual AI cleanup orchestration; only WordPress/SQL boundaries are doubled. */
require __DIR__ . '/user-withdrawal-watches-test.php';
class AICleanupDB extends WatchCleanupDB {
    public $history = array(), $history_backup, $history_engine = 'InnoDB', $history_deletes = 0;
    public $shared_cache = array( 'cached summary' );
    function begin() { $this->history_backup = $this->history; return parent::begin(); }
    function rows( $sql, $p = array() ) {
        if ( $this->failure === 'read' || ( $this->failure === 'post_read' && $this->mutated ) ) { return parent::rows( $sql, $p ); }
        if ( str_contains( $sql, 'information_schema.TABLES' ) && ( $p[0] ?? '' ) === 'wp_egov_law_ai_summary_history' ) { return array( array( 'TABLE_NAME' => $p[0], 'ENGINE' => $this->history_engine ) ); }
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) && str_contains( $sql, 'egov_law_ai_summary_history' ) ) {
            return array( array( 'total' => $this->failure === 'invalid_count' ? null : (string) count( array_filter( $this->history, static fn( $r ) => $r['user_id'] === $p[0] ) ) ) );
        }
        if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) && ( $p[1] ?? '' ) === 'egov_law_monitor_ai_summary_trial_usage' ) {
            return array( array( 'total' => (string) count( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] === $p[0] && $r['meta_key'] === $p[1] ) ) ) );
        }
        return parent::rows( $sql, $p );
    }
    function write( $sql, $p ) {
        if ( str_starts_with( $sql, 'DELETE FROM `wp_egov_law_ai_summary_history` WHERE user_id = ?' ) ) {
            if ( $this->failure === 'delete' ) { return false; }
            $this->mutated = true; ++$this->history_deletes;
            if ( $this->failure !== 'residual' ) { $this->history = array_values( array_filter( $this->history, static fn( $r ) => $r['user_id'] !== $p[0] ) ); }
            return true;
        }
        if ( str_starts_with( $sql, 'DELETE FROM `wp_usermeta`' ) && $p[1] === 'egov_law_monitor_ai_summary_trial_usage' ) {
            if ( $this->failure === 'meta_delete' ) { return false; }
            $this->notification_rows = array_values( array_filter( $this->notification_rows, static fn( $r ) => $r['user_id'] !== $p[0] || $r['meta_key'] !== $p[1] ) ); return true;
        }
        if ( str_starts_with( $sql, 'DELETE' ) ) { throw new LogicException( 'AI cleanup touched another data table' ); }
        return parent::write( $sql, $p );
    }
    function rollback() {
        $ok = parent::rollback();
        if ( $ok && $this->failure !== 'commit' ) { $this->history = $this->history_backup; }
        return $ok;
    }
}
function ai_cleanup_fixture() {
    watch_cleanup_fixture();
    $db = new AICleanupDB();
    $db->history = array( array( 'user_id' => 10, 'law_id' => 'A' ), array( 'user_id' => 10, 'law_id' => 'B' ), array( 'user_id' => 20, 'law_id' => 'C' ) );
    $db->watches = array( array( 'user_id' => 20, 'is_active' => 1 ) );
    $db->notification_rows = array( array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage' ), array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage' ), array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_standard_consent' ) );
    $GLOBALS['withdrawal_records'][10]['last_audit_id'] = 10;
    $GLOBALS['withdrawal_records'][10]['started_audit_id'] = 1;
    $db->audit_rows[10] = array( 'user_id' => 10, 'action' => 'withdrawal_watches_deleted', 'new_value' => json_encode( array( 'process_uuid' => $GLOBALS['withdrawal_records'][10]['process_uuid'], 'remaining' => array( 'watches' => 0, 'notifications' => 0 ) ) ) );
    $state = $GLOBALS['withdrawal_records'][10];
    $fields = array( 'ai_nonce' => 'valid:egov_withdrawal_ai_10', 'confirm_ai_delete' => '1', 'ai_token' => egov_law_monitor_withdrawal_ai_token( 10, $state, egov_law_monitor_withdrawal_ai_snapshot( 10, $state, $db ) ) );
    return array( $db, $fields );
}
list( $db, $fields ) = ai_cleanup_fixture(); $before_watches = $db->watches;
$result = egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db );
preflight_check( ! is_wp_error( $result ) && $result['remaining'] === array( 'history' => 0, 'trial' => 0 ) && ! $result['already'], 'history and trial deleted with residual zero' );
preflight_check( $db->history === array( array( 'user_id' => 20, 'law_id' => 'C' ) ) && $db->shared_cache === array( 'cached summary' ), 'other user history and shared cache protected' );
preflight_check( $db->notification_rows === array( array( 'user_id' => 20, 'meta_key' => 'egov_law_monitor_ai_summary_trial_usage' ), array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_standard_consent' ) ), 'consent and other trial protected' );
preflight_check( $db->watches === $before_watches && $db->deletes === 0 && $withdrawal_records[10]['phase'] === 'started' && $withdrawal_records[10]['revision'] === 2, 'no watch cleanup or later stage executed' );
preflight_check( $db->audit_rows[11]['action'] === 'withdrawal_ai_history_deleted' && count( $db->audit_rows ) === 2 && egov_law_monitor_withdrawal_watch_snapshot( 10, $withdrawal_records[10], $db )['recorded'], 'AI audit and previous watch evidence remain available' );
preflight_check( ! isset( $GLOBALS['cleanup_meta_cache'][10] ) && in_array( array( 10, 'user_meta', true ), $GLOBALS['cleanup_cache_events'], true ), 'AI metadata cache invalidated after COMMIT' );
preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === 2, 'old double submit refused' );
$fields['ai_token'] = egov_law_monitor_withdrawal_ai_token( 10, $withdrawal_records[10], egov_law_monitor_withdrawal_ai_snapshot( 10, $withdrawal_records[10], $db ) );
preflight_check( egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db )['already'] && count( $db->audit_rows ) === 2, 'fresh retry safely no-ops' );
$db->history[] = array( 'user_id' => 10, 'law_id' => 'late' );
$fields['ai_token'] = egov_law_monitor_withdrawal_ai_token( 10, $withdrawal_records[10], egov_law_monitor_withdrawal_ai_snapshot( 10, $withdrawal_records[10], $db ) );
preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db ) ) && count( $db->audit_rows ) === 3, 'late residual can be explicitly removed again' );
foreach ( array( 'schema', 'begin', 'read', 'invalid_count', 'delete', 'meta_delete', 'post_read', 'residual', 'audit', 'state', 'commit', 'audit_rollback' ) as $failure ) {
    list( $db, $fields ) = ai_cleanup_fixture(); $before = array( $db->history, $db->notification_rows, $withdrawal_records, $db->audit_rows ); $db->failure = $failure;
    $result = egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db );
    preflight_check( is_wp_error( $result ) && $db->closed, 'AI failure not successful: ' . $failure );
    if ( in_array( $failure, array( 'commit', 'audit_rollback' ), true ) ) { preflight_check( $result->code === 'withdrawal_ai_unknown', 'AI uncertain outcome reported' ); }
    else { preflight_check( array( $db->history, $db->notification_rows, $withdrawal_records, $db->audit_rows ) === $before, 'AI partial changes rolled back: ' . $failure ); }
}
foreach ( array( 'watch_not_recorded', 'watch_residual', 'notification_residual', 'other_process', 'not_started', 'completed', 'nonce', 'confirmation', 'token', 'role', 'cap', 'actor', 'permission', 'user', 'engine', 'changed_count' ) as $case ) {
    list( $db, $fields ) = ai_cleanup_fixture(); $uid = 10;
    if ( $case === 'watch_not_recorded' ) { $db->audit_rows[10]['action'] = 'withdrawal_started'; }
    if ( $case === 'watch_residual' ) { $db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); }
    if ( $case === 'notification_residual' ) { $db->notification_rows[] = array( 'user_id' => 10, 'meta_key' => 'egov_law_monitor_notifications' ); }
    if ( $case === 'other_process' ) { $db->audit_rows[10]['new_value'] = '{}'; }
    if ( $case === 'not_started' ) { $withdrawal_records = array(); }
    if ( $case === 'completed' ) { $withdrawal_records[10]['phase'] = 'completed'; }
    if ( $case === 'nonce' ) { $fields['ai_nonce'] = 'bad'; }
    if ( $case === 'confirmation' ) { unset( $fields['confirm_ai_delete'] ); }
    if ( $case === 'token' ) { $fields['ai_token'] = 'bad'; }
    if ( $case === 'role' ) { $person->roles = array( 'subscriber', 'editor' ); }
    if ( $case === 'cap' ) { $caps = array( 'manage_options' ); }
    if ( $case === 'actor' ) { $actor = 10; }
    if ( $case === 'permission' ) { $allowed = false; }
    if ( $case === 'user' ) { $uid = 20; }
    if ( $case === 'engine' ) { $db->history_engine = 'MyISAM'; }
    if ( $case === 'changed_count' ) { $db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); }
    preflight_check( is_wp_error( egov_law_monitor_delete_withdrawal_ai_history( $uid, $fields, fn() => $db ) ) && $db->history_deletes === 0, 'AI precondition refused: ' . $case );
}
list( $db, $fields ) = ai_cleanup_fixture(); $db->history = array();
$db->notification_rows = array_values( array_filter( $db->notification_rows, static fn( $r ) => $r['user_id'] !== 10 || $r['meta_key'] !== 'egov_law_monitor_ai_summary_trial_usage' ) );
$fields['ai_token'] = egov_law_monitor_withdrawal_ai_token( 10, $withdrawal_records[10], egov_law_monitor_withdrawal_ai_snapshot( 10, $withdrawal_records[10], $db ) );
preflight_check( ! is_wp_error( egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db ) ), 'empty history and trial safely recorded' );
function render_ai_step( $state, $result = null ) { ob_start(); egov_law_monitor_render_withdrawal_ai_step( 10, $state, false, $result ); return ob_get_clean(); }
list( $db, $fields ) = ai_cleanup_fixture(); $wpdb = new WatchCleanupReadBridge( $db );
$html = render_ai_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, 'AI個人履歴：2件' ) && str_contains( $html, 'Free試用回数メタ：1件' ) && str_contains( $html, 'name="confirm_ai_delete"' ) && str_contains( $html, 'AI要約の個人履歴を削除</button>' ), 'AI counts and confirmation UI' );
$db->watches[] = array( 'user_id' => 10, 'is_active' => 0 ); $html = render_ai_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '手順5から実行してください' ) && ! str_contains( $html, '<button' ), 'unfinished watch step blocks AI UI' );
array_pop( $db->watches ); $wpdb = new PreflightStore();
egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db );
$wpdb = new WatchCleanupReadBridge( $db ); $html = render_ai_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '削除済み。現在の残存確認：0件' ) && ! str_contains( $html, '<button' ), 'AI completed UI' );
$db->history[] = array( 'user_id' => 10, 'law_id' => 'late' ); $html = render_ai_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '残存データがあります' ) && str_contains( $html, '<button' ), 'late AI data visible for retry' );
$db->failure = 'read'; $html = render_ai_step( $withdrawal_records[10] );
preflight_check( str_contains( $html, '読み取りエラー' ) && ! str_contains( $html, '<button' ), 'AI read failure not zero/success' );
list( $db, $fields ) = ai_cleanup_fixture(); $GLOBALS['cleanup_cache_throw'] = true;
$result = egov_law_monitor_delete_withdrawal_ai_history( 10, $fields, fn() => $db );
preflight_check( is_wp_error( $result ) && $result->code === 'withdrawal_ai_cache_warning' && count( $db->audit_rows ) === 2, 'AI post-COMMIT cache failure warns without rollback claim' );
$GLOBALS['cleanup_cache_throw'] = false;
echo "Withdrawal AI cleanup tests passed; no live DB, API, shared cache or user deletion.\n";
