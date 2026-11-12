<?php
/** Guard the production dependency chain and absence of retired entry points. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; }
function register_activation_hook( ...$args ) { $GLOBALS['activations'][] = $args; }
function add_shortcode( ...$args ) {}
require __DIR__ . '/../wordpress-plugin/includes/square-subscriptions.php';
require __DIR__ . '/../wordpress-plugin/includes/square-approval.php';
require __DIR__ . '/../wordpress-plugin/includes/square-api.php';
require __DIR__ . '/../wordpress-plugin/includes/square-invoices.php';
require __DIR__ . '/../wordpress-plugin/includes/standard-plan-page.php';
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-square-subscriptions.php';
$checks = 0;
function check_cleanup( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
foreach ( array( 'egov_law_monitor_lookup_square_invoice', 'egov_law_monitor_approve_square_invoice', 'egov_law_monitor_square_apply_plan', 'egov_law_monitor_square_store_contract', 'egov_law_monitor_square_make_free', 'egov_law_monitor_render_square_audit', 'egov_law_monitor_plan_status_shortcode' ) as $function ) { check_cleanup( function_exists( $function ), 'required function loaded: ' . $function ); }
foreach ( array( 'egov_law_monitor_submit_checkout', 'egov_law_monitor_create_checkout_link', 'egov_law_monitor_checkout_request', 'egov_law_monitor_checkout_contract_search', 'egov_law_monitor_request_checkout_cancel', 'egov_law_monitor_complete_checkout_cancel', 'egov_law_monitor_save_square_subscription', 'egov_law_monitor_lookup_square_subscription', 'egov_law_monitor_install_checkout_table' ) as $function ) { check_cleanup( ! function_exists( $function ), 'retired function absent: ' . $function ); }
check_cleanup( ! array_filter( $GLOBALS['hooks'], static fn( $hook ) => $hook[0] === 'template_redirect' || str_starts_with( $hook[0], 'wp_ajax_' ) || $hook[0] === 'rest_api_init' ), 'no retired mutation hooks loaded' );
$root = __DIR__ . '/../wordpress-plugin/'; $bootstrap = file_get_contents( $root . 'egov-law-monitor.php' );
preg_match_all( "/require_once plugin_dir_path\( __FILE__ \) \. '([^']+)'/", $bootstrap, $matches );
foreach ( $matches[1] as $path ) { check_cleanup( is_file( $root . $path ), 'bootstrap dependency exists: ' . $path ); }
check_cleanup( ! str_contains( $bootstrap, 'egov-square-checkout' ) && ! str_contains( $bootstrap, 'includes/square-checkout' ), 'retired script and PHP loaders removed' );
$directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $directory as $file ) {
    if ( $file->getExtension() !== 'php' ) { continue; }
    $source = file_get_contents( $file->getPathname() );
    check_cleanup( ! str_contains( $source, '/v2/online-checkout/payment-links' ) && ! str_contains( $source, '/v2/subscriptions/search' ), 'no dynamic issuance or template search endpoint: ' . $file->getFilename() );
}
echo "{$checks} cleanup checks passed; production dependencies loaded without DB/API calls.\n";
