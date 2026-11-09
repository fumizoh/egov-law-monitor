<?php
/** Offline integration doubles; also reruns the existing approval regression harness. */
require __DIR__ . '/square-approval-test.php';
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'sandbox' );
define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', true );
define( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX', 'test-checkout-secret' );
define( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED', true );
define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX', 'LOCATION' );
define( 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_SANDBOX', 'VARIATION' );
define( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL', 'https://wp.example.test/mypage/' );
function is_user_logged_in() { return $GLOBALS['checkout_logged_in']; }
function home_url( $path ) { return 'https://wp.example.test' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_generate_uuid4() { static $id = 0; return '00000000-0000-4000-8000-' . str_pad( (string) ++$id, 12, '0', STR_PAD_LEFT ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function add_shortcode( ...$args ) {}
function nocache_headers() { $GLOBALS['nocache'] = true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function wp_nonce_field( $action, $name ) { echo '<input name="' . esc_attr( $name ) . '" value="valid:' . esc_attr( $action ) . '">'; }
function get_option( $name ) { return $GLOBALS['options'][$name] ?? ''; }
function update_option( $name, $value, $autoload ) { $GLOBALS['options'][$name] = $value; }
function dbDelta( $sql ) { $GLOBALS['ddl'][] = $sql; }
function wp_safe_remote_post( $url, $args ) {
    if ( isset( $GLOBALS['checkout_contract_transport'] ) ) { return ( $GLOBALS['checkout_contract_transport'] )( $url, $args ); }
    check( $GLOBALS['checkout_active'] === 0, 'HTTP runs outside all DB transactions' );
    $GLOBALS['http'][] = array( $url, $args );
    if ( $GLOBALS['http_hook'] ) { ( $GLOBALS['http_hook'] )(); }
    $mode = $GLOBALS['http_mode'];
    if ( $mode === 'timeout' ) { return new WP_Error( 'timeout', 'private test-checkout-secret' ); }
    if ( $mode === 'throw' ) { throw new RuntimeException( 'test-checkout-secret' ); }
    if ( $mode === '401' ) { return array( 'response' => array( 'code' => 401 ), 'body' => 'test-checkout-secret' ); }
    if ( $mode === 'json' ) { return array( 'response' => array( 'code' => 200 ), 'body' => 'bad test-checkout-secret' ); }
    if ( $mode === 'oversized' ) { return array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'x', 131073 ) ); }
    $request = json_decode( $args['body'], true );
    $result = array( 'payment_link' => array( 'id' => 'LINK', 'order_id' => 'ORDER', 'url' => 'https://sandbox.square.link/u/test' ),
        'related_resources' => array( 'orders' => array( array( 'id' => 'ORDER', 'location_id' => 'LOCATION', 'reference_id' => $request['order']['reference_id'], 'total_money' => array( 'amount' => 500, 'currency' => 'JPY' ) ) ) ) );
    if ( $mode === 'price' ) { $result['related_resources']['orders'][0]['total_money']['amount'] = 550; }
    if ( $mode === 'currency' ) { $result['related_resources']['orders'][0]['total_money']['currency'] = 'USD'; }
    if ( $mode === 'errors' ) { $result['errors'] = array( array( 'detail' => 'test-checkout-secret' ) ); }
    if ( $mode === 'reference' ) { $result['related_resources']['orders'][0]['reference_id'] = 'wrong'; }
    if ( $mode === 'url' ) { $result['payment_link']['url'] = 'https://square.link/u/production'; }
    if ( $mode === 'secret' ) { $result['payment_link']['id'] = 'test-checkout-secret'; }
    if ( $mode === 'no_order' ) { unset( $result['related_resources'] ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $result ) );
}
require __DIR__ . '/../wordpress-plugin/includes/square-api.php';
require __DIR__ . '/../wordpress-plugin/includes/square-checkout.php';
require __DIR__ . '/../wordpress-plugin/includes/square-checkout-cancel.php';
require __DIR__ . '/../wordpress-plugin/includes/square-checkout-page.php';

class CheckoutStore extends ApprovalStore {
    public $attempts = array();
    public $checkout_bad = '';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_row( $query, $mode ) { return $this->select( $query[0], $query[1] )[0] ?? null; }
    public function get_results( $query, $mode ) { return is_string( $query ) ? $this->select( $query, array() ) : parent::get_results( $query, $mode ); }
    public function select( $sql, $p ) {
        if ( str_contains( $sql, 'egov_square_subscriptions' ) && str_contains( $sql, 'WHERE user_id' ) ) { return array_values( array_filter( $this->contracts, static fn( $row ) => $row['user_id'] === $p[0] ) ); }
        if ( str_starts_with( $sql, 'SHOW TABLE' ) && str_contains( $p[0] ?? '', 'checkout_attempts' ) ) { return array( array( 'Engine' => $this->checkout_bad === 'engine' ? 'MyISAM' : 'InnoDB' ) ); }
        if ( ! str_contains( $sql, 'checkout_attempts' ) ) { return parent::select( $sql, $p ); }
        if ( str_starts_with( $sql, 'SHOW FULL' ) ) {
            $definitions = array( 'id' => 'bigint unsigned', 'application_uuid' => 'char(36)', 'user_id' => 'bigint unsigned', 'environment' => 'varchar(10)', 'request_json' => 'longtext', 'payment_link_id' => 'varchar(255)', 'template_order_id' => 'varchar(255)', 'checkout_url' => 'text', 'state' => 'varchar(20)', 'claim_token' => 'char(32)', 'claimed_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime' );
            $columns = array();
            foreach ( $definitions as $name => $type ) { $columns[] = array( 'Field' => $name, 'Type' => $this->checkout_bad === 'column' ? 'wrong' : $type, 'Null' => in_array( $name, array( 'payment_link_id', 'template_order_id', 'checkout_url' ), true ) ? 'YES' : 'NO', 'Extra' => $name === 'id' ? 'auto_increment' : '' ); }
            return $columns;
        }
        if ( str_starts_with( $sql, 'SHOW INDEX' ) ) {
            $groups = array( 'PRIMARY' => array( 'id' ), 'uuid' => array( 'application_uuid' ), 'link' => array( 'environment', 'payment_link_id' ), 'user' => array( 'user_id' ) );
            unset( $groups[$this->checkout_bad] );
            $rows = array();
            foreach ( $groups as $key => $names ) { foreach ( $names as $i => $name ) { $rows[] = array( 'Key_name' => $key, 'Seq_in_index' => $i + 1, 'Column_name' => $name, 'Non_unique' => $key === 'user' ? 1 : 0, 'Sub_part' => $this->checkout_bad === 'prefix' ? 10 : null ); } }
            return $rows;
        }
        if ( str_contains( $sql, 'WHERE id = ?' ) ) {
            $row = $this->attempts[$p[0]] ?? null;
            return $row && $row['user_id'] === $p[1] ? array( $row ) : array();
        }
        $rows = array_values( array_filter( $this->attempts, static fn( $row ) => $row['user_id'] === $p[0] && ( ! str_contains( $sql, "state <> 'closed'" ) || $row['state'] !== 'closed' ) ) );
        usort( $rows, static fn( $a, $b ) => $b['id'] <=> $a['id'] );
        return array_slice( $rows, 0, str_contains( $sql, 'LIMIT 2' ) ? 2 : 50 );
    }
}
class CheckoutSession extends ApprovalSession {
    private $source;
    private $active = false;
    public function __construct( $source ) { $this->source = $source; parent::__construct( $source ); }
    public function begin() { $ok = parent::begin(); if ( $ok ) { $this->active = true; ++$GLOBALS['checkout_active']; } return $ok; }
    public function commit() {
        $ok = parent::commit();
        if ( $ok || $this->source->commit_applied ) { $this->source->attempts = $this->local->attempts; }
        if ( $this->active ) { --$GLOBALS['checkout_active']; $this->active = false; }
        return $ok;
    }
    public function close() { if ( $this->active ) { --$GLOBALS['checkout_active']; $this->active = false; } parent::close(); }
    public function write( $sql, $p = array() ) {
        if ( str_contains( $sql, 'admin_log' ) ) { return parent::write( $sql, $p ); }
        if ( ! str_contains( $sql, 'checkout_attempts' ) ) { throw new LogicException( 'Unexpected mutation outside applications' ); }
        if ( str_contains( $sql, 'SET state = ?, updated_at = ?' ) ) {
            if ( $this->source->fail === 'cancel_noop' ) { return true; }
            if ( $this->source->fail === 'cancel_write' ) { return false; }
            $row =& $this->local->attempts[$p[2]];
            if ( $row['user_id'] !== $p[3] ) { return false; }
            $row['state'] = $p[0]; $row['updated_at'] = $p[1];
            return true;
        }
        $insert = str_starts_with( $sql, 'INSERT' );
        $final = str_contains( $sql, 'SET state = ?' );
        if ( $this->source->fail === ( $final ? 'result_write' : 'insert_attempt' ) ) { return false; }
        if ( $insert ) {
            $id = count( $this->local->attempts ) + 1;
            $this->insert_id = $id;
            $this->local->attempts[$id] = array_combine( array( 'application_uuid', 'user_id', 'environment', 'request_json', 'claim_token', 'claimed_at', 'created_at', 'updated_at' ), $p ) + array( 'id' => $id, 'state' => 'creating', 'payment_link_id' => null, 'template_order_id' => null, 'checkout_url' => null );
        } elseif ( $final ) {
            $row =& $this->local->attempts[$p[5]];
            if ( $row['user_id'] !== $p[6] || $row['claim_token'] !== $p[7] ) { return false; }
            foreach ( array( 'state', 'payment_link_id', 'template_order_id', 'checkout_url', 'updated_at' ) as $i => $key ) { $row[$key] = $p[$i]; }
        } else {
            $row =& $this->local->attempts[$p[3]];
            foreach ( array( 'claim_token', 'claimed_at', 'updated_at' ) as $i => $key ) { $row[$key] = $p[$i]; }
            $row['state'] = 'creating';
        }
        return true;
    }
}
function checkout_reset() {
    unset( $GLOBALS['egov_checkout_cancel_post'] ); $_SERVER['REQUEST_METHOD'] = 'GET';
    reset_store(); $GLOBALS['wpdb'] = new CheckoutStore(); $GLOBALS['wpdb']->contracts = array();
    $GLOBALS['checkout_user'] = 10; $GLOBALS['checkout_logged_in'] = true; $GLOBALS['checkout_active'] = 0;
    $GLOBALS['http'] = array(); $GLOBALS['http_hook'] = null; $GLOBALS['http_mode'] = 'ok';
    $GLOBALS['options'] = array(); $GLOBALS['ddl'] = array(); $_POST = array();
}
function submit( $uuid = '', $nonce = 'valid:egov_square_checkout_10' ) {
    return egov_law_monitor_submit_checkout( $nonce, $uuid, static fn() => new CheckoutSession( $GLOBALS['wpdb'] ) );
}
function age_claim( $seconds ) { $GLOBALS['wpdb']->attempts[1]['claimed_at'] = gmdate( 'Y-m-d H:i:s', time() - $seconds ); }
$baseline = $checks;
checkout_reset(); $settings = egov_law_monitor_checkout_settings();
check( $settings['variation_id'] === 'VARIATION' && $settings['environment'] === 'sandbox', 'environment-specific server configuration selected' );
$invalid_settings = array_replace( $settings, array( 'environment' => 'invalid' ) );
$invalid_uuid = wp_generate_uuid4();
check( egov_law_monitor_create_checkout_link( $invalid_settings, array( 'environment' => 'invalid', 'application_uuid' => $invalid_uuid, 'request_json' => json_encode( egov_law_monitor_checkout_request( $invalid_settings, $invalid_uuid ) ) ) ) instanceof WP_Error && ! $http, 'API boundary also rejects invalid environment before choosing any host' );
$contracts = $wpdb->contracts; $plan = $wpdb->meta;
$_POST = array( 'user_id' => 20, 'amount' => 1, 'location_id' => 'ATTACKER', 'plan_variation_id' => 'ATTACKER' );
$result = submit(); $uuid = $result['application_uuid'];
check( $result['state'] === 'ready' && count( $http ) === 1 && $wpdb->attempts[1]['user_id'] === 10, 'server UUID and logged-in owner persisted before HTTP' );
$body = json_decode( $http[0][1]['body'], true );
check( $body['idempotency_key'] === $uuid && $body['order']['reference_id'] === $uuid && strlen( $uuid ) === 36, 'same UUID used for idempotency and order reference' );
check( $body['order']['line_items'][0]['base_price_money'] === array( 'amount' => 500, 'currency' => 'JPY' ) && $body['order']['location_id'] === 'LOCATION' && $body['checkout_options']['subscription_plan_id'] === 'VARIATION', 'posted owner/price/location/variation tampering ignored' );
check( $http[0][0] === 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links' && $http[0][1]['sslverify'] && $http[0][1]['redirection'] === 0, 'fixed Sandbox POST and secure HTTP options' );
check( $http[0][1]['headers']['Square-Version'] === '2026-09-16' && $http[0][1]['timeout'] === 20 && $http[0][1]['limit_response_size'] === 131072, 'snapshot API version timeout and response size are fixed' );
check( $wpdb->meta === $plan && $wpdb->contracts === $contracts && ! $wpdb->logs, 'link creation never mutates plan contracts verification audit or usage' );
check( ! str_contains( json_encode( $wpdb->attempts ), 'test-checkout-secret' ) && ! isset( $result['environment'], $result['request_json'], $result['claim_token'], $result['payment_link_id'] ), 'no credentials stored and internal settings excluded from public result' );
check( submit()['checkout_url'] === $result['checkout_url'] && count( $http ) === 1, 'ready link is redisplayed without another request' );
check( submit( wp_generate_uuid4() ) instanceof WP_Error && count( $http ) === 1, 'wrong application UUID rejected' );
$checkout_user = 20;
check( submit( $uuid, 'valid:egov_square_checkout_20' )->code === 'checkout_wrong_owner' && count( $http ) === 1, 'another user cannot recover or view an application by UUID' );
checkout_reset(); $checkout_logged_in = false;
check( submit()->code === 'checkout_login_required' && ! $http, 'logged-out caller refused' );
checkout_reset();
check( submit( '', 'wrong' )->code === 'checkout_invalid_request' && ! $http, 'nonce refused before storage or HTTP' );
$wpdb->meta[1] = array( 'umeta_id' => 1, 'user_id' => 10, 'meta_key' => EGOV_LAW_MONITOR_META_PLAN, 'meta_value' => 'standard' );
check( submit()->code === 'checkout_free_only' && ! $http, 'Standard caller refused using authoritative DB state' );
checkout_reset(); $wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
check( submit()->code === 'checkout_existing_contract' && ! $http, 'historical contract blocks new billing even for Free user' );
checkout_reset(); $http_hook = function () {
    check( count( $GLOBALS['wpdb']->attempts ) === 1 && $GLOBALS['wpdb']->attempts[1]['state'] === 'creating', 'initial attempt is durable before network' );
    check( submit()->code === 'checkout_busy', 'simultaneous click sees active claim and makes no second HTTP request' );
};
check( submit()['state'] === 'ready' && count( $http ) === 1, 'simultaneous clicks yield one application and request' );
foreach ( array( 'timeout', 'throw', '401', 'json', 'price', 'currency', 'errors', 'oversized', 'reference', 'url', 'secret', 'no_order' ) as $mode ) {
    checkout_reset(); $http_mode = $mode;
    $result = submit();
    check( $result instanceof WP_Error && $wpdb->attempts[1]['state'] === 'unknown' && ! str_contains( $result->get_error_message(), 'test-checkout-secret' ), 'HTTP failure/unsafe response kept unresolved without leaks: ' . $mode );
}
checkout_reset(); $http_mode = 'timeout'; submit(); $first = $http[0][1]['body']; $uuid = $wpdb->attempts[1]['application_uuid'];
check( submit( $uuid )->code === 'checkout_busy' && count( $http ) === 1, 'unknown outcome has retry cooldown' );
age_claim( 31 ); $http_mode = 'ok';
check( submit( $uuid )['state'] === 'ready' && $http[1][1]['body'] === $first && count( $wpdb->attempts ) === 1, 'manual recovery sends identical UUID key and request' );
checkout_reset(); $http_mode = 'timeout'; submit(); age_claim( 91 ); $wpdb->attempts[1]['environment'] = 'production';
check( submit()->code === 'checkout_conflict' && count( $http ) === 1, 'environment mixing refused' );
checkout_reset(); $http_mode = 'timeout'; submit(); age_claim( 91 ); $wpdb->attempts[1]['request_json'] = str_replace( 'VARIATION', 'OTHER', $wpdb->attempts[1]['request_json'] );
check( submit()->code === 'checkout_conflict' && count( $http ) === 1, 'changed server/request snapshot never creates a different retry' );
checkout_reset(); $http_mode = 'timeout'; submit(); age_claim( 91 ); $wpdb->attempts[1]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 901 );
check( submit()->code === 'checkout_review_required' && count( $http ) === 1, 'old unknown applications require review, never fresh UUID' );
checkout_reset(); $http_hook = function () { $GLOBALS['wpdb']->fail = 'result_write'; };
check( submit()->code === 'checkout_storage_error' && $wpdb->attempts[1]['state'] === 'creating', 'result persistence failure withholds link and preserves original attempt' );
$first = $http[0][1]['body']; $wpdb->fail = ''; $http_hook = null; age_claim( 91 );
check( submit()['state'] === 'ready' && $http[1][1]['body'] === $first, 'result-save failure recovers using identical request after lease' );
foreach ( array( false, true ) as $applied ) {
    checkout_reset(); $wpdb->fail = 'commit'; $wpdb->commit_applied = $applied;
    check( submit()->code === 'checkout_storage_error' && ! $http, 'uncertain reservation COMMIT never starts HTTP' );
}
foreach ( array( 'begin', 'insert_attempt' ) as $failure ) {
    checkout_reset(); $wpdb->fail = $failure;
    check( submit()->code === 'checkout_storage_error' && ! $http && ! $wpdb->attempts, 'reservation failure cannot call API or create untracked link: ' . $failure );
}
foreach ( array( false, true ) as $applied ) {
    checkout_reset(); $http_hook = static function () use ( $applied ) { $GLOBALS['wpdb']->fail = 'commit'; $GLOBALS['wpdb']->commit_applied = $applied; };
    check( submit()->code === 'checkout_storage_error' && count( $http ) === 1, 'result COMMIT uncertainty never reports success' );
    $first = $http[0][1]['body']; $http_hook = null; $wpdb->fail = ''; $wpdb->commit_applied = false; age_claim( 91 );
    $recovered = submit();
    check( $recovered['state'] === 'ready' && count( $http ) === ( $applied ? 1 : 2 ) && end( $http )[1]['body'] === $first, 'unknown result COMMIT recovers without changing idempotency payload' );
}
checkout_reset(); $http_hook = static function () {
    $GLOBALS['http_hook'] = null; age_claim( 91 ); $GLOBALS['second_claim'] = submit();
};
check( submit()->code === 'checkout_storage_error' && $second_claim['state'] === 'ready' && $wpdb->attempts[1]['state'] === 'ready', 'expired first worker cannot overwrite newer claim results' );
check( count( $http ) === 2 && $http[0][1]['body'] === $http[1][1]['body'], 'lease recovery uses the same key even when original worker resumes' );
foreach ( array( 'engine', 'column', 'PRIMARY', 'uuid', 'link', 'user', 'prefix' ) as $bad ) {
    checkout_reset(); $wpdb->checkout_bad = $bad;
    check( ! egov_law_monitor_checkout_storage_ready(), 'invalid physical checkout schema rejected: ' . $bad );
    egov_law_monitor_install_checkout_table();
    check( ! isset( $options['egov_law_monitor_checkout_schema_version'] ), 'invalid schema never advances version: ' . $bad );
    check( submit() instanceof WP_Error && ! $http, 'invalid schema blocks API issuance: ' . $bad );
}
checkout_reset(); egov_law_monitor_install_checkout_table();
check( $options['egov_law_monitor_checkout_schema_version'] === '1' && count( $ddl ) === 1, 'activation/update installs only new table and sets verified schema version' );
egov_law_monitor_install_checkout_table();
check( count( $ddl ) === 1 && ! preg_match( '/DROP|TRUNCATE|DELETE/i', $ddl[0] ), 'valid update skips DDL and never initializes existing data' );
checkout_reset();
$html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, '月額500円（税込）' ) && ! $http && ! str_contains( $html, 'LOCATION' ) && ! str_contains( $html, 'VARIATION' ), 'GET shortcode exposes no settings and makes no API call' );
$previous_position = -1;
foreach ( array( '<h2>Standardプラン（月額500円・税込）</h2>', '<p>Standardプランをご利用いただくには、下のボタンからお申し込みください。</p>', '<p>お申し込み後、Squareの決済ページでお支払いいただけます。</p>', '<p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>', '<button type="submit" name="egov_square_checkout">Standard 月額500円（税込）を申し込む</button>' ) as $part ) {
    $position = strpos( $html, $part );
    check( $position !== false && $position > $previous_position, 'new application paragraph/button order: ' . $part );
    $previous_position = $position;
}
check( ! str_contains( $html, 'Standardプランのお支払いは、Squareの決済ページで行います。' ) && ! str_contains( $html, '切り替えが完了するまで、しばらくお待ちください。' ), 'new application replaces the old introductory copy without duplication' );
check( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'personal checkout panel requests page cache exclusion' );
submit(); $html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, 'Squareでお支払いへ進む' ) && count( $http ) === 1 && ! str_contains( $html, 'test-checkout-secret' ), 'GET/return displays owned link and keeps Free without HTTP' );
$_POST = array( 'egov_square_checkout' => '1', 'egov_checkout_nonce' => 'wrong' );
check( str_contains( egov_law_monitor_standard_application_shortcode(), '再読み込み' ) && count( $http ) === 1, 'shortcode POST validates nonce without any HTTP' );
$_POST = array(); $checkout_logged_in = false;
check( str_contains( egov_law_monitor_standard_application_shortcode(), 'ログイン' ) && count( $http ) === 1, 'logged-out shortcode hides application and link' );
$checkout_logged_in = true;
$checkout_user = 20;
check( ! str_contains( egov_law_monitor_standard_application_shortcode(), 'sandbox.square.link' ), 'another user cannot see checkout URL' );
ob_start(); egov_law_monitor_render_checkout_attempts( 10 ); $html = ob_get_clean();
check( str_contains( $html, 'LINK' ) && str_contains( $html, 'ORDER' ) && ! str_contains( $html, 'test-checkout-secret' ) && ! str_contains( $html, 'request_json' ), 'admin applications show needed IDs but not internal payload' );
$allowed = false; ob_start(); egov_law_monitor_render_checkout_attempts( 10 ); $html = ob_get_clean();
check( $html === '', 'non-admin cannot render application records' );
checkout_reset(); submit(); $http = array();
$html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, 'Squareでお支払いへ進む' ) && ! $http, 'valid owned ready link displays without HTTP' );
$wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
$html = egov_law_monitor_standard_application_shortcode();
check( ! str_contains( $html, 'https://sandbox.square.link' ) && str_contains( $html, '登録済みのSquare契約' ), 'registered contract hides link with an explanation' );
$wpdb->contracts = array(); $wpdb->read_fail = 'egov_square_subscriptions';
$html = egov_law_monitor_standard_application_shortcode();
check( ! str_contains( $html, 'https://sandbox.square.link' ) && str_contains( $html, '契約情報を取得できない' ) && ! str_contains( $html, 'private read error' ), 'contract read failure hides link with sanitized reason' );
$wpdb->read_fail = '';
foreach ( array( null, '', 'https://evil.example/pay', 'https://square.link/u/production' ) as $url ) {
    $wpdb->attempts[1]['checkout_url'] = $url;
    $html = egov_law_monitor_standard_application_shortcode();
    check( ! str_contains( $html, 'Squareでお支払いへ進む' ) && ! str_contains( $html, 'name="egov_square_checkout"' ) && str_contains( $html, '欠落・不正' ) && ! $http, 'missing/unsafe ready URL never enables reissuance' );
}
// Constants are tested in isolated processes; these cases never construct an HTTP request.
$program = <<<'PHP'
define('ABSPATH', __DIR__.'/');
class WP_Error { public function __construct(public $code, public $message) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function home_url($p) { return 'https://wp.example.test'.$p; }
function wp_parse_url($v) { return parse_url($v); }
function add_action(...$v) {}
function register_activation_hook(...$v) {}
$fixture=json_decode(base64_decode($argv[1]),true);
foreach($fixture as $k=>$v){define($k,$v);}
require getcwd().'/wordpress-plugin/includes/square-api.php';
require getcwd().'/wordpress-plugin/includes/square-checkout.php';
$s=egov_law_monitor_checkout_settings();
echo is_wp_error($s)?'ERROR':($s['environment'].':'.($s['token']===($fixture['EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_'.strtoupper($s['environment'])]??null)?'TOKEN_OK':'BAD'));
PHP;
$fixture = array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => true, 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' => 'sandbox', 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX' => 'dummy-sandbox', 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION' => 'dummy-production',
    'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED' => true, 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX' => 'LOC', 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_SANDBOX' => 'VAR',
    'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION' => 'PRODLOC', 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_PRODUCTION' => 'PRODVAR', 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' => 'https://wp.example.test/mypage/' );
$cases = array( 'sandbox' => array(), 'production' => array( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' => 'production' ),
    'bad_environment' => array( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT' => 'other' ), 'missing_token' => array( 'EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX' => null ),
    'disabled' => array( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED' => false ), 'location' => array( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX' => null ),
    'variation' => array( 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_SANDBOX' => 'bad ID' ), 'external_return' => array( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' => 'https://other.example/mypage/' ),
    'http_return' => array( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' => 'http://wp.example.test/mypage/' ), 'credential_return' => array( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL' => 'https://user@wp.example.test/mypage/' ) );
foreach ( $cases as $name => $replace ) {
    $process = proc_open( array( PHP_BINARY, '-r', $program, base64_encode( json_encode( array_replace( $fixture, $replace ) ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run config case' ); }
    $out = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
    check( $exit === 0 && $errors === '' && $out === ( in_array( $name, array( 'sandbox', 'production' ), true ) ? $name . ':TOKEN_OK' : 'ERROR' ), 'isolated checkout configuration: ' . $name );
}

// Render the actual shortcode under invalid issuance settings, without any HTTP/DB implementation.
$display_program = 'ob_start();' . $program . <<<'PHP'
ob_end_clean();
function is_user_logged_in(){return true;}
function current_user_can($cap){return false;}
function get_current_user_id(){return 10;}
function egov_law_monitor_get_plan($id){return 'free';}
function nocache_headers(){}
function add_shortcode(...$v){}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function wp_nonce_field(...$v){}
function egov_law_monitor_square_record_token($v){return str_repeat('a',64);}
class DisplayStore {
    public $prefix='site_'; public $last_error='';
    public function prepare($sql,...$p){return $sql;}
    public function get_results($sql,$mode){
        return str_contains($sql,'checkout_attempts')?array(array('id'=>1,'user_id'=>10,'application_uuid'=>'00000000-0000-4000-8000-000000000001','environment'=>'sandbox','state'=>'ready','checkout_url'=>'https://sandbox.square.link/u/saved')):array();
    }
}
define('ARRAY_A','ARRAY_A'); $_POST=array(); $wpdb=new DisplayStore();
require getcwd().'/wordpress-plugin/includes/square-checkout-page.php';
$html=egov_law_monitor_standard_application_shortcode();
echo json_encode(array('link'=>str_contains($html,'https://sandbox.square.link/u/saved'),'environment_error'=>str_contains($html,'保存環境'),'secret'=>str_contains($html,'dummy-sandbox')));
PHP;
foreach ( array( 'sandbox', 'disabled', 'missing_token', 'location', 'variation', 'external_return', 'bad_environment', 'production' ) as $name ) {
    $process = proc_open( array( PHP_BINARY, '-r', $display_program, base64_encode( json_encode( array_replace( $fixture, $cases[$name] ) ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run display case' ); }
    $out = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process ); $data = json_decode( $out, true );
    $environment_error = in_array( $name, array( 'bad_environment', 'production' ), true );
    check( $exit === 0 && $errors === '' && $data === array( 'link' => ! $environment_error, 'environment_error' => $environment_error, 'secret' => false ), 'saved link display is independent of issuance settings but validates environment: ' . $name );
}
echo ( $checks - $baseline ) . " checkout checks passed; all HTTP/DB mocked.\n";
