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
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-square-subscriptions.php';
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-users.php';
$checks = 0;
function check_lookup( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['checks'];
    echo "PASS: {$label}\n";
}
function api_response( $subscription, $status = 200 ) { return array( 'response' => array( 'code' => $status ), 'body' => json_encode( array( 'subscription' => $subscription ) ) ); }
function lookup( $user = 10, $id = 1, $nonce = null, $token = null ) {
    $record = $GLOBALS['wpdb']->records[$id] ?? array();
    return egov_law_monitor_lookup_square_subscription( $user, $id, $nonce ?? 'valid:egov_square_lookup_' . $user . '_' . $id, $token ?? egov_law_monitor_square_record_token( $record ) );
}
function render_lookup_page( $post = array() ) {
    $_GET = array( 'user_id' => 10 );
    $_POST = $post;
    ob_start();
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
    $allowed = true;
    $wpdb = new SquareLookupDatabase();
    $wpdb->records[1] = array( 'id' => 1, 'user_id' => 10, 'square_customer_id' => 'CUSTOMER_1', 'square_subscription_id' => 'SUB_1', 'verified_at' => null, 'verified_by' => null, 'admin_note' => '', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00' );
    $stored = $wpdb->records;
    $subscription = array( 'id' => 'SUB_1', 'customer_id' => 'CUSTOMER_1', 'status' => 'ACTIVE', 'plan_variation_id' => 'VAR_1', 'invoice_ids' => array( 'NOT_DISPLAYED' ), 'card_id' => 'NOT_DISPLAYED' );
    $http_result = api_response( $subscription );
    $http_calls = array();
    $during_http = null;
    if ( $settings_case !== 'default' ) {
        $result = lookup();
        if ( in_array( $settings_case, array( 'production', 'sandbox_only', 'production_only' ), true ) ) {
            $environment = $fixture['environment'];
            $origin = $environment === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
            check_lookup( is_array( $result ) && count( $http_calls ) === 1 && $http_calls[0][0] === $origin . '/v2/subscriptions/SUB_1', 'environment selects correct API origin' );
            check_lookup( $http_calls[0][1]['headers']['Authorization'] === 'Bearer ' . $fixture[$environment], 'selected environment constant supplies token' );
            check_lookup( ! str_contains( json_encode( $result ), $fixture[$environment] ) && $wpdb->records === $stored, 'selected token stays private and contract unchanged' );
        } else {
            check_lookup( $result instanceof WP_Error && $result->code === 'square_api_not_configured', 'invalid selected configuration refused' );
            check_lookup( ! $http_calls, 'no fallback and no HTTP request' );
            check_lookup( ! str_contains( $result->message, 'test-only-square-secret' ) && ! str_contains( $result->message, 'test-only-production-secret' ) && ! str_contains( $result->message, 'ignored-legacy-token' ), 'configuration error never exposes token values' );
        }
        echo "{$checks} settings checks passed.\n";
        return;
    }
    check_lookup( ! $http_calls, 'loading API helpers makes no request' );
    $result = lookup();
    check_lookup( is_array( $result ) && $result['customer_matches'] === true && $result['status'] === 'ACTIVE', 'subscription and exact customer match returned' );
    check_lookup( $result['plan_variation_id'] === 'VAR_1' && $result['queried_at'] === '2026-11-08 12:00:00', 'plan ID and query time returned' );
    check_lookup( count( $http_calls ) === 1 && $http_calls[0][0] === 'https://connect.squareupsandbox.com/v2/subscriptions/SUB_1', 'one GET request to fixed sandbox endpoint' );
    $args = $http_calls[0][1];
    check_lookup( $args['timeout'] === 10 && $args['redirection'] === 0 && $args['sslverify'] === true && $args['limit_response_size'] === 65536, 'timeout TLS redirect and size limits set' );
    check_lookup( $args['headers']['Authorization'] === 'Bearer test-only-square-secret' && $args['headers']['Square-Version'] === '2026-09-16', 'server-side authentication and pinned API version' );
    check_lookup( ! str_contains( json_encode( $result ), 'test-only-square-secret' ) && ! isset( $result['invoice_ids'], $result['card_id'] ), 'secret and unrelated API fields not exposed' );
    check_lookup( $wpdb->records === $stored, 'lookup never changes contract or verification fields' );
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

    $count = count( $http_calls );
    $allowed = false;
    check_lookup( lookup()->code === 'square_lookup_forbidden', 'unauthorized caller refused' );
    $allowed = true;
    check_lookup( lookup( 10, 1, 'wrong' )->code === 'square_lookup_invalid_request', 'invalid nonce refused' );
    check_lookup( lookup( 10, 1, 'valid:egov_square_lookup_20_1' )->code === 'square_lookup_invalid_request', 'nonce cannot be moved to another user' );
    check_lookup( lookup( 999 )->code === 'square_lookup_invalid_user', 'missing user refused' );
    check_lookup( lookup( 20 )->code === 'square_lookup_record_not_found', 'other user contract refused' );
    check_lookup( lookup( 10, 999 )->code === 'square_lookup_record_not_found', 'missing contract refused' );
    check_lookup( lookup( 10, 1, null, 'stale' )->code === 'square_lookup_stale_record', 'stale or forged contract snapshot refused' );
    $wpdb->fail = true;
    check_lookup( lookup()->code === 'square_lookup_storage_error', 'DB read failure handled separately' );
    $wpdb->fail = false;
    $wpdb->records[1]['square_subscription_id'] = '../../other';
    check_lookup( lookup()->code === 'square_lookup_invalid_ids', 'invalid stored ID cannot change API path' );
    $wpdb->records = $stored;
    check_lookup( count( $http_calls ) === $count, 'rejected requests make no HTTP call' );

    foreach ( array( 401 => '認証', 403 => 'SUBSCRIPTIONS_READ', 404 => '見つかりません', 429 => '呼び出し制限', 500 => 'エラー', 302 => 'エラー' ) as $status => $message ) {
        $http_result = array( 'response' => array( 'code' => $status ), 'body' => 'test-only-square-secret <script>error</script>' );
        $result = lookup();
        check_lookup( $result instanceof WP_Error && str_contains( $result->message, $message ) && ! str_contains( $result->message, 'test-only-square-secret' ), "HTTP {$status} mapped without raw response" );
    }
    $http_result = new WP_Error( 'http_request_failed', 'timeout test-only-square-secret' );
    check_lookup( lookup()->code === 'square_lookup_transport_error' && ! str_contains( lookup()->message, 'test-only-square-secret' ), 'transport errors never expose underlying messages' );
    $during_http = function () { throw new RuntimeException( 'HTTP extension error test-only-square-secret' ); };
    $result = lookup();
    check_lookup( $result->code === 'square_lookup_transport_error' && ! str_contains( $result->message, 'test-only-square-secret' ), 'HTTP exceptions are contained without credential leakage' );
    $during_http = null;
    $http_result = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'subscription' => $subscription, 'errors' => array( array( 'detail' => 'test-only-square-secret' ) ) ) ) );
    check_lookup( lookup()->code === 'square_lookup_invalid_response', 'error-bearing success response is refused' );
    foreach ( array( 'not JSON', 'null', '[]', '{"subscription":[]}', str_repeat( 'x', 65537 ) ) as $body ) {
        $http_result = array( 'response' => array( 'code' => 200 ), 'body' => $body );
        check_lookup( lookup()->code === 'square_lookup_invalid_response', 'malformed or oversized body rejected' );
    }
    foreach ( array( array( 'id', 'WRONG_ID' ), array( 'status', '<script>alert(1)</script>' ), array( 'status', array() ), array( 'customer_id', null ), array( 'plan_variation_id', array() ), array( 'plan_variation_id', 'test-only-square-secret' ) ) as $bad ) {
        $payload = $subscription;
        $payload[$bad[0]] = $bad[1];
        $http_result = api_response( $payload );
        check_lookup( lookup()->code === 'square_lookup_invalid_response', 'invalid or secret-reflecting response fields rejected' );
    }
    $payload = $subscription;
    $payload['customer_id'] = 'OTHER_CUSTOMER';
    $http_result = api_response( $payload );
    check_lookup( lookup()['customer_matches'] === false && $wpdb->records === $stored, 'customer mismatch is displayed, never relinked' );
    unset( $payload['customer_id'] );
    $http_result = api_response( $payload );
    check_lookup( lookup()['customer_matches'] === null, 'missing customer is indeterminate rather than a match' );
    unset( $payload['plan_variation_id'] );
    $payload['plan_id'] = 'LEGACY_PLAN';
    $http_result = api_response( $payload );
    check_lookup( lookup()['plan_id'] === 'LEGACY_PLAN' && lookup()['plan_variation_id'] === '', 'legacy plan ID supported without Catalog API calls' );
    unset( $payload['plan_id'] );
    $http_result = api_response( $payload );
    check_lookup( lookup()['plan_id'] === '', 'missing plan remains unacquired' );
    $http_result = api_response( $subscription );
    $during_http = function () { $GLOBALS['wpdb']->records[1]['square_customer_id'] = 'CHANGED'; };
    check_lookup( lookup()->code === 'square_lookup_stale_record', 'contract changes during request cannot produce a trusted result' );
    $wpdb->records = $stored;
    $during_http = null;

    $count = count( $http_calls );
    $html = render_lookup_page();
    check_lookup( count( $http_calls ) === $count && str_contains( $html, 'Square契約状況を確認' ), 'normal page render shows button but never queries API' );
    check_lookup( str_contains( $html, 'この契約でStandardを承認する' ) && str_contains( $html, 'dashboard_confirmed' ) && str_contains( $html, 'approval_reason' ) && str_contains( $html, 'approval_token' ), 'approval form requires explicit confirmation and signed state' );
    $html = render_lookup_page( array( 'egov_square_lookup' => '1', 'lookup_record_id' => '1', 'egov_square_lookup_nonce' => 'valid:egov_square_lookup_10_1', 'lookup_record_token' => egov_law_monitor_square_record_token( $stored[1] ) ) );
    check_lookup( count( $http_calls ) === $count + 1 && str_contains( $html, 'ACTIVE' ) && str_contains( $html, 'VAR_1' ), 'button renders transient status and plan result' );
    check_lookup( ! str_contains( $html, 'test-only-square-secret' ) && $wpdb->records === $stored, 'rendered lookup exposes no token and persists nothing' );
    $payload = $subscription;
    $payload['customer_id'] = 'OTHER_CUSTOMER';
    $http_result = api_response( $payload );
    $html = render_lookup_page( array( 'egov_square_lookup' => '1', 'lookup_record_id' => '1', 'egov_square_lookup_nonce' => 'valid:egov_square_lookup_10_1', 'lookup_record_token' => egov_law_monitor_square_record_token( $stored[1] ) ) );
    check_lookup( str_contains( $html, 'Customer IDが一致しません' ) && str_contains( $html, 'OTHER_CUSTOMER' ) && $wpdb->records === $stored, 'customer mismatch renders a warning without saving' );
    $http_result = array( 'response' => array( 'code' => 401 ), 'body' => 'test-only-square-secret <script>raw</script>' );
    $html = render_lookup_page( array( 'egov_square_lookup' => '1', 'lookup_record_id' => '1', 'egov_square_lookup_nonce' => 'valid:egov_square_lookup_10_1', 'lookup_record_token' => egov_law_monitor_square_record_token( $stored[1] ) ) );
    check_lookup( str_contains( $html, '認証に失敗' ) && str_contains( $html, '照会操作日時' ) && ! str_contains( $html, 'test-only-square-secret' ) && ! str_contains( $html, '<script>raw</script>' ), 'API error and attempt time render without raw body' );
    $wpdb->records[1]['admin_note'] = '<script>note</script>';
    $html = render_lookup_page();
    check_lookup( str_contains( $html, '&lt;script&gt;note&lt;/script&gt;' ) && ! str_contains( $html, '<script>note</script>' ), 'existing contract display remains escaped' );
    try {
        render_lookup_page( array( 'egov_square_lookup' => '1', 'egov_square_save' => '1' ) );
        throw new LogicException( 'mixed action accepted' );
    } catch ( RuntimeException $error ) {
        check_lookup( str_contains( $error->getMessage(), '別々' ), 'lookup and save cannot execute in the same request' );
    }
    try {
        render_lookup_page( array( 'egov_square_approve' => '1', 'egov_square_lookup' => '1' ) );
        throw new LogicException( 'mixed approval accepted' );
    } catch ( RuntimeException $error ) {
        check_lookup( str_contains( $error->getMessage(), '別々' ), 'approval and lookup cannot execute together' );
    }
    $html = render_lookup_page( array( 'egov_square_approve' => '1', 'approval_record_id' => '1', 'egov_square_approve_nonce' => 'wrong' ) );
    check_lookup( str_contains( $html, '操作を確認できません' ) && $wpdb->records[1]['verified_at'] === null, 'bad approval nonce renders error without changing confirmation' );
    $_POST = array();
    ob_start();
    try { egov_law_monitor_render_admin_users(); $html = ob_get_contents(); }
    finally { ob_end_clean(); }
    check_lookup( ! str_contains( $html, '<form' ) && ! str_contains( $html, '<select' ) && ! str_contains( $html, '<input' ) && ! str_contains( $html, '<button' ), 'user management list is completely read-only' );
    check_lookup( str_contains( $html, '無料' ) && str_contains( $html, 'スタンダード' ) && str_contains( $html, 'ON' ) && str_contains( $html, 'OFF' ), 'plan and notification current values render as text' );
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
    check_lookup( str_contains( $html, 'egov_square_free_nonce' ) && str_contains( $html, 'free_reason' ) && str_contains( $html, 'Square側の解約・課金停止は行いません' ), 'user-level Free form requires reason and clarifies Square billing unchanged' );
    $html = render_lookup_page( array( 'egov_square_free' => '1', 'egov_square_free_nonce' => 'wrong' ) );
    check_lookup( str_contains( $html, '操作を確認できません' ), 'Free form rejects invalid nonce' );
    try { render_lookup_page( array( 'egov_square_free' => '1', 'egov_square_approve' => '1' ) ); throw new LogicException( 'mixed Free accepted' ); }
    catch ( RuntimeException $error ) { check_lookup( str_contains( $error->getMessage(), '別々' ), 'Free and approval cannot execute together' ); }
    $display_standard = false;
} finally {
    putenv( $old_token === false ? 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN' : 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN=' . $old_token );
    putenv( $old_environment === false ? 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' : 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT=' . $old_environment );
}
echo "{$checks} checks passed.\n";
