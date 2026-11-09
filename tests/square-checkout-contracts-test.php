<?php
/** Offline API + dedicated-session integration for explicit checkout registration. */
require __DIR__ . '/square-checkout-test.php';
require __DIR__ . '/../wordpress-plugin/includes/square-checkout-contracts.php';
$contract_baseline = $checks;
class ContractLookupStore extends CheckoutStore {
    public function select( $sql, $p ) {
        if ( str_contains( $sql, 'egov_square_subscriptions' ) && str_contains( $sql, 'WHERE square_subscription_id' ) ) { return array_values( array_filter( $this->contracts, static fn( $r ) => $r['square_subscription_id'] === $p[0] && $r['id'] !== $p[1] ) ); }
        return parent::select( $sql, $p );
    }
}
class ContractLookupSession extends CheckoutSession {
    private $source;
    public function __construct( $source ) { $this->source = $source; parent::__construct( $source ); }
    public function write( $sql, $p = array() ) {
        if ( str_contains( $sql, 'admin_log' ) && count( $p ) === 6 ) { array_splice( $p, 5, 0, array( null ) ); }
        if ( str_contains( $sql, 'egov_square_subscriptions' ) && str_starts_with( $sql, 'INSERT' ) ) {
            if ( $this->source->fail === 'contract' ) { return false; }
            foreach ( $this->local->contracts as $row ) { if ( $row['square_subscription_id'] === $p[1] ) { return false; } }
            $this->insert_id = count( $this->local->contracts ) + 1;
            $this->local->contracts[$this->insert_id] = array_combine( array( 'square_customer_id', 'square_subscription_id', 'admin_note', 'updated_at', 'user_id', 'created_at' ), $p ) + array( 'id' => $this->insert_id, 'verified_at' => null, 'verified_by' => null );
            return true;
        }
        return parent::write( $sql, $p );
    }
}
function contract_http( $url, $args ) {
    check( $GLOBALS['checkout_active'] === 0, 'contract HTTP outside all database transactions' );
    $GLOBALS['contract_calls'][] = array( $url, $args );
    $mode = $GLOBALS['contract_mode'];
    if ( $GLOBALS['contract_hook'] ) { $hook = $GLOBALS['contract_hook']; $GLOBALS['contract_hook'] = null; $hook(); }
    if ( $mode === 'timeout' ) { return new WP_Error( 'timeout', 'test-checkout-secret' ); }
    if ( $mode === 'throw' ) { throw new RuntimeException( 'test-checkout-secret' ); }
    if ( in_array( $mode, array( '401', '403', '429', '500' ), true ) ) { return array( 'response' => array( 'code' => (int) $mode ), 'body' => 'test-checkout-secret' ); }
    if ( $mode === 'json' ) { return array( 'response' => array( 'code' => 200 ), 'body' => 'bad' ); }
    if ( str_contains( $url, '/orders/' ) ) {
        $data = array( 'order' => array( 'id' => 'ORDER', 'reference_id' => $GLOBALS['wpdb']->attempts[1]['application_uuid'], 'location_id' => 'LOCATION' ) );
        if ( $mode === 'order' ) { $data['order']['reference_id'] = 'other'; }
    } else {
        $sub = array( 'id' => 'SUB', 'customer_id' => 'CUSTOMER', 'location_id' => 'LOCATION', 'plan_variation_id' => 'VARIATION', 'status' => 'ACTIVE', 'phases' => array( array( 'order_template_id' => 'ORDER' ) ) );
        if ( $mode === 'location' ) { $sub['location_id'] = 'OTHER'; }
        if ( $mode === 'variation' ) { $sub['plan_variation_id'] = 'OTHER'; }
        if ( $mode === 'canceled' ) { $sub['status'] = 'CANCELED'; }
        if ( $mode === 'customer_missing' ) { unset( $sub['customer_id'] ); }
        if ( $mode === 'phases' ) { unset( $sub['phases'] ); }
        if ( $mode === 'phase_missing_id' ) { $sub['phases'] = array( array() ); }
        if ( $mode === 'secret' ) { $sub['customer_id'] = 'test-checkout-secret'; }
        if ( $mode === 'multi_phase' ) { $sub['phases'][] = array( 'order_template_id' => 'ORDER' ); }
        $data = array( 'subscriptions' => $mode === 'none' ? array() : array( $sub ) );
        if ( $mode === 'multiple' ) { $data['subscriptions'][] = array_replace( $sub, array( 'id' => 'SUB2' ) ); }
        $body = json_decode( $args['body'], true );
        if ( $mode === 'pages' && ! isset( $body['cursor'] ) ) { $data = array( 'subscriptions' => array(), 'cursor' => 'NEXT' ); }
        if ( $mode === 'limit' ) { $data['cursor'] = 'PAGE' . count( $GLOBALS['contract_calls'] ); }
        if ( $mode === 'loop' ) { $data['cursor'] = 'SAME'; }
        if ( $mode === 'cursor_invalid' ) { $data['cursor'] = array( 'bad' ); }
        if ( $mode === 'oversized_page' ) { $data['subscriptions'] = array_fill( 0, 101, $sub ); }
        if ( $mode === 'late_multiple' && ! isset( $body['cursor'] ) ) { $data['cursor'] = 'NEXT'; }
        elseif ( $mode === 'late_multiple' ) { $data['subscriptions'][0]['id'] = 'OTHER_SUB'; }
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
}
function lookup_reset( $mode = 'ok' ) {
    unset( $GLOBALS['checkout_contract_transport'] ); checkout_reset(); $GLOBALS['wpdb'] = new ContractLookupStore(); $GLOBALS['wpdb']->contracts = array(); submit();
    $GLOBALS['contract_mode'] = $mode; $GLOBALS['contract_hook'] = null; $GLOBALS['contract_calls'] = array(); $GLOBALS['checkout_contract_transport'] = 'contract_http';
}
function lookup_fields( $register = false, $candidate = null ) {
    return array( 'attempt_id' => '1', 'attempt_token' => egov_law_monitor_square_record_token( $GLOBALS['wpdb']->attempts[1] ),
        'attempt_nonce' => 'valid:egov_checkout_contract_' . ( $register ? 'register' : 'lookup' ) . '_10_1',
        'candidate' => $candidate ? json_encode( $candidate ) : '', 'candidate_signature' => $candidate ? egov_law_monitor_checkout_candidate_signature( $candidate ) : '' );
}
function lookup_contract( $register = false, $fields = null, $user_id = 10, $factory = null ) {
    return egov_law_monitor_checkout_contract_operation( $user_id, $fields ?? lookup_fields(), $register, $factory ?? static fn() => new ContractLookupSession( $GLOBALS['wpdb'] ) );
}
lookup_reset(); $before = array( $wpdb->attempts, $wpdb->meta, $wpdb->watches, $trial_usage, $monthly_usage );
$candidate = lookup_contract();
check( is_array( $candidate ) && $candidate['id'] === 'SUB' && $candidate['customer_id'] === 'CUSTOMER' && count( $contract_calls ) === 2, 'one exact match yields minimal candidate after original order check' );
check( ! $wpdb->contracts && ! $wpdb->logs, 'lookup never registers or audits a contract automatically' );
$id = lookup_contract( true, lookup_fields( true, $candidate ) );
check( $id === 1 && count( $contract_calls ) === 4 && count( $wpdb->contracts ) === 1 && count( $wpdb->logs ) === 1, 'explicit confirmation repeats API lookup then atomically saves contract and audit' );
$note = $wpdb->contracts[1]['admin_note'];
check( str_contains( $note, $wpdb->attempts[1]['application_uuid'] ) && str_contains( $note, 'sandbox' ) && str_contains( $note, 'ORDER' ) && str_contains( $note, 'checked_at' ) && ! str_contains( $note, 'record_token' ), 'memo and audited contract contain only necessary reconciliation metadata' );
check( $wpdb->contracts[1]['verified_at'] === null && $wpdb->contracts[1]['verified_by'] === null && $wpdb->logs[0]['action'] === 'subscription_created', 'registration is unverified and reuses existing creation audit' );
check( $before === array( $wpdb->attempts, $wpdb->meta, $wpdb->watches, $trial_usage, $monthly_usage ), 'registration does not alter application plan usage or watches' );
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ) ) ) && count( $wpdb->logs ) === 1, 'duplicate registration cannot duplicate contract or audit' );
foreach ( array( 'none', 'multiple', 'late_multiple', 'limit', 'loop', 'cursor_invalid', 'oversized_page', 'phases', 'phase_missing_id', 'customer_missing', 'location', 'variation', 'canceled', 'order', 'timeout', 'throw', '401', '403', '429', '500', 'json', 'secret' ) as $mode ) {
    lookup_reset( $mode ); $result = lookup_contract();
    check( is_wp_error( $result ) && ! $wpdb->contracts && ! $wpdb->logs && ! str_contains( $result->get_error_message(), 'test-checkout-secret' ), 'lookup fails closed without raw secrets: ' . $mode );
}
foreach ( array( 'pages', 'multi_phase' ) as $mode ) {
    lookup_reset( $mode ); check( is_array( lookup_contract() ), 'complete multi-page or multiple matching phases resolves unique subscription: ' . $mode );
}
lookup_reset(); $fields = lookup_fields(); $fields['attempt_nonce'] = 'wrong';
check( is_wp_error( lookup_contract( false, $fields ) ) && ! $contract_calls, 'invalid nonce stops before HTTP' );
$fields = lookup_fields(); $fields['attempt_token'] = 'stale';
check( is_wp_error( lookup_contract( false, $fields ) ) && ! $contract_calls, 'stale signed application form rejected before HTTP' );
$allowed = false;
check( is_wp_error( lookup_contract() ) && ! $contract_calls, 'non-administrator cannot lookup' );
lookup_reset(); $fields = lookup_fields(); $fields['attempt_nonce'] = 'valid:egov_checkout_contract_lookup_20_1';
check( is_wp_error( lookup_contract( false, $fields, 20 ) ) && ! $contract_calls, 'another user application is rejected' );
foreach ( array( 'production', 'bad' ) as $environment ) {
    lookup_reset(); $wpdb->attempts[1]['environment'] = $environment;
    check( is_wp_error( lookup_contract() ) && ! $contract_calls, 'saved environment must match selected token environment: ' . $environment );
}
foreach ( array( 'cancel_requested', 'closed', 'unknown', 'creating' ) as $state ) {
    lookup_reset(); $wpdb->attempts[1]['state'] = $state;
    check( is_wp_error( lookup_contract() ) && ! $contract_calls, 'ineligible application state rejected: ' . $state );
}
lookup_reset(); $candidate = lookup_contract(); $fields = lookup_fields( true, $candidate );
$candidate['customer_id'] = 'TAMPER'; $fields['candidate'] = json_encode( $candidate ); $contract_calls = array();
check( is_wp_error( lookup_contract( true, $fields ) ) && ! $contract_calls, 'tampered browser candidate rejected before network' );
lookup_reset(); $candidate = lookup_contract(); $candidate['queried_at'] -= 301; $contract_calls = array();
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ) ) ) && ! $contract_calls, 'signed but expired candidate rejected' );
lookup_reset(); $candidate = lookup_contract(); $candidate['admin_id'] = 20; $contract_calls = array();
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ) ) ) && ! $contract_calls, 'candidate bound to confirming administrator' );
lookup_reset(); $candidate = lookup_contract(); $candidate['customer_id'] = 'OLD';
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ) ) ) && ! $wpdb->contracts, 'fresh API response must equal signed candidate' );
lookup_reset(); $fields = lookup_fields(); $contract_hook = static function () { $GLOBALS['wpdb']->attempts[1]['state'] = 'cancel_requested'; };
check( is_wp_error( lookup_contract( false, $fields ) ) && ! $wpdb->contracts, 'cancellation during lookup invalidates candidate' );
lookup_reset(); $candidate = lookup_contract();
$wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 20, 'square_subscription_id' => 'SUB' );
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ) ) ) && count( $wpdb->contracts ) === 1 && ! $wpdb->logs, 'subscription belonging to another local user is never moved or duplicated' );
lookup_reset(); $candidate = lookup_contract();
$factory = static function () { $GLOBALS['wpdb']->attempts[1]['state'] = 'cancel_requested'; return new ContractLookupSession( $GLOBALS['wpdb'] ); };
check( is_wp_error( lookup_contract( true, lookup_fields( true, $candidate ), 10, $factory ) ) && ! $wpdb->contracts && ! $wpdb->logs, 'transaction rechecks state after last API response before writing' );
foreach ( array( 'audit', 'audit_rollback', 'contract', 'commit', 'disconnect:commit' ) as $failure ) {
    lookup_reset(); $candidate = lookup_contract(); $wpdb->fail = $failure;
    $result = lookup_contract( true, lookup_fields( true, $candidate ) );
    check( is_wp_error( $result ) && ! $wpdb->contracts && ! $wpdb->logs, 'registration atomic failure: ' . $failure );
}
lookup_reset(); $candidate = lookup_contract(); $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
check( lookup_contract( true, lookup_fields( true, $candidate ) )->code === 'square_save_outcome_unknown' && count( $wpdb->contracts ) === 1 && count( $wpdb->logs ) === 1, 'lost COMMIT response distinguished even if registration applied' );
lookup_reset(); $candidate = lookup_contract(); ob_start(); egov_law_monitor_render_checkout_contract_result( 10, $candidate ); $html = ob_get_clean();
check( str_contains( $html, 'この契約を登録' ) && str_contains( $html, 'candidate_signature' ) && str_contains( $html, 'Location ID' ) && ! str_contains( $html, 'test-checkout-secret' ), 'minimal candidate confirmation form is escaped and signed' );
ob_start(); egov_law_monitor_render_checkout_attempts( 10 ); $html = ob_get_clean();
check( str_contains( $html, 'Square契約を照会' ) && str_contains( $html, 'https://developer.squareup.com/apps' ) && str_contains( $html, '閲覧に対応していません' ), 'application row includes lookup and honest Sandbox dashboard fallback' );
echo ( $checks - $contract_baseline ) . " checkout contract checks passed; no real DB or Square API used.\n";
