<?php
/** Read-only plan panel, using offline WordPress/DB/HTTP doubles. */
require __DIR__ . '/square-checkout-test.php';
$status_baseline = $checks;
checkout_reset();
$html = egov_law_monitor_plan_status_shortcode();
check( str_contains( strip_tags( $html ), '現在のプラン：Free' ) && str_contains( $html, 'Standardプランに申し込む' ) && str_contains( $html, 'https://wp.example.test/standard-application/' ), 'Free without unresolved history links to independent same-site application page' );
check( str_contains( $html, '<h2>💳 ご利用プラン</h2>' ) && str_contains( $html, '<p class="egov-plan-status-current"><span>現在のプラン：</span><strong class="egov-plan-status-badge egov-plan-status-badge--free">Free</strong></p>' ), 'Free label and styled badge remain separate elements under the card heading' );
check( ! str_contains( $html, '<form' ) && ! str_contains( $html, 'square.link' ) && ! $http && DONOTCACHEPAGE, 'plan panel is private read-only content without checkout URL or HTTP' );
check( egov_law_monitor_standard_application_page_url() !== EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL, 'independent page navigation does not rewrite or depend on the configured legacy return destination' );
$checkout_logged_in = false;
check( str_contains( egov_law_monitor_plan_status_shortcode(), 'ログインが必要' ), 'anonymous user cannot see plan panel' );
foreach ( array( 'ready', 'creating', 'unknown', 'cancel_requested', 'closed', 'closing', 'canceling', 'cancel_unknown' ) as $state ) {
    checkout_reset(); submit(); $wpdb->attempts[1]['state'] = $state; $http = array();
    $before = array( $wpdb->attempts, $wpdb->logs, $wpdb->meta, $wpdb->watches );
    $_POST = array( 'egov_square_checkout' => '1', 'egov_checkout_cancel_request' => '1', 'egov_checkout_cancel' => '1' );
    $html = egov_law_monitor_plan_status_shortcode();
    $expected = array( 'ready' => 'お申し込みを受け付けました。お支払いの確認後、管理者がStandardプランへの切り替えを行います。', 'creating' => '申込処理中', 'unknown' => '申込結果の確認が必要', 'cancel_requested' => 'キャンセル手続き中', 'closed' => 'Standardプランに申し込む' );
    check( str_contains( strip_tags( $html ), $expected[$state] ?? '未解決の申込' ), 'plan panel state: ' . $state );
    if ( $state === 'ready' ) {
        check( str_contains( $html, '<p>お申し込みを受け付けました。</p><p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>' ), 'ready message uses separate paragraphs with unchanged wording' );
    }
    check( ( str_contains( $html, '/standard-application/' ) ) === isset( $expected[$state] ), 'only supported states offer independent page navigation: ' . $state );
    check( ! $http && $before === array( $wpdb->attempts, $wpdb->logs, $wpdb->meta, $wpdb->watches ) && ! str_contains( $html, '<form' ) && ! str_contains( $html, 'sandbox.square.link' ), 'POST cannot mutate or expose checkout from plan panel: ' . $state );
}
checkout_reset(); submit(); $wpdb->attempts[1]['state'] = 'closed';
$wpdb->attempts[2] = array_replace( $wpdb->attempts[1], array( 'id' => 2, 'application_uuid' => wp_generate_uuid4() ) );
check( str_contains( egov_law_monitor_plan_status_shortcode(), 'Standardプランに申し込む' ), 'multiple closed histories still show new application navigation' );
checkout_reset(); $cache[10] = 'standard';
$html = egov_law_monitor_plan_status_shortcode();
check( str_contains( strip_tags( $html ), '現在のプラン：Standard' ) && str_contains( $html, 'Standardプランをご利用中' ) && ! str_contains( $html, '/standard-application/' ) && ! str_contains( $html, '契約有効' ), 'Standard displays entitlement without claiming Square payment or contract validity' );
check( str_contains( $html, 'class="egov-plan-status-badge egov-plan-status-badge--standard">Standard</strong>' ) && str_contains( $html, '<h2>💳 ご利用プラン</h2>' ), 'Standard has its own badge class without changing the card heading' );
checkout_reset(); $wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
check( ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'Free with registered contract cannot navigate as a new applicant' );
$wpdb->contracts = array(); $wpdb->read_fail = 'egov_square_subscriptions';
check( str_contains( egov_law_monitor_plan_status_shortcode(), '確認が必要' ) && ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'read failure fails closed' );
checkout_reset(); submit(); $http = array(); $wpdb->attempts[1]['checkout_url'] = 'https://evil.example/pay';
check( ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'invalid stored URL is treated as inconsistent application data' );
$wpdb->attempts[1]['environment'] = 'production';
check( ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'mixed environment blocks plan-panel navigation' );
checkout_reset(); submit(); $http = array(); $checkout_user = 20;
$html = egov_law_monitor_plan_status_shortcode();
check( ! str_contains( $html, $wpdb->attempts[1]['application_uuid'] ) && ! str_contains( $html, 'square.link' ), 'other user application data is not disclosed' );
// Constant variations are isolated; no database or HTTP implementation is available.
$panel_program = 'ob_start();' . $display_program . <<<'PHP'
ob_end_clean();
$panel=egov_law_monitor_plan_status_shortcode();
echo json_encode(array('details'=>str_contains($panel,'https://wp.example.test/standard-application/'),'purchase'=>str_contains($panel,'sandbox.square.link'),'diagnostic'=>str_contains($panel,'egov-checkout-diagnostic')));
PHP;
$fresh_panel_program = str_replace( '$panel=egov_law_monitor_plan_status_shortcode();', '$wpdb=new class extends DisplayStore {public function get_results($sql,$mode){return array();}}; $panel=egov_law_monitor_plan_status_shortcode();', $panel_program );
foreach ( array( 'ready' => $panel_program, 'fresh' => $fresh_panel_program ) as $mode => $program_variant ) {
foreach ( array( 'sandbox', 'disabled', 'missing_token', 'location', 'variation', 'external_return' ) as $name ) {
    $process = proc_open( array( PHP_BINARY, '-r', $program_variant, base64_encode( json_encode( array_replace( $fixture, $cases[$name] ) ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run plan panel case' ); }
    $out = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
    check( $exit === 0 && $errors === '' && json_decode( $out, true ) === array( 'details' => true, 'purchase' => false, 'diagnostic' => false ), 'panel navigation independent of issuance configuration: ' . $mode . ' ' . $name );
}
}
check( ! str_contains( file_get_contents( __DIR__ . '/../wordpress-plugin/includes/square-checkout-page.php' ), 'checkout_diagnostic' ), 'temporary diagnostics removed from application page' );
echo ( $checks - $status_baseline ) . " plan-status checks passed; no real DB or Square API used.\n";
