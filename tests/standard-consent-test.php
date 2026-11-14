<?php
/** Offline consent POST and rendering regression tests. */
define( 'EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT', 'production' );
require __DIR__ . '/standard-plan-test-bootstrap.php';
$checks = 0;
function consent_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function consent_post() {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( 'egov_standard_consent' => '1', 'standard_consent_nonce' => 'valid', 'standard_agreed' => '1', 'url' => 'https://evil.test' );
}
$html = egov_law_monitor_standard_application_shortcode();
foreach ( array( '月額500円', '月100回', 'キーワード10件', 'クレジットカード', '1か月ごと', '1営業日', '3日前', '次回分が請求', '日割り返金', 'terms/', 'specified-commercial-transactions/', 'privacy-policy/' ) as $text ) { consent_check( str_contains( $html, $text ), 'condition/link: ' . $text ); }
consent_check( ! str_contains( $html, ' checked' ) && str_contains( $html, 'value="1" required' ) && ! str_contains( $html, 'square.link' ), 'unchecked required checkbox; no direct checkout link' );
consent_check( str_contains( $html, 'method="post"' ) && ! str_contains( $html, 'onclick' ), 'works without JavaScript' );
foreach ( array( 'terms', 'specified-commercial-transactions', 'privacy-policy' ) as $path ) { consent_check( str_contains( $html, 'href="https://egovlm.oogushioffice.com/' . $path . '/"' ), 'exact document URL: ' . $path ); }
$redirects = array();
$redirect = function ( $url, $status ) use ( &$redirects ) { $redirects[] = array( $url, $status ); return true; };
foreach ( array( 'agreement', 'nonce', 'anonymous', 'standard', 'contract', 'legacy', 'gate', 'storage' ) as $case ) {
    reset_standard_page(); consent_post();
    if ( $case === 'agreement' ) { unset( $_POST['standard_agreed'] ); }
    if ( $case === 'nonce' ) { $_POST['standard_consent_nonce'] = array( 'bad' ); }
    if ( $case === 'anonymous' ) { $page_logged_in = false; }
    if ( $case === 'standard' ) { $page_plan = 'standard'; }
    if ( $case === 'contract' ) { $wpdb->contracts = array( 10 ); }
    if ( $case === 'legacy' ) { $wpdb->legacy = array( array( 'user_id' => 10, 'state' => 'unknown' ) ); }
    if ( $case === 'gate' ) { $page_admin = false; }
    if ( $case === 'storage' ) { $page_save_fail = true; }
    $result = egov_law_monitor_standard_consent_post( $redirect );
    consent_check( is_wp_error( $result ) && ! $redirects && $page_plan !== 'other', 'reject without redirect: ' . $case );
    if ( $case === 'storage' ) { consent_check( str_contains( egov_law_monitor_standard_application_shortcode(), '同意情報を保存できませんでした' ), 'save failure displayed safely' ); }
    if ( $case !== 'storage' ) { consent_check( $page_writes === 0, 'no consent write: ' . $case ); }
}
reset_standard_page(); consent_post();
consent_check( egov_law_monitor_standard_consent_post( $redirect ) === true && $redirects === array( array( 'https://square.link/u/30s16R3f', 303 ) ), 'server-owned 303 target' );
$record = $page_meta['egov_law_monitor_standard_consent'];
consent_check( count( $record ) === 3 && preg_match( '/Z$/', $record['agreed_at'] ) && $record['terms_version'] === EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION && $record['conditions_version'] === EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION, 'UTC and explicit versions' );
consent_check( $page_nonce_action === 'egov_standard_consent_10_' . EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION . '_' . EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION, 'nonce bound to user and current versions' );
egov_law_monitor_standard_application_shortcode(); egov_law_monitor_standard_application_shortcode(); egov_law_monitor_standard_consent_post( $redirect );
consent_check( $page_writes === 1 && count( $redirects ) === 1 && $page_plan === 'free', 'request guard, repeated rendering, unchanged plan' );
unset( $GLOBALS['egov_standard_consent_result'] );
egov_law_monitor_standard_consent_post( $redirect );
consent_check( count( $page_meta ) === 1 && count( $redirects ) === 2, 'reposting maintains latest single record including same-value save' );
$page_meta['egov_law_monitor_standard_consent']['terms_version'] = 'old';
unset( $GLOBALS['egov_standard_consent_result'] ); egov_law_monitor_standard_consent_post( $redirect );
consent_check( $page_meta['egov_law_monitor_standard_consent']['terms_version'] === EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION && count( $page_meta ) === 1, 'older consent replaced by latest versions' );
$_SERVER['REQUEST_METHOD'] = 'GET'; unset( $GLOBALS['egov_standard_consent_result'] );
$before = $page_writes; egov_law_monitor_standard_consent_post( $redirect );
consent_check( $before === $page_writes, 'GET never saves' );
echo "{$checks} consent checks passed; no live DB, mail or HTTP.\n";
