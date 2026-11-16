<?php
/** Offline real gate/POST/sanitizer tests; WordPress options.php is not executed. */
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', true );
require __DIR__ . '/standard-plan-test-bootstrap.php';
function register_setting( $group, $name, $args ) { $GLOBALS['registered'][$name] = array( $group, $args ); }
function add_settings_error( ...$args ) { $GLOBALS['setting_errors'][] = $args; }
function settings_errors( $name ) {}
function settings_fields( $group ) { echo '<input name="option_page" value="' . $group . '"><input name="_wpnonce" value="settings-nonce">'; }
function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
$checks = 0;
function check_setting( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
$key = 'egov_law_monitor_standard_application_public';
foreach ( $page_hooks as $hook ) {
    if ( $hook[0] === 'admin_init' && is_callable( $hook[1] ) && $hook[1] !== 'egov_law_monitor_install_withdrawal_table' ) { $hook[1](); }
}
check_setting( $registered[$key][0] === 'egov_law_monitor_standard_application_settings' && $registered[$key][1]['sanitize_callback'] === 'egov_law_monitor_sanitize_standard_application_public', 'separate Settings API group and actual sanitizer registered' );
check_setting( isset( $registered['egov_law_monitor_terms_version'] ), 'existing terms group remains registered' );
$page_admin = false;
check_setting( ! egov_law_monitor_standard_application_public() && ! egov_law_monitor_standard_application_allowed() && $page_options === array(), 'missing setting is OFF even with legacy true; no automatic migration/write' );
check_setting( ! str_contains( egov_law_monitor_standard_application_shortcode(), 'name="egov_standard_consent"' ) && ! str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'ordinary Free application and mypage both closed' );
ob_start(); egov_law_monitor_render_standard_application_settings(); $html = ob_get_clean();
check_setting( $html === '', 'non-admin cannot see settings form' );
$page_admin = true;
ob_start(); egov_law_monitor_render_standard_application_settings(); $html = ob_get_clean();
check_setting( str_contains( $html, 'action="options.php"' ) && str_contains( $html, 'value="egov_law_monitor_standard_application_settings"' ) && str_contains( $html, 'name="_wpnonce"' ), 'save delegates capability and nonce verification to standard options.php' );
check_setting( str_contains( $html, 'OFF（一般受付停止中）' ) && ! str_contains( $html, ' checked' ) && str_contains( $html, 'type="hidden"' ), 'default unchecked with explicit OFF submission' );
$page_options['egov_law_monitor_terms_version'] = '2026-11-14';
foreach ( array( '1', '0', '1' ) as $value ) {
    $page_options[$key] = egov_law_monitor_sanitize_standard_application_public( $value );
    check_setting( $page_options[$key] === $value && egov_law_monitor_standard_application_public() === ( $value === '1' ), 'ON/OFF saved and retrieved' );
    ob_start(); egov_law_monitor_render_standard_application_settings(); $html = ob_get_clean();
    check_setting( str_contains( $html, ' checked' ) === ( $value === '1' ) && str_contains( $html, $value === '1' ? 'ON（一般受付中）' : 'OFF（一般受付停止中）' ), 'saved state reflected in admin form' );
}
foreach ( array( '', 'true', 'false', '2', array( '1' ), null, true, 1 ) as $invalid ) {
    $before = count( $setting_errors ?? array() );
    check_setting( egov_law_monitor_sanitize_standard_application_public( $invalid ) === '1' && count( $setting_errors ) === $before + 1, 'invalid submitted input rejected; previous option retained' );
}
$page_admin = false;
check_setting( egov_law_monitor_sanitize_standard_application_public( '0' ) === '1', 'non-admin cannot change value' );
check_setting( egov_law_monitor_standard_application_allowed(), 'ordinary Free accepted when ON' );
check_setting( str_contains( egov_law_monitor_standard_application_shortcode(), 'name="egov_standard_consent"' ) && str_contains( egov_law_monitor_plan_status_shortcode(), '/standard-application/' ), 'application and mypage use same ON gate' );
// Actual consent POST rechecks the current option even if a form was opened while ON.
$page_options[$key] = '0';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'egov_standard_consent' => '1', 'standard_agreed' => '1', 'standard_consent_nonce' => 'valid' );
$redirects = array();
$redirect = function ( $url, $status ) use ( &$redirects ) { $redirects[] = array( $url, $status ); return true; };
$result = egov_law_monitor_standard_consent_post( $redirect );
check_setting( is_wp_error( $result ) && $result->code === 'consent_unavailable' && ! $redirects && $page_writes === 0, 'OFF at POST rejects stale ON form without consent write/redirect' );
$page_options[$key] = '1'; unset( $GLOBALS['egov_standard_consent_result'] );
$result = egov_law_monitor_standard_consent_post( $redirect );
check_setting( ! is_wp_error( $result ) && $page_writes === 1 && $redirects === array( array( 'https://square.link/u/30s16R3f', 303 ) ), 'ON at POST retains consent and fixed Square 303 flow' );
check_setting( $page_options['egov_law_monitor_terms_version'] === '2026-11-14' && $page_plan === 'free', 'unrelated settings and plan unchanged' );
foreach ( array( 'true', 'yes', '2', array( '1' ), null ) as $corrupt ) {
    $page_options[$key] = $corrupt;
    check_setting( ! egov_law_monitor_standard_application_public(), 'corrupt stored option fails closed' );
}
$page_options[$key] = '0'; $page_admin = true;
check_setting( egov_law_monitor_standard_application_allowed(), 'admin exception retained while OFF' );
echo "$checks Standard application settings checks passed; options.php nonce/capability and real DB save require WordPress integration verification.\n";
