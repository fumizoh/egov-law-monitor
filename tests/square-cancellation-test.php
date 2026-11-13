<?php
/** Service cancellation transactions, mail and date tests; no real DB/API/mail. */
require __DIR__ . '/square-approval-test.php';
function is_user_logged_in() { return ! empty( $GLOBALS['cancel_logged_in'] ); }
function wp_timezone() { return new DateTimeZone( $GLOBALS['cancel_timezone'] ); }
function current_datetime() { return ( new DateTimeImmutable( $GLOBALS['cancel_clock'] ) )->setTimezone( wp_timezone() ); }
function get_option( $key, $default = false ) { return $GLOBALS['cancel_options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['cancel_options'][$key] = $value; }
function dbDelta( $sql ) { $GLOBALS['cancel_ddl'][] = $sql; }
function add_shortcode( ...$args ) {}
function wp_unslash( $value ) { return stripslashes( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_parse_url( $value ) { return parse_url( $value ); }
function home_url( $path ) { return 'https://example.test' . $path; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . http_build_query( array( $key => $value ) ); }
function wp_nonce_field( $action, $name ) { echo '<input name="' . esc_attr( $name ) . '" value="valid:' . esc_attr( $action ) . '">'; }
function nocache_headers() {}
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['cancel_schedule'][$hook] ?? false; }
function wp_schedule_event( $time, $period, $hook ) { $GLOBALS['cancel_schedule'][$hook] = array( $time, $period ); return true; }
function wp_mail( $to, $subject, $body ) {
    foreach ( $GLOBALS['cancel_sessions'] as $session ) { if ( $session->began && ! $session->closed ) { throw new LogicException( 'mail inside transaction' ); } }
    $GLOBALS['cancel_mails'][] = array( $to, $subject, $body );
    if ( isset( $GLOBALS['cancel_during_mail'] ) ) { ( $GLOBALS['cancel_during_mail'] )(); }
    return ! $GLOBALS['cancel_mail_fail'];
}
require __DIR__ . '/../wordpress-plugin/includes/square-cancellations.php';
require __DIR__ . '/../wordpress-plugin/includes/standard-plan-page.php';

class CancellationStore extends ApprovalStore {
    public $cancellations = array(); public $cancellation_exists = true; public $cancellation_bad = '';
    public function get_results( $query, $mode ) { if ( is_string( $query ) ) { $this->last_error = ''; return $this->select( $query, array() ); } return parent::get_results( $query, $mode ); }
    public function select( $sql, $p ) {
        $is_table = str_contains( $sql, 'egov_square_cancellations' );
        if ( str_contains( $sql, 'information_schema.TABLES' ) && ( $p[0] ?? '' ) === $this->prefix . 'egov_square_cancellations' ) {
            return $this->cancellation_exists ? array( array( 'TABLE_NAME' => $p[0], 'ENGINE' => $this->cancellation_bad === 'engine' ? 'MyISAM' : 'InnoDB' ) ) : array();
        }
        if ( $is_table && str_starts_with( $sql, 'SHOW' ) ) {
            if ( str_starts_with( $sql, 'SHOW INDEX' ) ) {
                $rows = array();
                foreach ( array( 'PRIMARY' => array( 'id', 0 ), 'approval_id' => array( 'approval_id', 0 ), 'user_id' => array( 'user_id', 1 ) ) as $name => $spec ) { if ( $this->cancellation_bad === 'unique' && $name === 'approval_id' ) { continue; } $rows[] = array( 'Key_name' => $name, 'Column_name' => $spec[0], 'Non_unique' => $spec[1], 'Sub_part' => $this->cancellation_bad === 'prefix' ? 10 : null ); }
                return $rows;
            }
            $rows = array();
            foreach ( egov_law_monitor_cancellation_columns() as $name => $spec ) { if ( $this->cancellation_bad === 'missing_user_attempt' && $name === 'user_mail_attempt_at' ) { continue; } $rows[] = array( 'Field' => $name, 'Type' => $this->cancellation_bad === 'column' && $name === 'end_date' ? 'text' : $spec[0], 'Null' => $spec[1] ? 'YES' : 'NO', 'Extra' => $name === 'id' ? 'auto_increment' : '' ); }
            return $rows;
        }
        if ( $is_table ) {
            $rows = array_values( $this->cancellations );
            if ( str_contains( $sql, 'WHERE approval_id' ) ) { $rows = array_filter( $rows, static fn( $r ) => (int) $r['approval_id'] === (int) $p[0] ); }
            elseif ( str_contains( $sql, 'WHERE id =' ) ) { $rows = array_filter( $rows, static fn( $r ) => (int) $r['id'] === (int) $p[0] && ( ! isset( $p[1] ) || (int) $r['user_id'] === (int) $p[1] ) ); }
            elseif ( str_contains( $sql, 'WHERE user_id' ) ) { $rows = array_filter( $rows, static fn( $r ) => (int) $r['user_id'] === (int) $p[0] && ( ! str_contains( $sql, 'AND approval_id' ) || (int) $r['approval_id'] === (int) $p[1] ) ); }
            else { $rows = array_filter( $rows, static fn( $r ) => $r['completed_at'] === null && (int) $r['id'] > (int) $p[0] ); }
            if ( str_contains( $sql, 'completed_at IS NULL' ) ) { $rows = array_filter( $rows, static fn( $r ) => $r['completed_at'] === null ); }
            if ( str_contains( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
            return array_values( $rows );
        }
        if ( str_contains( $sql, 'admin_log' ) && str_contains( $sql, 'WHERE id =' ) ) { return array_values( array_filter( $this->logs, static fn( $r ) => (int) $r['id'] === (int) $p[0] && (int) $r['user_id'] === (int) $p[1] ) ); }
        return parent::select( $sql, $p );
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
}
class CancellationSession extends ApprovalSession {
    private $source;
    public function __construct( $store ) { parent::__construct( $store ); $this->source = $store; }
    public function write( $sql, $p = array() ) {
        if ( $this->source->fail === 'cancel_ack' && str_contains( $sql, 'SET admin_notified_at' ) ) { return false; }
        if ( str_contains( $sql, 'admin_log' ) && ( ( $this->source->fail === 'completion_audit' && $p[2] === 'subscription_cancellation_completed' ) || ( $this->source->fail === 'date_audit' && str_starts_with( $p[2], 'subscription_end_date_' ) ) ) ) { return false; }
        if ( ! str_contains( $sql, 'egov_square_cancellations' ) ) { return parent::write( $sql, $p ); }
        $this->source->sql_queries[] = $sql;
        if ( $this->source->fail === 'cancel_write' || ( $this->source->fail === 'cancel_complete' && str_contains( $sql, 'SET completed_at' ) ) ) { return false; }
        if ( $this->source->fail === 'disconnect:cancel' ) { $this->connection_lost = true; return false; }
        if ( str_starts_with( $sql, 'INSERT' ) ) {
            foreach ( $this->local->cancellations as $row ) { if ( (int) $row['approval_id'] === (int) $p[2] ) { return false; } }
            $id = count( $this->local->cancellations ) + 1; $this->insert_id = $id;
            $this->local->cancellations[$id] = array( 'id' => $id, 'user_id' => $p[0], 'contract_id' => $p[1], 'approval_id' => $p[2], 'requested_at' => $p[3], 'updated_at' => $p[4], 'revision' => 1, 'end_date' => null, 'completed_at' => null, 'admin_notified_at' => null, 'user_notified_revision' => 0, 'last_mail_attempt_at' => null, 'request_mail_attempt_at' => null, 'user_mail_attempt_at' => null, 'last_due_attempt_date' => null, 'last_due_sent_date' => null, 'mail_claim_token' => null, 'mail_claimed_at' => null );
        } else {
            $id = $p[count( $p ) - 2]; $row = &$this->local->cancellations[$id];
            if ( str_contains( $sql, 'SET end_date' ) ) { $row['end_date'] = $p[0]; $row['updated_at'] = $p[1]; ++$row['revision']; }
            elseif ( str_contains( $sql, 'SET completed_at' ) ) { $row['completed_at'] = $p[0]; $row['updated_at'] = $p[1]; ++$row['revision']; $row['mail_claim_token'] = null; $row['mail_claimed_at'] = null; }
            elseif ( str_contains( $sql, 'SET mail_claim_token' ) ) { $row['mail_claim_token'] = $p[0]; $row['mail_claimed_at'] = $p[1]; $row['last_mail_attempt_at'] = $p[2]; $row['last_due_attempt_date'] = $p[3]; $row['request_mail_attempt_at'] = $p[4]; $row['user_mail_attempt_at'] = $p[5]; }
            elseif ( str_contains( $sql, 'SET admin_notified_at' ) ) { $row['admin_notified_at'] = $p[0]; $row['user_notified_revision'] = $p[1]; $row['last_due_sent_date'] = $p[2]; $row['mail_claim_token'] = null; $row['mail_claimed_at'] = null; }
            else { throw new LogicException( 'unknown cancellation write' ); }
        }
        return true;
    }
    public function commit() { $ok = parent::commit(); if ( $ok || $this->source->commit_applied ) { $this->source->cancellations = $this->local->cancellations; } return $ok; }
}
function reset_cancellation() {
    reset_store(); $GLOBALS['wpdb'] = new CancellationStore(); $GLOBALS['checkout_user'] = 10; change();
    $GLOBALS['cancel_logged_in'] = true; $GLOBALS['cancel_timezone'] = 'Asia/Tokyo'; $GLOBALS['cancel_clock'] = '2026-11-13T00:00:00+09:00';
    $GLOBALS['cancel_options'] = array( 'admin_email' => 'admin@example.test' ); $GLOBALS['cancel_mails'] = array(); $GLOBALS['cancel_sessions'] = array(); $GLOBALS['cancel_mail_fail'] = false; $GLOBALS['cancel_schedule'] = array();
    unset( $GLOBALS['egov_standard_cancel_result'], $GLOBALS['cancel_during_mail'] ); $_POST = array(); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
}
function cancel_factory() { $session = new CancellationSession( $GLOBALS['wpdb'] ); $GLOBALS['cancel_sessions'][] = $session; return $session; }
function request_cancellation( $nonce = 'valid:egov_standard_cancel_10', $token = null ) { return egov_law_monitor_request_subscription_cancellation( $nonce, $token ?? egov_law_monitor_cancellation_context( 10 )['plan_token'], 'cancel_factory' ); }
function set_cancel_date( $date, $token = null, $confirmed = true, $reason = 'Dashboardで確認', $nonce = 'valid:egov_cancel_date_10_1' ) {
    return egov_law_monitor_save_cancellation_date( 10, 1, $date, $nonce, $token ?? egov_law_monitor_cancellation_token( $GLOBALS['wpdb']->cancellations[1] ), $confirmed, $reason, 'cancel_factory' );
}
function free_cancellation() { return egov_law_monitor_square_make_free( 10, token( 0 ), 'valid:egov_square_free_10', '利用終了日を確認して解除', cancel_factory() ); }
$start = $checks;
reset_cancellation(); $contracts = $wpdb->contracts; $original_usage = array( $trial_usage, $monthly_usage ); $allowed = false;
$result = request_cancellation();
check( ! is_wp_error( $result ) && $result['id'] === 1 && count( $wpdb->cancellations ) === 1 && $wpdb->meta[1]['meta_value'] === 'standard', 'Standard user can request without changing plan' );
check( $wpdb->contracts === $contracts && array( $trial_usage, $monthly_usage ) === $original_usage, 'request preserves contracts and AI usage' );
check( $wpdb->logs[1]['action'] === 'subscription_cancellation_requested' && $wpdb->logs[1]['admin_user_id'] === 0 && json_decode( $wpdb->logs[1]['new_value'], true )['actor_user_id'] === 10, 'request audited as user operation' );
check( request_cancellation()['already'] && count( $wpdb->logs ) === 2 && count( $wpdb->cancellations ) === 1, 'duplicate clicks create neither duplicate row nor audit' );
$cancel_mail_fail = true; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 1 && $wpdb->cancellations[1]['admin_notified_at'] === null && $wpdb->meta[1]['meta_value'] === 'standard', 'failed admin mail retains accepted request and Standard' );
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); check( count( $cancel_mails ) === 1, 'failure retry uses cooldown' );
$cancel_clock = '2026-11-13T01:01:00+09:00'; $cancel_mail_fail = false; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2 && $wpdb->cancellations[1]['admin_notified_at'] !== null && str_contains( $cancel_mails[1][2], 'SUB1' ) && str_contains( $cancel_mails[1][2], 'user_id=10' ), 'admin retry includes identity, contract and admin link' );

foreach ( array( 'nonce', 'logged_out', 'free', 'forged', 'other_user', 'ambiguous_contract' ) as $case ) {
    reset_cancellation(); $signature = egov_law_monitor_cancellation_context( 10 )['plan_token'];
    if ( $case === 'logged_out' ) { $cancel_logged_in = false; }
    if ( $case === 'free' ) { $wpdb->meta[1]['meta_value'] = 'free'; }
    if ( $case === 'other_user' ) { $checkout_user = 20; }
    if ( $case === 'ambiguous_contract' ) { $wpdb->logs[0]['new_value'] = '{}'; }
    $result = request_cancellation( $case === 'nonce' ? 'bad' : 'valid:egov_standard_cancel_' . get_current_user_id(), $case === 'forged' ? 'forged' : $signature );
    check( is_wp_error( $result ) && ! $wpdb->cancellations && count( $wpdb->logs ) === 1, 'invalid request rejected: ' . $case );
}
reset_cancellation(); request_cancellation(); $allowed = true;
$r = set_cancel_date( '2026-11-15' );
check( ! is_wp_error( $r ) && $wpdb->cancellations[1]['end_date'] === '2026-11-15' && $wpdb->logs[2]['action'] === 'subscription_end_date_set' && $wpdb->meta[1]['meta_value'] === 'standard', 'administrator sets date and audit without plan change' );
$old_token = egov_law_monitor_cancellation_token( $wpdb->cancellations[1] );
check( set_cancel_date( '2026-11-16' )['unchanged'] === false && $wpdb->logs[3]['action'] === 'subscription_end_date_changed', 'date edit audited' );
check( is_wp_error( set_cancel_date( '2026-11-15', $old_token ) ) && $wpdb->cancellations[1]['end_date'] === '2026-11-16', 'stale administrator form cannot overwrite date' );
check( set_cancel_date( '2026-11-16' )['unchanged'] && count( $wpdb->logs ) === 4, 'identical date does not create duplicate audit' );
foreach ( array( '2026-02-30', '2026-2-03', 'bad', '<script>' ) as $date ) { check( is_wp_error( set_cancel_date( $date ) ), 'invalid date rejected: ' . $date ); }
check( is_wp_error( set_cancel_date( '2026-11-17', null, false ) ) && is_wp_error( set_cancel_date( '2026-11-17', null, true, '' ) ) && is_wp_error( set_cancel_date( '2026-11-17', null, true, '確認', 'bad' ) ), 'Dashboard check, reason and nonce required' );
$allowed = false; check( is_wp_error( set_cancel_date( '2026-11-17' ) ), 'date update needs manage_options' ); $allowed = true;

reset_cancellation(); request_cancellation(); set_cancel_date( '2026-11-14' );
$row = $wpdb->cancellations[1];
$wpdb->cancellations[1]['admin_notified_at'] = egov_law_monitor_cancellation_now();
$wpdb->cancellations[1]['user_notified_revision'] = $row['revision'];
check( egov_law_monitor_cancellation_state( null, 'standard' ) === 'none' && egov_law_monitor_cancellation_state( array_replace( $row, array( 'end_date' => null ) ), 'standard' ) === 'requested', 'no request and no end date states' );
check( egov_law_monitor_cancellation_state( $row, 'standard' ) === 'scheduled', 'before end date remains scheduled' );
$cancel_clock = '2026-11-13T15:00:00Z';
check( egov_law_monitor_cancellation_state( $row, 'standard' ) === 'scheduled', 'Tokyo start of end date is not due' );
$cancel_clock = '2026-11-14T23:59:59+09:00';
$quiet_row = array_replace( $row, array( 'admin_notified_at' => egov_law_monitor_cancellation_now(), 'user_notified_revision' => $row['revision'] ) );
check( egov_law_monitor_cancellation_state( $row, 'standard' ) === 'scheduled' && egov_law_monitor_cancellation_mail_job( $quiet_row ) === null, 'no due notification through end date 23:59:59' );
$cancel_clock = '2026-11-15T00:00:00+09:00';
check( egov_law_monitor_cancellation_state( $row, 'standard' ) === 'due' && egov_law_monitor_cancellation_mail_job( $quiet_row ) === 'due', 'next local midnight starts due notification' );
$cancel_timezone = 'America/Los_Angeles';
check( egov_law_monitor_cancellation_state( $row, 'standard' ) === 'scheduled' && egov_law_monitor_cancellation_mail_job( $quiet_row ) === null, 'same instant still on local end date is not due' ); $cancel_timezone = 'Asia/Tokyo';
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 1 && str_contains( $cancel_mails[0][1], '利用終了日到来' ) && $wpdb->cancellations[1]['last_due_attempt_date'] === '2026-11-15', 'due mail reserves local day before send' );
for ( $i = 0; $i < 3; ++$i ) { egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); }
check( count( $cancel_mails ) === 1 && $wpdb->meta[1]['meta_value'] === 'standard', 'same-day duplicate cron calls never auto-downgrade or resend due mail' );
$cancel_clock = '2026-11-16T00:01:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2 && $wpdb->cancellations[1]['last_due_sent_date'] === '2026-11-16', 'next local day re-notifies while still Standard' );
$result = free_cancellation();
check( ! is_wp_error( $result ) && $wpdb->meta[1]['meta_value'] === 'free' && $wpdb->cancellations[1]['completed_at'] !== null, 'existing approval revocation atomically completes request' );
check( end( $wpdb->logs )['action'] === 'subscription_cancellation_completed' && egov_law_monitor_cancellation_state( $wpdb->cancellations[1], 'free' ) === 'completed', 'completion audited and shown as Free changed' );
$cancel_clock = '2026-11-16T00:01:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2, 'Free completion stops future notices' );

reset_cancellation(); request_cancellation(); set_cancel_date( '2026-11-12' ); $cancel_mail_fail = true;
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); $cancel_clock = '2026-11-13T02:00:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( array_filter( $cancel_mails, static fn( $m ) => str_contains( $m[1], '利用終了日到来' ) ) ) === 1 && $wpdb->cancellations[1]['last_due_sent_date'] === null, 'failed due notification still capped to one attempt per day' );
$cancel_clock = '2026-11-14T00:01:00+09:00'; $cancel_mail_fail = false; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( $wpdb->cancellations[1]['last_due_sent_date'] === '2026-11-14', 'failed due mail retries on next local day' );

foreach ( array( 'cancel_write', 'audit', 'audit_rollback', 'commit', 'disconnect:cancel' ) as $failure ) {
    reset_cancellation(); $wpdb->fail = $failure; $r = request_cancellation();
    check( is_wp_error( $r ) && ! $wpdb->cancellations && count( $wpdb->logs ) === 1 && $wpdb->meta[1]['meta_value'] === 'standard', 'request atomic on failure: ' . $failure );
}
reset_cancellation(); request_cancellation(); $wpdb->fail = 'cancel_complete'; $r = free_cancellation();
check( is_wp_error( $r ) && $wpdb->meta[1]['meta_value'] === 'standard' && $wpdb->cancellations[1]['completed_at'] === null && count( $wpdb->logs ) === 2, 'completion failure rolls back existing Free change and its audit' );
reset_cancellation(); request_cancellation(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true; $r = free_cancellation();
check( is_wp_error( $r ) && $r->code === 'square_plan_outcome_unknown' && $wpdb->meta[1]['meta_value'] === 'free' && $wpdb->cancellations[1]['completed_at'] !== null, 'ambiguous Free COMMIT retains atomic plan/completion classification' );

reset_cancellation();
$panel = egov_law_monitor_subscription_cancellation_panel( 10 ); check( str_contains( $panel, 'Standardを解約する' ) && ! str_contains( $panel, '<form' ), 'initial button opens separate confirmation page' );
check( str_contains( egov_law_monitor_plan_status_shortcode(), 'Standardを解約する' ) && str_contains( egov_law_monitor_plan_status_shortcode(), 'egov-plan-status-badge--standard' ), 'mypage integration keeps Standard badge and cancellation available independently of new-application publication gate' );
$_GET['egov_standard_cancel_confirm'] = '1'; $panel = egov_law_monitor_subscription_cancellation_panel( 10 );
check( str_contains( $panel, '>解約を申請する</button>' ) && ! str_contains( $panel, '確定して解約を申請する' ) && str_contains( $panel, 'standard_cancel_nonce' ) && ! $wpdb->cancellations, 'confirmation GET has updated label and nonce and makes no changes' );
check( str_contains( egov_law_monitor_standard_application_shortcode(), '>解約を申請する</button>' ) && ! str_contains( egov_law_monitor_standard_application_shortcode(), 'https://square.link/u/30s16R3f' ), 'independent confirmation page never offers another payment to Standard user' );
check( str_contains( $panel, '<p class="egov-standard-cancellation-actions">' ), 'confirmation actions have dedicated layout scope' );
$cancellation_css = file_get_contents( __DIR__ . '/../wordpress-plugin/assets/css/egov-law-post.css' );
check( preg_match( '/\.egov-standard-cancellation \.egov-standard-cancellation-actions\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;[^}]*align-items:\s*center;[^}]*gap:\s*16px;/s', $cancellation_css ), 'confirmation-only actions wrap with 16px gap' );
check( str_contains( $panel, '管理者がSquareで解約手続きを行います。お支払い済みの期間は、引き続きStandardプランをご利用いただけます。' ) && ! str_contains( $panel, '申請だけではSquareの解約' ), 'confirmation uses customer-facing cancellation guidance' );
$application_html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $application_html, egov_law_monitor_standard_contact_guidance() ) && ! str_contains( $application_html, 'お支払い・契約・解約については管理者へお問い合わせください。' ), 'application uses shared contact form guidance' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'egov_standard_cancel_request' => '1', 'standard_cancel_nonce' => 'valid:egov_standard_cancel_10', 'standard_cancel_token' => egov_law_monitor_cancellation_context( 10 )['plan_token'], 'user_id' => '20' );
$redirects = array(); $redirect = static function ( $url, $status ) use ( &$redirects ) { $redirects[] = array( $url, $status ); return 'redirect'; };
check( egov_law_monitor_subscription_cancel_post( 'cancel_factory', $redirect ) === 'redirect' && $redirects === array( array( 'https://example.test/standard-application/', 303 ) ), 'successful request PRG uses fixed same-site 303 target and ignores forged user ID' );
egov_law_monitor_subscription_cancel_post( 'cancel_factory', $redirect );
check( count( $wpdb->cancellations ) === 1 && count( $cancel_mails ) === 1 && count( $redirects ) === 1, 'POST handler runs once per request' );
$_SERVER['REQUEST_METHOD'] = 'GET'; check( egov_law_monitor_subscription_cancel_post( 'cancel_factory', $redirect ) === null && str_contains( egov_law_monitor_subscription_cancellation_panel( 10 ), '解約申請を受け付けました' ), 'GET shows accepted state and never replays POST' );
set_cancel_date( '2026-11-15' ); $panel = egov_law_monitor_subscription_cancellation_panel( 10 );
check( str_contains( $panel, 'Standardプランは解約予定です。' ) && str_contains( $panel, '2026年11月15日' ) && ! str_contains( $panel, 'Standardを解約する' ), 'scheduled date appears in mypage instead of button' );

// Both shortcodes share exactly one state-specific notice; rendering never sends mail or changes plans.
foreach ( array( 'normal', 'requested', 'scheduled', 'end_day', 'due' ) as $display_state ) {
    reset_cancellation();
    if ( $display_state !== 'normal' ) { request_cancellation(); }
    if ( in_array( $display_state, array( 'scheduled', 'end_day', 'due' ), true ) ) { set_cancel_date( '2026-11-15' ); }
    if ( $display_state === 'end_day' ) { $cancel_clock = '2026-11-15T23:59:59+09:00'; }
    if ( $display_state === 'due' ) { $cancel_clock = '2026-11-16T00:00:00+09:00'; }
    $expected = array( 'normal' => 'Standardプランをご利用中です。', 'requested' => '解約申請を受け付けました。手続き完了後に利用終了日をお知らせします。', 'scheduled' => 'Standardプランは解約予定です。', 'end_day' => 'Standardプランは解約予定です。', 'due' => 'Standardプランの利用終了日を過ぎています。管理者による手続き中です。' );
    $before_display = serialize( array( $wpdb->cancellations, $wpdb->logs, $wpdb->meta, $cancel_mails ) );
    foreach ( array( 'egov_law_monitor_plan_status_shortcode', 'egov_law_monitor_standard_application_shortcode' ) as $shortcode ) {
        $html = $shortcode();
        check( substr_count( $html, 'href="https://egovlm.oogushioffice.com/contact/"' ) === 1 && substr_count( $html, 'お支払い・契約に関するお問い合わせは、<a' ) === 1 && str_contains( $html, '>お問い合わせフォーム</a>からご連絡ください。' ), 'one ordinary contact link in each Standard state: ' . $display_state . '/' . $shortcode );
        check( substr_count( $html, $expected[$display_state] ) === 1 && ! str_contains( $html, 'Squareの支払状況はこの画面では確認できません' ) && ( $display_state === 'normal' || ! str_contains( $html, 'Standardプランをご利用中です。' ) ), 'one shared notice without obsolete or duplicate guidance: ' . $display_state . '/' . $shortcode );
        if ( in_array( $display_state, array( 'scheduled', 'end_day', 'due' ), true ) ) {
            check( str_contains( $html, '利用終了日：<strong>2026年11月15日</strong>' ) && ( str_contains( $html, '利用終了日まではStandardプランをご利用いただけます。' ) === ( $display_state !== 'due' ) ), 'end date and usage notice match state: ' . $display_state . '/' . $shortcode );
        }
    }
    check( serialize( array( $wpdb->cancellations, $wpdb->logs, $wpdb->meta, $cancel_mails ) ) === $before_display, 'display has no mutation or mail side effects: ' . $display_state );
}

foreach ( array( 'engine', 'column', 'unique', 'prefix', 'missing_user_attempt' ) as $bad ) {
    reset_cancellation(); $wpdb->cancellation_bad = $bad;
    check( ! egov_law_monitor_cancellation_storage_ready() && is_wp_error( request_cancellation() ), 'incomplete storage rejects request: ' . $bad );
    egov_law_monitor_install_cancellation_table(); check( ! isset( $cancel_options['egov_law_monitor_cancellation_schema_version'] ), 'incomplete schema never advances version' );
}
reset_cancellation(); egov_law_monitor_install_cancellation_table();
check( $cancel_options['egov_law_monitor_cancellation_schema_version'] === '2' && str_contains( end( $cancel_ddl ), 'ENGINE=InnoDB' ), 'activation/update schema version only after metadata verification' );
$cancel_options['egov_law_monitor_cancellation_schema_version'] = '1';
$schema_rows = serialize( array( $wpdb->cancellations, $wpdb->contracts, $wpdb->logs, $wpdb->meta ) );
egov_law_monitor_install_cancellation_table();
check( $cancel_options['egov_law_monitor_cancellation_schema_version'] === '2' && str_contains( end( $cancel_ddl ), 'request_mail_attempt_at DATETIME NULL' ) && str_contains( end( $cancel_ddl ), 'user_mail_attempt_at DATETIME NULL' ) && $schema_rows === serialize( array( $wpdb->cancellations, $wpdb->contracts, $wpdb->logs, $wpdb->meta ) ), 'schema version one upgrades additively without resetting stored data' );
egov_law_monitor_schedule_cancellation_cron(); $schedule = $cancel_schedule; egov_law_monitor_schedule_cancellation_cron();
check( $cancel_schedule === $schedule && $cancel_schedule['egov_standard_cancellation_cron'][1] === 'hourly', 'hourly scheduling avoids duplicate events' );
reset_cancellation(); request_cancellation();
$cancel_during_mail = static function () { egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); };
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 1 && $wpdb->cancellations[1]['admin_notified_at'] !== null, 'overlapping notification workers honor committed lease and cooldown' );
unset( $cancel_during_mail );
set_cancel_date( '2026-11-15' ); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2 && $cancel_mails[1][0] === 'user10@example.test' && str_contains( $cancel_mails[1][2], '2026年11月15日' ) && (int) $wpdb->cancellations[1]['user_notified_revision'] === (int) $wpdb->cancellations[1]['revision'], 'end date mail reaches user through wp_mail and acknowledges revision' );
check( $cancel_mails[1][2] === "Standard利用終了日: 2026年11月15日\n利用終了日当日までStandardプランをご利用いただけます。\n", 'user end-date mail uses concise body without administrator processing explanation' );
$mail_token = egov_law_monitor_cancellation_token( $wpdb->cancellations[1] );
check( ! is_wp_error( set_cancel_date( '2026-11-16', $mail_token ) ), 'mail acknowledgements do not invalidate administrator date token' );
reset_cancellation(); request_cancellation(); $wpdb->fail = 'date_audit'; $r = set_cancel_date( '2026-11-15' );
check( is_wp_error( $r ) && $wpdb->cancellations[1]['end_date'] === null && count( $wpdb->logs ) === 2, 'date audit failure rolls back date write' );
reset_cancellation(); request_cancellation(); $wpdb->fail = 'completion_audit'; $r = free_cancellation();
check( is_wp_error( $r ) && $wpdb->meta[1]['meta_value'] === 'standard' && $wpdb->cancellations[1]['completed_at'] === null && count( $wpdb->logs ) === 2, 'completion audit failure rolls back plan and completion together' );
reset_cancellation(); $wpdb->cancellation_exists = false;
check( ! is_wp_error( free_cancellation() ) && $wpdb->meta[1]['meta_value'] === 'free', 'old sites without new table retain existing Free revocation' );
reset_cancellation(); request_cancellation(); $wpdb->meta[1]['meta_value'] = 'free'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( ! $cancel_mails && $wpdb->cancellations[1]['completed_at'] !== null && $wpdb->meta[1]['meta_value'] === 'free', 'cron notices externally changed Free, stops mail and audits local completion without changing plan' );
reset_cancellation(); request_cancellation(); free_cancellation(); $old_request = $wpdb->cancellations[1]; change(); request_cancellation();
check( count( $wpdb->cancellations ) === 2 && $wpdb->cancellations[1] === $old_request && $wpdb->cancellations[2]['approval_id'] !== $old_request['approval_id'], 'new approval cycle creates distinct request and retains completed history' );
reset_cancellation(); request_cancellation(); egov_law_monitor_cancellation_cron( 'cancel_factory' ); egov_law_monitor_cancellation_cron( 'cancel_factory' );
check( count( $cancel_mails ) === 1 && $cancel_options['egov_standard_cancellation_cursor'] === 0, 'cron processes bounded queue with persisted cursor and no duplicate mail' );
reset_cancellation(); request_cancellation(); $allowed = true; ob_start(); egov_law_monitor_render_subscription_cancellations( 10, admin_url( 'admin.php' ) ); $admin_html = ob_get_clean();
check( str_contains( $admin_html, '未処理：利用終了日未登録' ) && str_contains( $admin_html, 'subscription_cancel_date_nonce' ) && str_contains( $admin_html, 'subscription_dashboard_confirmed' ), 'administrator sees unprocessed request and protected date form' );
reset_cancellation(); request_cancellation(); set_cancel_date( '2026-11-12' ); $wpdb->cancellations[1]['admin_notified_at'] = egov_law_monitor_cancellation_now(); $wpdb->fail = 'cancel_ack';
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); $wpdb->fail = ''; $cancel_clock = '2026-11-13T02:00:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( array_filter( $cancel_mails, static fn( $m ) => str_contains( $m[1], '利用終了日到来' ) ) ) === 1 && $wpdb->cancellations[1]['last_due_attempt_date'] === '2026-11-13', 'lost mail acknowledgement cannot cause a second due notification that day' );
reset_cancellation(); request_cancellation(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true; $r = set_cancel_date( '2026-11-15' );
check( $r->code === 'cancellation_outcome_unknown' && $wpdb->cancellations[1]['end_date'] === '2026-11-15' && end( $wpdb->logs )['action'] === 'subscription_end_date_set' && ! $cancel_mails, 'date COMMIT ambiguity preserves queued notification and audit but sends no premature email' );
reset_cancellation(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true; $r = request_cancellation();
check( $r->code === 'cancellation_outcome_unknown' && count( $wpdb->cancellations ) === 1 && count( $wpdb->logs ) === 2, 'request COMMIT ambiguity distinguishes stored request from success response' );
reset_cancellation(); request_cancellation();
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
set_cancel_date( '2026-12-12' );
check( egov_law_monitor_cancellation_user_mail_status( $wpdb->cancellations[1] ) === '待機中', 'request mail does not mark user notification attempted' );
$cancel_during_mail = static function () { egov_law_monitor_cancellation_cron( 'cancel_factory' ); };
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); unset( $cancel_during_mail );
check( count( $cancel_mails ) === 2 && $cancel_mails[1][0] === 'user10@example.test' && $wpdb->cancellations[1]['user_notified_revision'] === $wpdb->cancellations[1]['revision'], 'date mail sends immediately after administrator mail, overlapping Cron cannot duplicate' );
check( egov_law_monitor_cancellation_user_mail_status( $wpdb->cancellations[1] ) === '送信処理受付済み', 'user notice reports acceptance only' );
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2, 'same accepted revision is not resent' );

reset_cancellation(); request_cancellation(); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); set_cancel_date( '2026-12-12' );
$cancel_mail_fail = true; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2 && $wpdb->cancellations[1]['user_notified_revision'] === 0 && str_starts_with( egov_law_monitor_cancellation_user_mail_status( $wpdb->cancellations[1] ), '再試行待ち' ), 'failed end-date notice remains pending with own attempt record' );
egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
$cancel_clock = '2026-11-13T00:59:59+09:00'; egov_law_monitor_cancellation_cron( 'cancel_factory' );
check( count( $cancel_mails ) === 2, 'failed user notice cannot retry before one hour' );
$cancel_clock = '2026-11-13T01:00:00+09:00'; $cancel_mail_fail = false; egov_law_monitor_cancellation_cron( 'cancel_factory' );
check( count( $cancel_mails ) === 3 && egov_law_monitor_cancellation_user_mail_status( $wpdb->cancellations[1] ) === '送信処理受付済み', 'Cron retries user notice at one-hour boundary and acknowledges revision' );
ob_start(); egov_law_monitor_render_subscription_cancellations( 10, admin_url( 'admin.php' ) ); $notification_html = ob_get_clean();
check( str_contains( $notification_html, '利用者向け終了日通知：送信処理受付済み' ) && str_contains( $notification_html, '受信完了を保証しません' ), 'administrator sees delivery disclaimer and user acceptance state' );

reset_cancellation(); request_cancellation(); $cancel_mail_fail = true; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); set_cancel_date( '2026-12-12' );
$cancel_mail_fail = false; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 2 && $wpdb->cancellations[1]['admin_notified_at'] === null, 'user mail bypasses failed request cooldown without resetting request retry limit' );
$cancel_clock = '2026-11-13T01:00:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 3 && $wpdb->cancellations[1]['admin_notified_at'] !== null, 'failed administrator request retries independently' );

reset_cancellation(); request_cancellation(); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); set_cancel_date( '2026-11-13' ); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
$cancel_clock = '2026-11-13T23:59:59+09:00';
check( egov_law_monitor_cancellation_mail_job( $wpdb->cancellations[1] ) === null, 'separated cooldown does not send due notification on end date' );
$cancel_clock = '2026-11-14T00:00:00+09:00'; egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' ); egov_law_monitor_process_cancellation_mail( 1, 'cancel_factory' );
check( count( $cancel_mails ) === 3 && $wpdb->cancellations[1]['last_due_attempt_date'] === '2026-11-14', 'next-day due notification retains daily limit' );

echo ( $checks - $start ) . " service cancellation checks passed; no real DB, API or mail used.\n";
