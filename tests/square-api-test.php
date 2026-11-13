<?php
/** Run: php tests/square-api-test.php. All HTTP calls are deterministic doubles. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'EGOV_LAW_MONITOR_META_PLAN', 'egov_law_monitor_plan' );
define( 'EGOV_LAW_MONITOR_PLAN_FREE', 'free' );
define( 'EGOV_LAW_MONITOR_PLAN_STANDARD', 'standard' );
class WP_Error {
    public function __construct( public $code, public $message ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return $GLOBALS['allowed']; }
function get_userdata( $id ) { return in_array( (int) $id, array( 10, 20 ), true ) ? (object) array( 'ID' => $id, 'user_login' => 'user', 'display_name' => 'User', 'user_email' => 'user@example.test' ) : false; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
function current_time( $format ) { return '2026-11-08 12:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-only-key'; }
function add_action( ...$args ) {}
function register_activation_hook( ...$args ) {}
function add_shortcode( ...$args ) {}
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['http_calls'][] = array( $url, $args );
    if ( $GLOBALS['during_http'] ) { ( $GLOBALS['during_http'] )(); }
    return $GLOBALS['http_result'];
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function admin_url( $path ) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = null ) {
    if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
    return $url . '&' . http_build_query( array( $key => $value ) );
}
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'valid:' . $action ) . '">'; }
function submit_button( $text, $type, $name ) { echo '<button name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function egov_law_monitor_get_plan( $id ) { return $id === 20 || ! empty( $GLOBALS['display_standard'] ) ? 'standard' : 'free'; }
function egov_law_monitor_get_plan_label( $plan ) { return ucfirst( $plan ); }
function egov_law_monitor_get_notifications( $id ) { return $id !== 20; }
function egov_law_monitor_get_ai_summary_trial_usage_count( $id ) { return 2; }
function egov_law_monitor_get_ai_summary_limit( $id ) { return $id === 20 ? 100 : 3; }
function egov_law_monitor_get_ai_summary_usage_count( $id ) { return 37; }
function get_users( $args ) { return array_map( static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => 'user' . $id . '@example.test', 'user_registered' => '2026-01-01' ), array( 10, 20 ) ); }
function selected( $a, $b ) { if ( $a === $b ) { echo 'selected'; } }
function checked( $a, $b ) { if ( $a === $b ) { echo 'checked'; } }
function wp_date( $format, $timestamp ) { return date( $format, $timestamp ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
// Any accidental persistence is a test failure.
function update_user_meta( ...$args ) { throw new LogicException( 'unexpected plan write' ); }
function update_option( ...$args ) { throw new LogicException( 'unexpected settings write' ); }
function set_transient( ...$args ) { throw new LogicException( 'unexpected lookup persistence' ); }

class SquareLookupDatabase {
    public $prefix = 'wp_';
    public $users = 'wp_users';
    public $usermeta = 'wp_usermeta';
    public $last_error = '';
    public $records = array();
    public $fail = false;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_row( $query, $mode ) {
        $this->last_error = $this->fail ? 'test DB failure' : '';
        if ( $this->fail ) { return null; }
        list( $sql, $args ) = $query;
        if ( str_starts_with( $sql, 'SHOW' ) ) { return array( 'Engine' => 'InnoDB' ); }
        $record = $this->records[$args[0]] ?? null;
        return $record && (int) $record['user_id'] === $args[1] ? $record : null;
    }
    public function get_results( $query, $mode ) {
        $this->last_error = '';
        if ( is_array( $query ) ) {
            if ( str_contains( $query[0], 'checkout_attempts' ) ) { return array(); }
            if ( str_contains( $query[0], 'usermeta' ) ) { return array(); }
            return str_contains( $query[0], 'admin_log' ) ? array() : array_values( array_filter( $this->records, static fn( $row ) => (int) $row['user_id'] === $query[1][0] ) );
        }
        if ( str_contains( $query, 'law_watch_settings' ) ) { return array( array( 'user_id' => 10, 'keyword' => '環境' ), array( 'user_id' => 10, 'keyword' => '行政' ) ); }
        $suffix = str_contains( $query, 'admin_log' ) ? 'egov_square_admin_log' : 'egov_square_subscriptions';
        if ( str_starts_with( $query, 'SHOW FULL' ) ) {
            $columns = array();
            foreach ( egov_law_monitor_square_columns()[$suffix] as $name => $definition ) {
                $columns[] = array( 'Field' => $name, 'Type' => $definition[0], 'Null' => $definition[1] ? 'YES' : 'NO', 'Extra' => $name === 'id' ? 'auto_increment' : '' );
            }
            return $columns;
        }
        $indexes = array(
            array( 'Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0, 'Sub_part' => null ),
            array( 'Key_name' => 'user_id', 'Seq_in_index' => 1, 'Column_name' => 'user_id', 'Non_unique' => 1, 'Sub_part' => null ),
        );
        if ( $suffix === 'egov_square_subscriptions' ) { $indexes[] = array( 'Key_name' => 'square_subscription_id', 'Seq_in_index' => 1, 'Column_name' => 'square_subscription_id', 'Non_unique' => 0, 'Sub_part' => null ); }
        return $indexes;
    }
    public function __call( $name, $args ) { throw new LogicException( 'unexpected DB operation: ' . $name ); }
}
require __DIR__ . '/../wordpress-plugin/includes/square-subscriptions.php';
require __DIR__ . '/../wordpress-plugin/includes/square-api.php';
require __DIR__ . '/../wordpress-plugin/includes/square-approval.php';
require __DIR__ . '/../wordpress-plugin/includes/square-invoices.php';
require __DIR__ . '/../wordpress-plugin/includes/square-cancellations.php';
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-square-subscriptions.php';
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-users.php';
$checks = 0;
function check_lookup( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['checks'];
    echo "PASS: {$label}\n";
}
function render_lookup_page( $post = array() ) {
    $_GET = array( 'user_id' => 10 ); $_POST = $post; ob_start();
    try { egov_law_monitor_render_square_subscriptions(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
$old_token = getenv( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN' );
$old_environment = getenv( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' );
try {
    // Constants cannot be redefined: configuration cases run in isolated PHP processes.
    $settings_case = $argv[1] ?? 'default';
    $fixture = array( 'environment' => 'sandbox', 'sandbox' => 'test-only-square-secret', 'production' => 'test-only-production-secret' );
    switch ( $settings_case ) {
        case 'production': $fixture['environment'] = 'production'; break;
        case 'sandbox_only': unset( $fixture['production'] ); break;
        case 'production_only': $fixture['environment'] = 'production'; unset( $fixture['sandbox'] ); break;
        case 'missing_sandbox': unset( $fixture['sandbox'] ); break;
        case 'missing_production': $fixture['environment'] = 'production'; unset( $fixture['production'] ); break;
        case 'empty_sandbox': $fixture['sandbox'] = ''; break;
        case 'empty_production': $fixture['environment'] = 'production'; $fixture['production'] = ''; break;
        case 'newline_token': $fixture['sandbox'] = "bad\r\nheader"; break;
        case 'space_token': $fixture['sandbox'] = 'contains space'; break;
        case 'nonstring_token': $fixture['sandbox'] = false; break;
        case 'long_token': $fixture['sandbox'] = str_repeat( 'x', 4097 ); break;
        case 'invalid_environment': $fixture['environment'] = 'https://untrusted.example'; break;
        case 'nonstring_environment': $fixture['environment'] = array( 'sandbox' ); break;
        case 'missing_environment': unset( $fixture['environment'] ); break;
        case 'legacy_only': unset( $fixture['sandbox'], $fixture['production'] ); break;
        case 'environment_variables_only': $fixture = array(); break;
        case 'default': break;
        default: throw new LogicException( 'unknown settings test case' );
    }
    foreach ( array( 'environment' => 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'sandbox' => 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX', 'production' => 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION' ) as $key => $name ) {
        if ( array_key_exists( $key, $fixture ) ) { define( $name, $fixture[$key] ); }
    }
    define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN', 'ignored-legacy-token' );
    putenv( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN=test-only-square-secret' );
    putenv( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT=sandbox' );
    $http_calls = array(); $during_http = null;
    if ( $settings_case !== 'default' ) {
        $result = egov_law_monitor_square_api_settings();
        if ( in_array( $settings_case, array( 'production', 'sandbox_only', 'production_only' ), true ) ) {
            check_lookup( is_array( $result ) && $result['environment'] === $fixture['environment'], 'configured environment selected' );
            check_lookup( $result['token'] === $fixture[$fixture['environment']], 'matching environment token selected' );
            check_lookup( ! $http_calls, 'configuration never calls Square' );
        } else {
            check_lookup( $result instanceof WP_Error && $result->code === 'square_api_not_configured', 'invalid selected configuration refused' );
            check_lookup( ! $http_calls, 'no fallback and no HTTP request' );
            check_lookup( ! str_contains( $result->message, 'test-only-square-secret' ) && ! str_contains( $result->message, 'test-only-production-secret' ), 'error hides credentials' );
        }
        echo "{$checks} settings checks passed.\n"; return;
    }
    $allowed = true; $wpdb = new SquareLookupDatabase();
    $wpdb->records[1] = array( 'id' => 1, 'user_id' => 10, 'square_customer_id' => 'CUSTOMER_1', 'square_subscription_id' => 'SUB_1', 'verified_at' => null, 'verified_by' => null, 'admin_note' => '<script>note</script>', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00' );
    foreach ( array( 'production', 'sandbox_only', 'production_only', 'missing_sandbox', 'missing_production', 'empty_sandbox', 'empty_production', 'newline_token', 'space_token', 'nonstring_token', 'long_token', 'invalid_environment', 'nonstring_environment', 'missing_environment', 'legacy_only', 'environment_variables_only' ) as $case ) {
        $process = proc_open( array( PHP_BINARY, __FILE__, $case ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        if ( ! is_resource( $process ) ) { throw new RuntimeException( 'cannot start settings test process' ); }
        $output = stream_get_contents( $pipes[1] );
        $errors = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit_code = proc_close( $process );
        check_lookup( $exit_code === 0 && str_contains( $output, '3 settings checks passed.' ) && $errors === '', 'isolated constant configuration: ' . $case );
    }

    $html = render_lookup_page();
    check_lookup( ! $http_calls && str_contains( $html, '請求書番号でSquare契約を照会' ), 'normal render is read-only and invoice-focused' );
    $invoice_guidance = '請求書番号で契約・支払いを照会し、本人確認後に契約登録とStandard承認を行ってください。照会だけでは利用権限は変わりません。';
    check_lookup( str_contains( $html, $invoice_guidance ), 'Free user sees introductory invoice approval guidance' );
    foreach ( array( 'egov_square_save', 'egov_square_lookup', 'egov_square_approve', 'egov_checkout_cancel', 'egov_checkout_contract_lookup', 'egov_checkout_contract_register', 'egov_square_checkout', 'egov_checkout_cancel_request' ) as $retired ) {
        check_lookup( ! str_contains( $html, 'name="' . $retired . '"' ), 'retired control absent: ' . $retired );
        try { render_lookup_page( array( $retired => '1' ) ); throw new LogicException( 'retired POST accepted' ); }
        catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '廃止' ) && ! $http_calls, 'retired POST blocked without API or writes: ' . $retired ); }
    }
    check_lookup( str_contains( $html, 'SUB_1' ) && str_contains( $html, '&lt;script&gt;note&lt;/script&gt;' ), 'saved contracts and escaped notes remain visible' );
    $allowed = false;
    try { render_lookup_page(); throw new LogicException( 'unauthorized page accepted' ); }
    catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '権限' ), 'administrator required' ); }
    $allowed = true;
    $_POST = array();
    ob_start();
    try { egov_law_monitor_render_admin_users(); $html = ob_get_contents(); }
    finally { ob_end_clean(); }
    check_lookup( ! str_contains( $html, '<form' ) && ! str_contains( $html, '<select' ) && ! str_contains( $html, '<input' ) && ! str_contains( $html, '<button' ), 'user management list is completely read-only' );
    check_lookup( str_contains( $html, 'Free' ) && str_contains( $html, 'Standard' ) && ! str_contains( $html, '無料' ) && ! str_contains( $html, 'スタンダード' ) && str_contains( $html, 'ON' ) && str_contains( $html, 'OFF' ), 'plan and notification current values render as text' );
    preg_match_all( '/<th>(.*?)<\/th>/', $html, $headers );
    check_lookup( $headers[1] === array( 'ユーザーID', 'ユーザー', 'メールアドレス', 'プラン', '通知', 'ウォッチキーワード', 'AI要約', '登録日', '操作' ), 'all nine columns and original order preserved' );
    check_lookup( str_contains( $html, '環境、行政' ) && preg_match( '/2\s*\/\s*3回/u', $html ) && preg_match( '/37\s*\/\s*100回/u', $html ) && str_contains( $html, '2026年1月1日' ), 'watch formatting usage counters and registration date formatting preserved' );
    check_lookup( str_contains( $html, 'user_id=10' ) && str_contains( $html, 'user_id=20' ), 'each user has own Square contract link' );
    foreach ( array( 'egov_law_monitor_save_user', 'egov_law_monitor_clear_ai_summary' ) as $old_action ) {
        $_POST = array( $old_action => '1', 'user_id' => '10', 'plan' => 'standard', 'notifications' => '1', 'ai_summary_user_id' => '10' );
        ob_start();
        try { egov_law_monitor_render_admin_users(); throw new LogicException( 'old POST accepted' ); }
        catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '閲覧専用' ), 'old save/reset POST refused without writes' ); }
        finally { ob_end_clean(); }
    }
    $display_standard = true;
    $html = render_lookup_page();
    check_lookup( ! str_contains( $html, $invoice_guidance ) && str_contains( $html, '<details><summary>請求書照会・Standard承認</summary>' ), 'Standard user omits introductory guidance but retains folded invoice form' );
    check_lookup( str_contains( $html, 'egov_square_free_nonce' ) && str_contains( $html, 'free_reason' ) && str_contains( $html, 'Square側の解約・課金停止は行いません' ), 'user-level Free form requires reason and clarifies Square billing unchanged' );
    check_lookup( str_contains( $html, 'Standard承認を解除' ) && str_contains( $html, 'この操作はWordPressの利用権限のみを変更し、Squareのサブスクリプションは解約しません' ) && str_contains( $html, 'name="free_confirmed" value="1" required' ), 'revoke approval form explicitly requires confirmation and warns Square is unchanged' );
    check_lookup( str_contains( $html, '請求書番号でSquare契約を照会' ) && str_contains( $html, 'invoice_nonce' ) && str_contains( $html, 'user@example.test' ), 'invoice read-only form sits alongside selected WordPress identity' );
    check_lookup( str_contains( $html, '契約・解約の状態：確認が必要' ) && str_contains( $html, '<details><summary>例外的な管理操作：Standard承認を解除</summary>' ) && str_contains( $html, '<details><summary>請求書照会・Standard承認</summary>' ), 'unverifiable contract overview exposes warning and folds optional operations without removing forms' );
    check_lookup( str_contains( $html, '<details><summary>Square操作履歴（最新100件・旧操作の履歴を含む）</summary>' ) && str_contains( $html, '<h2>登録済みSquare契約</h2>' ), 'audit initially folded while registered contract table remains available' );
    $html = render_lookup_page( array( 'egov_square_invoice_lookup' => '1', 'invoice_number' => '000004', 'invoice_nonce' => 'bad' ) );
    check_lookup( str_contains( $html, 'nonceを確認できません' ), 'invoice controller preserves nonce error' );
    check_lookup( ! str_contains( $html, '<details><summary>請求書照会・Standard承認</summary>' ), 'invoice POST result and error are never hidden in optional lookup fold' );
    try { render_lookup_page( array( 'egov_square_invoice_lookup' => '1', 'egov_square_save' => '1' ) ); throw new LogicException( 'mixed invoice/save accepted' ); }
    catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '廃止' ), 'retired save POST refused even alongside invoice lookup' ); }
    $html = render_lookup_page( array( 'egov_square_invoice_approve' => '1', 'invoice_approve_nonce' => 'bad' ) );
    check_lookup( str_contains( $html, 'nonceを確認できません' ), 'invoice approval controller validates nonce' );
    try { render_lookup_page( array( 'egov_square_invoice_approve' => '1', 'egov_square_invoice_lookup' => '1' ) ); throw new LogicException( 'mixed invoice lookup/approval accepted' ); }
    catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '別々' ), 'invoice lookup and approval remain separate operations' ); }
    $html = render_lookup_page( array( 'egov_square_free' => '1', 'egov_square_free_nonce' => 'valid:egov_square_free_10' ) );
    check_lookup( str_contains( $html, '解除することを確認してください' ), 'valid nonce alone cannot execute revocation without confirmation' );
    $html = render_lookup_page( array( 'egov_square_free' => '1', 'egov_square_free_nonce' => 'wrong' ) );
    check_lookup( str_contains( $html, '操作を確認できません' ), 'Free form rejects invalid nonce' );
    try { render_lookup_page( array( 'egov_square_free' => '1', 'egov_square_approve' => '1' ) ); throw new LogicException( 'mixed Free accepted' ); }
    catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '廃止' ), 'retired approval POST refused even alongside Free operation' ); }
    $display_standard = false;
} finally {
    putenv( $old_token === false ? 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN' : 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN=' . $old_token );
    putenv( $old_environment === false ? 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' : 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT=' . $old_environment );
}
echo "{$checks} checks passed.\n";
