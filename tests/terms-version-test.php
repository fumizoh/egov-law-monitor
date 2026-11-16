<?php
/** Offline Settings API registration/rendering and consent version regressions. */
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
require __DIR__ . '/standard-plan-test-bootstrap.php';
function register_setting( $group, $name, $args ) { if ( $name === 'egov_law_monitor_terms_version' ) { $GLOBALS['terms_registered'] = array( $group, $name, $args ); } }
function add_settings_error( ...$args ) { $GLOBALS['terms_errors'][] = $args; }
function settings_errors( $name ) {}
function esc_attr( $value ) { return esc_html( $value ); }
function settings_fields( $group ) { echo '<input name="option_page" value="' . $group . '"><input name="_wpnonce" value="settings-nonce">'; }
function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
$checks = 0;
function check_terms( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
foreach ( $page_hooks as $hook ) { if ( $hook[0] === 'admin_init' && $hook[1] !== 'egov_law_monitor_install_withdrawal_table' ) { $hook[1](); } }
check_terms( $terms_registered[0] === 'egov_law_monitor_terms_settings' && $terms_registered[1] === 'egov_law_monitor_terms_version' && $terms_registered[2]['sanitize_callback'] === 'egov_law_monitor_sanitize_terms_version', 'independent Settings API group and sanitizer registered' );
check_terms( egov_law_monitor_terms_version() === '2026-11-14' && $page_options === array(), 'missing option uses default without writing' );
foreach ( array( '2026-11-15', '2028-02-29' ) as $valid ) {
    $value = egov_law_monitor_sanitize_terms_version( $valid );
    $page_options['egov_law_monitor_terms_version'] = $value;
    check_terms( $value === $valid && egov_law_monitor_terms_version() === $valid, 'valid version accepted and retrieved' );
}
foreach ( array( '', '2026-02-29', '2026-13-01', '2026-11-31', '0000-01-01', '2026-1-01', '2026-11-15 ', array(), null, 20261115 ) as $invalid ) {
    $before = count( $GLOBALS['terms_errors'] ?? array() );
    check_terms( egov_law_monitor_sanitize_terms_version( $invalid ) === '2028-02-29' && count( $terms_errors ) === $before + 1, 'invalid input preserves last valid option with error' );
}
$page_admin = false;
check_terms( egov_law_monitor_sanitize_terms_version( '2029-01-01' ) === '2028-02-29', 'non-administrator cannot change setting through sanitizer' );
ob_start(); egov_law_monitor_render_terms_settings(); $html = ob_get_clean();
check_terms( $html === '', 'non-administrator cannot see settings form' );
$page_admin = true;
ob_start(); egov_law_monitor_render_terms_settings(); $html = ob_get_clean();
check_terms( str_contains( $html, 'action="options.php"' ) && str_contains( $html, 'value="egov_law_monitor_terms_settings"' ) && str_contains( $html, 'name="_wpnonce"' ), 'standard options.php save endpoint and independent nonce group' );
// Actual options.php capability/nonce enforcement belongs to WordPress, not these doubles.
$legacy = array( 'agreed_at' => '2026-11-14T00:00:00Z', 'terms_version' => '2026-11-14', 'conditions_version' => '2026-11-14' );
$page_meta['egov_law_monitor_standard_consent'] = $legacy;
egov_law_monitor_standard_application_shortcode();
check_terms( $page_meta['egov_law_monitor_standard_consent'] === $legacy && $page_writes === 0, 'old three-field record untouched by display and settings changes' );
$redirects = array(); $redirect = function ( $url, $status ) use ( &$redirects ) { $redirects[] = array( $url, $status ); return true; };
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'egov_standard_consent' => '1', 'standard_agreed' => '1', 'standard_consent_nonce' => 'valid:egov_standard_consent_10_2026-11-14' );
$result = egov_law_monitor_standard_consent_post( $redirect );
check_terms( is_wp_error( $result ) && $result->code === 'consent_nonce' && ! $redirects && $page_writes === 0, 'form predating terms update rejected' );
unset( $GLOBALS['egov_standard_consent_result'] );
$_POST['standard_consent_nonce'] = 'valid:egov_standard_consent_10_2028-02-29';
egov_law_monitor_standard_consent_post( $redirect );
$record = $page_meta['egov_law_monitor_standard_consent'];
check_terms( count( $record ) === 2 && ! isset( $record['conditions_version'] ) && $record['terms_version'] === '2028-02-29' && str_ends_with( $record['agreed_at'], 'Z' ) && $redirects === array( array( 'https://square.link/u/30s16R3f', 303 ) ), 'explicit reconsent replaces legacy record with UTC and current terms only' );
foreach ( array( '', array(), '2026-02-30', false ) as $corrupt ) {
    reset_standard_page(); $page_options['egov_law_monitor_terms_version'] = $corrupt;
    ob_start(); egov_law_monitor_render_terms_settings(); $admin_html = ob_get_clean();
    check_terms( is_wp_error( egov_law_monitor_terms_version() ) && str_contains( $admin_html, '保存されている利用規約バージョンが不正' ), 'corrupt stored option warns administrators' );
    $html = egov_law_monitor_standard_application_shortcode();
    check_terms( ! str_contains( $html, 'name="egov_standard_consent"' ) && str_contains( $html, '利用規約の設定を確認できません' ), 'corrupt option prevents checkout form' );
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'egov_standard_consent' => '1', 'standard_agreed' => '1', 'standard_consent_nonce' => 'valid' );
    $count = count( $redirects );
    check_terms( is_wp_error( egov_law_monitor_standard_consent_post( $redirect ) ) && $page_writes === 0 && count( $redirects ) === $count, 'corrupt option prevents writes and redirect' );
}
echo "{$checks} terms version checks passed; no live DB, HTTP or WordPress settings save.\n";
