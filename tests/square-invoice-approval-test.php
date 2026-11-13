<?php
/** Atomic invoice approval on isolated transaction/HTTP doubles only. */
require __DIR__ . '/square-approval-test.php';
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION', 'invoice-test-token' );
define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION', 'LOCATION' );
define( 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_PRODUCTION', 'VARIATION' );
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_nonce_field( $a, $n ) { echo '<input name="' . esc_attr( $n ) . '">'; }
require __DIR__ . '/../wordpress-plugin/includes/square-api.php';
require __DIR__ . '/../wordpress-plugin/includes/square-invoices.php';

class InvoiceStore extends ApprovalStore {
    /** Preexisting immutable fixture history, separate from writes under test. */
    public $environment_history = array();
    public function select( $sql, $p ) {
        if ( str_contains( $sql, 'SELECT action, new_value' ) && str_contains( $sql, 'admin_log' ) ) { return array_slice( array_values( array_reverse( array_filter( array_merge( $this->environment_history, $this->logs ), static fn( $r ) => $r['user_id'] === $p[0] && in_array( $r['action'], array_slice( $p, 1 ), true ) ) ) ), 0, 101 ); }
        if ( str_contains( $sql, 'WHERE square_subscription_id =' ) ) { return array_values( array_filter( $this->contracts, static fn( $r ) => $r['square_subscription_id'] === $p[0] ) ); }
        return parent::select( $sql, $p );
    }
}
class InvoiceSession extends ApprovalSession {
    public function write( $sql, $p = array() ) {
        $store = $GLOBALS['wpdb'];
        if ( $store->fail === 'approval_audit' && str_contains( $sql, 'admin_log' ) && $p[2] === 'standard_approved' ) { return false; }
        if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'egov_square_subscriptions' ) ) {
            $store->queries[] = 'insert_contract';
            if ( $store->fail === 'contract' ) { return false; }
            $id = $this->local->contracts ? max( array_keys( $this->local->contracts ) ) + 1 : 1;
            $this->insert_id = $id;
            foreach ( $this->local->contracts as $r ) { if ( $r['square_subscription_id'] === $p[1] ) { return false; } }
            $this->local->contracts[$id] = array( 'id' => $id, 'square_customer_id' => $p[0], 'square_subscription_id' => $p[1], 'admin_note' => $p[2], 'updated_at' => $p[3], 'user_id' => $p[4], 'created_at' => $p[5], 'verified_at' => null, 'verified_by' => null );
            return true;
        }
        if ( str_contains( $sql, 'admin_log' ) && count( $p ) === 6 ) { $p = array_merge( array_slice( $p, 0, 5 ), array( null, $p[5] ) ); }
        return parent::write( $sql, $p );
    }
}
function invoice_fixture() {
    return array( 'id' => 'inv:0-test', 'version' => 1, 'updated_at' => '2026-11-12T10:00:00Z', 'invoice_number' => '000004', 'location_id' => 'LOCATION', 'subscription_id' => 'SUB1', 'status' => 'PAID', 'primary_recipient' => array( 'customer_id' => 'CUSTOMER', 'given_name' => 'Test', 'email_address' => 'test@example.test' ),
        'payment_requests' => array( array( 'computed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ), 'total_completed_amount_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) );
}
function subscription_fixture() { return array( 'id' => 'SUB1', 'version' => 2, 'location_id' => 'LOCATION', 'plan_variation_id' => 'VARIATION', 'customer_id' => 'CUSTOMER', 'status' => 'ACTIVE' ); }
function reset_invoice_approval( $existing = false ) {
    reset_store(); $GLOBALS['wpdb'] = new InvoiceStore();
    if ( $existing ) { $GLOBALS['wpdb']->contracts = array( 1 => $GLOBALS['wpdb']->contracts[1] ); $GLOBALS['wpdb']->contracts[1]['admin_note'] = '申込照合: {"environment":"production"}'; }
    else { $GLOBALS['wpdb']->contracts = array(); }
    if ( $existing ) { $GLOBALS['wpdb']->environment_history[] = array( 'user_id' => 10, 'action' => 'subscription_created', 'new_value' => json_encode( $GLOBALS['wpdb']->contracts[1] ) ); }
    $GLOBALS['invoice_data'] = invoice_fixture(); $GLOBALS['subscription_data'] = subscription_fixture();
    $GLOBALS['invoice_http_calls'] = array(); $GLOBALS['invoice_session'] = null;
    $GLOBALS['checkout_contract_transport'] = static function ( $url, $args ) {
        if ( $GLOBALS['invoice_session'] && ! $GLOBALS['invoice_session']->closed ) { throw new LogicException( 'HTTP during DB transaction' ); }
        $GLOBALS['invoice_http_calls'][] = array( $url, $args );
        if ( str_contains( $url, '/invoices?' ) ) { $data = array( 'invoices' => array( $GLOBALS['invoice_data'] ) ); }
        elseif ( str_contains( $url, '/invoices/' ) ) { $data = array( 'invoice' => $GLOBALS['invoice_data'] ); }
        else { $data = array( 'subscription' => $GLOBALS['subscription_data'] ); }
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
    };
}
function invoice_candidate() {
    return egov_law_monitor_invoice_candidate( 10, egov_law_monitor_lookup_square_invoice( 10, '000004', 'valid:egov_square_invoice_10' ) );
}
function invoice_fields( $r ) { return array( 'nonce' => 'valid:egov_square_invoice_approve_10', 'candidate' => $r['candidate'], 'signature' => $r['signature'], 'confirmed' => '1', 'reason' => '本人とメール・申込情報を照合した' ); }
function approve_invoice( $fields ) {
    return egov_law_monitor_approve_square_invoice( 10, $fields, static function () { return $GLOBALS['invoice_session'] = new InvoiceSession( $GLOBALS['wpdb'] ); } );
}
function resign_invoice( &$fields, $key, $value ) {
    $data = json_decode( $fields['candidate'], true ); $data[$key] = $value;
    $fields['candidate'] = json_encode( $data ); $fields['signature'] = egov_law_monitor_invoice_signature( $fields['candidate'] );
}
$invoice_checks_start = $checks;
reset_invoice_approval(); seed_watches(); $original_watches = $wpdb->watches; $candidate = invoice_candidate(); $fields = invoice_fields( $candidate );
check( isset( $candidate['signature'] ) && ! $wpdb->logs && ! $wpdb->meta, 'candidate is signed and read-only' );
ob_start(); egov_law_monitor_render_invoice_lookup( 10, 'https://example.test/admin', $candidate ); $html = ob_get_clean();
check( str_contains( $html, '<details>' ) && str_contains( $html, '対象ユーザー本人の契約' ) && str_contains( $html, 'invoice_reason' ) && str_contains( $html, '契約を登録してStandardを承認' ), 'compact result, identity comparison and explicit confirmation form' );
$r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && ! $r['already'] && count( $wpdb->contracts ) === 1 && $wpdb->meta[1]['meta_value'] === 'standard', 'new contract and Standard committed together' );
check( $wpdb->contracts[1]['verified_by'] === 99 && count( $wpdb->logs ) === 2 && $wpdb->logs[0]['action'] === 'subscription_created' && $wpdb->logs[1]['action'] === 'standard_approved', 'creation and approval audits with confirmation' );
$evidence = json_decode( $wpdb->logs[1]['new_value'], true )['invoice_evidence'];
check( $evidence['invoice_number'] === '000004' && $evidence['environment'] === 'production' && $evidence['paid_jpy'] === 500 && $wpdb->logs[1]['reason'] === $fields['reason'], 'exact evidence and identity reason audited' );
check( $invoice_session->closed && count( array_filter( $wpdb->queries, static fn( $s ) => $s === 'begin' ) ) === 1 && count( array_filter( $wpdb->queries, static fn( $s ) => $s === 'commit' ) ) === 1 && $evictions === array( array( 10, 'user_meta' ) ), 'one transaction and cache eviction after commit' );
check( count( $invoice_http_calls ) === 5 && ! str_contains( $invoice_http_calls[3][0], '?' ) && str_contains( $invoice_http_calls[4][0], '/subscriptions/' ), 'approval directly refreshes Invoice and Subscription before DB open' );
check( $wpdb->watches === $original_watches && ! array_filter( $wpdb->sql_queries, static fn( $sql ) => str_contains( $sql, 'checkout_attempts' ) ), 'watch settings and application history never modified' );
$old_logs = $wpdb->logs; $r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && $r['already'] && $wpdb->logs === $old_logs && count( $wpdb->contracts ) === 1, 'duplicate approval no-op' );
$candidate = invoice_candidate(); check( isset( $candidate['approval_notice'] ) && ! isset( $candidate['candidate'] ), 'already Standard hides confirmation action' );
change( 'free', 0 ); check( is_wp_error( approve_invoice( $fields ) ) && count( $wpdb->logs ) === 3, 'old candidate cannot reapprove after Standard-Free cycle' );
$fields = invoice_fields( invoice_candidate() ); $r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && count( $wpdb->contracts ) === 1 && count( $wpdb->logs ) === 4 && $wpdb->logs[3]['action'] === 'standard_approved', 'fresh reapproval after Free preserves contract and appends history' );
reset_invoice_approval( true ); $fields = invoice_fields( invoice_candidate() ); $r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && count( $wpdb->contracts ) === 1 && count( $wpdb->logs ) === 1 && $wpdb->logs[0]['action'] === 'standard_approved', 'same-user environment-known contract reused' );
foreach ( array( 'sandbox', 'other_user', 'other_customer' ) as $case ) {
    reset_invoice_approval( true );
    if ( $case === 'sandbox' ) { $wpdb->contracts[1]['admin_note'] = '申込照合: {"environment":"sandbox"}'; }
    if ( $case === 'other_user' ) { $wpdb->contracts[1]['user_id'] = 20; }
    if ( $case === 'other_customer' ) { $wpdb->contracts[1]['square_customer_id'] = 'OTHER'; }
    $r = invoice_candidate(); check( ! isset( $r['candidate'] ) && isset( $r['approval_notice'] ), 'unsafe existing contract blocked: ' . $case );
}
foreach ( array( 'forbidden', 'nonce', 'unchecked', 'empty_reason', 'tampered', 'expired', 'other_admin', 'other_user', 'settings' ) as $case ) {
    reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $before_calls = count( $invoice_http_calls );
    if ( $case === 'forbidden' ) { $allowed = false; }
    if ( $case === 'nonce' ) { $fields['nonce'] = 'bad'; }
    if ( $case === 'unchecked' ) { $fields['confirmed'] = ''; }
    if ( $case === 'empty_reason' ) { $fields['reason'] = ' '; }
    if ( $case === 'tampered' ) { $fields['candidate'] .= ' '; }
    if ( $case === 'expired' ) { resign_invoice( $fields, 'issued_at', time() - 301 ); }
    if ( $case === 'other_admin' ) { resign_invoice( $fields, 'admin_id', 44 ); }
    if ( $case === 'other_user' ) { resign_invoice( $fields, 'user_id', 20 ); }
    if ( $case === 'settings' ) { resign_invoice( $fields, 'location', 'OTHER' ); }
    check( is_wp_error( approve_invoice( $fields ) ) && ! $invoice_session && count( $invoice_http_calls ) === $before_calls && ! $wpdb->logs, 'reject before HTTP/DB: ' . $case );
}
foreach ( array( 'invoice_version', 'paid', 'customer', 'location', 'variation', 'status' ) as $case ) {
    reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() );
    if ( $case === 'invoice_version' ) { ++$invoice_data['version']; }
    if ( $case === 'paid' ) { $invoice_data['payment_requests'][0]['total_completed_amount_money']['amount'] = 499; }
    if ( $case === 'customer' ) { $subscription_data['customer_id'] = 'OTHER'; }
    if ( $case === 'location' ) { $subscription_data['location_id'] = 'OTHER'; }
    if ( $case === 'variation' ) { $subscription_data['plan_variation_id'] = 'OTHER'; }
    if ( $case === 'status' ) { $invoice_data['status'] = 'REFUNDED'; }
    check( is_wp_error( approve_invoice( $fields ) ) && ! $invoice_session && ! $wpdb->logs, 'Square change stops before transaction: ' . $case );
}
foreach ( array( 'contract', 'meta', 'audit', 'approval_audit', 'begin', 'audit_rollback', 'commit', 'disconnect:commit', 'disconnect:meta' ) as $failure ) {
    reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $wpdb->fail = $failure;
    $r = approve_invoice( $fields );
    check( is_wp_error( $r ) && ! $wpdb->meta && ! $wpdb->contracts && ! $wpdb->logs && $invoice_session->closed, 'atomic rollback/close on failure: ' . $failure );
    if ( in_array( $failure, array( 'audit_rollback', 'commit', 'disconnect:commit', 'disconnect:meta' ), true ) ) { check( $r->code === 'square_invoice_outcome_unknown', 'uncertain result distinct: ' . $failure ); }
}
reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
$r = approve_invoice( $fields ); check( $r->code === 'square_invoice_outcome_unknown' && count( $wpdb->logs ) === 2 && $wpdb->meta[1]['meta_value'] === 'standard' && $evictions, 'lost COMMIT response with applied writes remains unknown and evicts cache' );
reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $wpdb->contracts[2] = ( new InvoiceStore() )->contracts[2]; change( 'standard', 2 );
check( is_wp_error( approve_invoice( $fields ) ) && count( $wpdb->logs ) === 1, 'plan race detected without overwriting concurrent change' );
reset_invoice_approval( true ); $fields = invoice_fields( invoice_candidate() ); $wpdb->contracts[1]['updated_at'] = 'edited';
check( is_wp_error( approve_invoice( $fields ) ) && ! $wpdb->logs, 'contract edit after lookup detected' );
reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $wpdb->contracts[1] = ( new InvoiceStore() )->contracts[1]; $wpdb->contracts[1]['user_id'] = 20;
check( is_wp_error( approve_invoice( $fields ) ) && ! $wpdb->logs, 'other-user registration race refused' );
check( $trial_usage === 2 && $monthly_usage === 37, 'AI counters unchanged' );
reset_invoice_approval(); $fields = invoice_fields( invoice_candidate() ); $cache_failure = true; $r = approve_invoice( $fields );
check( $r->code === 'square_invoice_cache_warning' && count( $wpdb->logs ) === 2 && $wpdb->meta[1]['meta_value'] === 'standard', 'cache exception distinguished from committed approval' );

function reset_legacy_invoice() { reset_invoice_approval( true ); $GLOBALS['wpdb']->contracts[1]['admin_note'] = '旧手動登録のメモ'; $GLOBALS['wpdb']->environment_history = array( array( 'user_id' => 10, 'action' => 'subscription_created', 'new_value' => json_encode( $GLOBALS['wpdb']->contracts[1] ) ) ); }
function confirm_environment_fields( $candidate ) { return invoice_fields( $candidate ) + array( 'environment_confirmed' => '1', 'environment_reason' => '本番DashboardでLocation・契約・顧客を照合した' ); }
reset_legacy_invoice(); change(); change( 'free', 0 ); $past_logs = $wpdb->logs;
$candidate = invoice_candidate();
check( isset( $candidate['candidate'] ) && $candidate['environment_confirmation_required'] && str_contains( $candidate['approval_notice'], 'Square環境情報が未登録' ), 'legacy unknown environment offers provisional candidate with mandatory confirmation' );
check( ! $wpdb->meta || $wpdb->meta[1]['meta_value'] === 'free', 'lookup never changes Free plan or auto-completes environment' );
ob_start(); egov_law_monitor_render_invoice_lookup( 10, 'https://example.test/admin', $candidate ); $html = ob_get_clean();
check( str_contains( $html, 'name="invoice_environment_confirmed" value="1" required' ) && str_contains( $html, 'name="invoice_environment_reason"' ) && str_contains( $html, 'production' ), 'legacy form displays separate environment checkbox, evidence and selected API environment' );
$fields = confirm_environment_fields( $candidate ); $r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && count( $wpdb->contracts ) === 1 && $wpdb->meta[1]['meta_value'] === 'standard' && $wpdb->contracts[1]['admin_note'] === '旧手動登録のメモ', 'legacy contract reapproved without duplicate or note rewrite' );
$environment_log = $wpdb->logs[2]; $environment_data = json_decode( $environment_log['new_value'], true );
check( array_slice( $wpdb->logs, 0, 2 ) === $past_logs && $environment_log['action'] === 'contract_environment_confirmed' && $wpdb->logs[3]['action'] === 'standard_approved', 'old approval history preserved and environment plus approval audited together' );
check( $environment_data['contract_id'] === 1 && $environment_data['environment'] === 'production' && $environment_data['invoice_number'] === '000004' && $environment_log['admin_user_id'] === 99 && $environment_log['reason'] === $fields['environment_reason'] && $environment_log['created_at'] === $now, 'environment confirmation includes contract, invoice, actor, reason and time' );
check( egov_law_monitor_invoice_contract_environment( $wpdb->contracts[1] ) === 'production', 'immutable confirmation establishes environment without magic note text' );
$saved_logs = $wpdb->logs; $r = approve_invoice( $fields );
check( ! is_wp_error( $r ) && $r['already'] && $wpdb->logs === $saved_logs, 'double legacy approval never duplicates environment or approval log' );
change( 'free', 0 ); $candidate = invoice_candidate();
check( isset( $candidate['candidate'] ) && ! $candidate['environment_confirmation_required'], 'later reapproval reads confirmed environment from audit' );
$r = approve_invoice( invoice_fields( $candidate ) );
check( ! is_wp_error( $r ) && count( array_filter( $wpdb->logs, static fn( $row ) => $row['action'] === 'contract_environment_confirmed' ) ) === 1, 'known audited environment needs no repeated supplementation' );

foreach ( array( 'unchecked_environment', 'empty_environment_reason', 'html_environment_reason', 'unchecked_identity', 'empty_identity_reason', 'tampered_environment_requirement' ) as $case ) {
    reset_legacy_invoice(); $fields = confirm_environment_fields( invoice_candidate() ); $call_count = count( $invoice_http_calls );
    if ( $case === 'unchecked_environment' ) { unset( $fields['environment_confirmed'] ); }
    if ( $case === 'empty_environment_reason' ) { $fields['environment_reason'] = ' '; }
    if ( $case === 'html_environment_reason' ) { $fields['environment_reason'] = '<b></b>'; }
    if ( $case === 'unchecked_identity' ) { $fields['confirmed'] = ''; }
    if ( $case === 'empty_identity_reason' ) { $fields['reason'] = ''; }
    if ( $case === 'tampered_environment_requirement' ) { $data = json_decode( $fields['candidate'], true ); $data['environment_confirmation_required'] = false; $fields['candidate'] = json_encode( $data ); }
    $r = approve_invoice( $fields );
    check( is_wp_error( $r ) && ! $invoice_session && count( $invoice_http_calls ) === $call_count && ! $wpdb->logs && ! $wpdb->meta, 'confirmation required before HTTP/DB: ' . $case );
}
reset_legacy_invoice(); $wpdb->contracts[1]['admin_note'] .= '\n請求書照合: {"environment":"production"}'; $candidate = invoice_candidate();
check( $candidate['environment_confirmation_required'] && ! $wpdb->logs, 'appending metadata text does not bypass environment confirmation' );
reset_legacy_invoice(); $wpdb->contracts[1]['admin_note'] = '請求書照合: {"environment":"production"}'; $candidate = invoice_candidate();
check( $candidate['environment_confirmation_required'], 'even replacing an empty or legacy note with magic metadata cannot establish provenance' );
reset_invoice_approval( true ); $wpdb->contracts[1]['admin_note'] = '管理者がメモを編集'; $candidate = invoice_candidate();
check( ! $candidate['environment_confirmation_required'], 'immutable creation evidence survives later note edits' );
reset_legacy_invoice(); $fields = confirm_environment_fields( invoice_candidate() );
$wpdb->logs[] = array( 'id' => 1, 'user_id' => 10, 'action' => 'contract_environment_confirmed', 'new_value' => json_encode( array( 'contract_id' => 1, 'subscription_id' => 'SUB1', 'customer_id' => 'CUSTOMER', 'environment' => 'production' ) ) );
check( is_wp_error( approve_invoice( $fields ) ) && count( $wpdb->logs ) === 1 && ! $wpdb->meta, 'concurrent environment confirmation invalidates stale unknown candidate' );
reset_legacy_invoice(); $wpdb->environment_history = array_fill( 0, 101, $wpdb->environment_history[0] ); $candidate = invoice_candidate();
check( ! isset( $candidate['candidate'] ) && str_contains( $candidate['approval_notice'], '環境確認履歴' ), 'incomplete bounded history never treated as unknown and overridden' );
reset_legacy_invoice(); $wpdb->read_fail = 'global_usermeta'; $candidate = invoice_candidate();
check( ! isset( $candidate['candidate'] ) && str_contains( $candidate['approval_notice'], '現在のプラン' ), 'plan-read failure distinct from unknown environment' );
foreach ( array( 'sandbox' => '保存環境', 'other_user' => '所属ユーザー', 'other_customer' => 'Customer ID', 'history_read_failure' => '環境確認履歴' ) as $case => $message ) {
    reset_legacy_invoice();
    if ( $case === 'sandbox' ) { $wpdb->contracts[1]['admin_note'] = '申込照合: {"environment":"sandbox"}'; }
    if ( $case === 'other_user' ) { $wpdb->contracts[1]['user_id'] = 20; }
    if ( $case === 'other_customer' ) { $wpdb->contracts[1]['square_customer_id'] = 'OTHER'; }
    if ( $case === 'history_read_failure' ) { $wpdb->read_fail = 'SELECT action, new_value'; }
    $r = invoice_candidate(); check( ! isset( $r['candidate'] ) && str_contains( $r['approval_notice'], $message ), 'distinct safe refusal reason: ' . $case );
}
foreach ( array( 'note', 'owner', 'customer' ) as $race ) {
    reset_legacy_invoice(); $fields = confirm_environment_fields( invoice_candidate() );
    if ( $race === 'note' ) { $wpdb->contracts[1]['admin_note'] = '申込照合: {"environment":"sandbox"}'; }
    if ( $race === 'owner' ) { $wpdb->contracts[1]['user_id'] = 20; }
    if ( $race === 'customer' ) { $wpdb->contracts[1]['square_customer_id'] = 'OTHER'; }
    check( is_wp_error( approve_invoice( $fields ) ) && ! $wpdb->logs && ! $wpdb->meta, 'legacy contract race rejected under transaction: ' . $race );
}
foreach ( array( 'meta', 'contract', 'audit', 'approval_audit', 'audit_rollback', 'commit', 'disconnect:meta' ) as $failure ) {
    reset_legacy_invoice(); $fields = confirm_environment_fields( invoice_candidate() ); $original_contract = $wpdb->contracts[1]; $wpdb->fail = $failure;
    $r = approve_invoice( $fields );
    check( is_wp_error( $r ) && ! $wpdb->logs && ! $wpdb->meta && $wpdb->contracts[1] === $original_contract && $invoice_session->closed, 'environment and approval remain atomic on failure: ' . $failure );
    if ( in_array( $failure, array( 'audit_rollback', 'commit', 'disconnect:meta' ), true ) ) { check( $r->code === 'square_invoice_outcome_unknown', 'legacy uncertain result distinguished: ' . $failure ); }
}
reset_legacy_invoice(); $fields = confirm_environment_fields( invoice_candidate() ); $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
$r = approve_invoice( $fields );
check( $r->code === 'square_invoice_outcome_unknown' && $wpdb->meta[1]['meta_value'] === 'standard' && count( $wpdb->logs ) === 2 && egov_law_monitor_invoice_contract_environment( $wpdb->contracts[1] ) === 'production', 'applied COMMIT response loss retains atomic environment and plan but remains unknown' );
echo ( $checks - $invoice_checks_start ) . " invoice approval checks passed; no real DB or Square API used.\n";
