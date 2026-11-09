<?php
/** Isolated public/development gate configurations; no actual DB/HTTP available. */
require __DIR__ . '/square-checkout-test.php';
$access_baseline = $checks;
$access_program = 'ob_start();' . $display_program . <<<'PHP'
ob_end_clean();
function get_userdata($id){return $id>0?(object)array('ID'=>$id):false;}
function wp_verify_nonce($nonce,$action){return false;}
require getcwd().'/wordpress-plugin/includes/square-checkout-cancel.php';
$panel=egov_law_monitor_plan_status_shortcode();
$fail_factory=static function(){throw new LogicException('DB session must not open');};
$submit=egov_law_monitor_submit_checkout('wrong','',$fail_factory);
$cancel=egov_law_monitor_request_checkout_cancel(array('checkout_cancel_id'=>'1','checkout_cancel_nonce'=>'wrong','checkout_cancel_token'=>'wrong','checkout_cancel_confirmed'=>'1'),$fail_factory);
$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('egov_checkout_cancel_request'=>'1');
$prg=egov_law_monitor_checkout_cancel_post($fail_factory,static function(){throw new LogicException('Must not redirect rejected input');});
echo json_encode(array('allowed'=>egov_law_monitor_standard_application_allowed(),'application_form'=>str_contains($html,'data-cancel-open'),'payment_link'=>str_contains($html,'https://sandbox.square.link/u/saved'),
    'plan_navigation'=>str_contains($panel,'/standard-application/'),'free_display'=>str_contains(strip_tags($panel),'現在のプラン：Free'),
    'submit_code'=>$submit->code,'cancel_code'=>$cancel->code,'prg_code'=>$prg->code));
PHP;
$access_program = str_replace( 'function is_user_logged_in(){return true;}', 'function is_user_logged_in(){return $GLOBALS["fixture"]["TEST_LOGGED"]??true;}', $access_program );
$access_program = str_replace( 'function current_user_can($cap){return false;}', 'function current_user_can($cap){return $GLOBALS["fixture"]["TEST_ADMIN"]??false;}', $access_program );
$access_cases = array(
    'undefined' => array(),
    'private_regular' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => false ),
    'private_admin' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => false, 'TEST_ADMIN' => true ),
    'undefined_admin' => array( 'TEST_ADMIN' => true ),
    'private_test_user' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => false, 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' => array( 10 ) ),
    'unlisted_user' => array( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' => array( 20 ) ),
    'malformed_list' => array( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' => '10' ),
    'string_id' => array( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' => array( '10' ) ),
    'public_regular' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => true ),
    'public_anonymous' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => true, 'TEST_LOGGED' => false ),
    'private_anonymous' => array( 'TEST_LOGGED' => false, 'TEST_ADMIN' => true, 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS' => array( 10 ) ),
    'string_public' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => 'true' ),
    'numeric_public' => array( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC' => 1 ),
);
foreach ( $access_cases as $name => $config ) {
    $configuration = $fixture; unset( $configuration['EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC'] ); $configuration = array_replace( $configuration, $config );
    $process = proc_open( array( PHP_BINARY, '-r', $access_program, base64_encode( json_encode( $configuration ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
    if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run access case' ); }
    $out = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process ); $data = json_decode( $out, true );
    $logged = $config['TEST_LOGGED'] ?? true;
    $allow = $logged && ( ( $config['TEST_ADMIN'] ?? false ) || ( $config['EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC'] ?? false ) === true || ( is_array( $config['EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS'] ?? null ) && in_array( 10, $config['EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS'], true ) ) );
    check( $exit === 0 && $errors === '' && is_array( $data ), 'isolated access fixture runs cleanly: ' . $name );
    check( $data['allowed'] === $allow && $data['application_form'] === $allow && $data['payment_link'] === $allow && $data['plan_navigation'] === $allow, 'view and common policy agree: ' . $name );
    check( $data['free_display'] === $logged, 'Free plan remains visible without exposing application links: ' . $name );
    check( $data['submit_code'] === ( ! $logged ? 'checkout_login_required' : ( $allow ? 'checkout_invalid_request' : 'checkout_application_restricted' ) ) &&
        $data['cancel_code'] === ( ! $logged ? 'cancel_login_required' : ( $allow ? 'cancel_invalid_request' : 'checkout_application_restricted' ) ), 'direct issuance/cancel input cannot bypass policy or existing nonce checks: ' . $name );
    check( $data['prg_code'] === ( $allow ? 'cancel_invalid_request' : 'checkout_application_restricted' ), 'early PRG handler also enforces application gate: ' . $name );
}
// Existing public-mode regression tests remain unchanged; run the same workflows
// with public mode false and all fixture users explicitly permitted, in memory only.
$workflow_program = <<<'PHP'
$source=file_get_contents(getcwd().'/tests/square-checkout-test.php');
$source=str_replace('__DIR__',var_export(getcwd().'/tests',true),$source);
$source=str_replace("define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', true );","define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', false ); define( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS', array(10,20,99) );",$source);
eval('?>'.$source);
checkout_reset(); submit();
$row=$GLOBALS['wpdb']->attempts[1];
$fields=array('checkout_cancel_id'=>'1','checkout_cancel_nonce'=>'valid:egov_checkout_cancel_request_10_1',
    'checkout_cancel_token'=>egov_law_monitor_square_record_token($row),'checkout_cancel_confirmed'=>'1');
$result=egov_law_monitor_request_checkout_cancel($fields,static fn()=>new CheckoutSession($GLOBALS['wpdb']));
if($result!==true||$GLOBALS['wpdb']->attempts[1]['state']!=='cancel_requested'){throw new RuntimeException('Allowed test-user cancellation failed');}
echo 'PRIVATE_WORKFLOW_OK';
PHP;
$process = proc_open( array( PHP_BINARY, '-r', $workflow_program ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ) );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run private workflow' ); }
$out = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
check( $exit === 0 && str_contains( $out, 'PRIVATE_WORKFLOW_OK' ) && ! str_contains( $errors, 'Warning' ) && ! str_contains( $errors, 'Fatal' ), 'private allowlisted users retain existing issuance recovery and cancellation flows' );
echo ( $checks - $access_baseline ) . " access-control checks passed; no real DB or Square API used.\n";
