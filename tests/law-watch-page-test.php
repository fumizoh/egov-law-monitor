<?php
/** Display-only law watch regression checks; no WordPress or DB required. */
function wp_create_nonce( $action ) { return 'test-nonce'; }
function nocache_headers() {}
function egov_law_monitor_get_notifications( $id ) { return $GLOBALS['test_notifications'] ?? true; }
function get_current_user_id() { return 10; }
function egov_law_monitor_get_watches() { return $GLOBALS['test_watches']; }
function egov_law_monitor_get_watch_limit( $user_id ) { return 3; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function rest_url( $path ) { return 'https://example.test/' . $path; }
function add_shortcode( $name, $callback ) {}
function egov_law_monitor_last_checked_label() { return '未確認'; }
require __DIR__ . '/../wordpress-plugin/includes/law-search-page.php';

$checks = 0;
function check_watch_display( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['checks'];
}
foreach ( array( array(), array( array( 'keyword' => '民法', 'is_active' => 1 ), array( 'keyword' => '商法', 'is_active' => 0 ) ) ) as $watches ) {
    $GLOBALS['test_watches'] = $watches;
    $html = egov_law_monitor_render_search_page();
    check_watch_display( ! str_contains( $html, 'ご利用プラン' ) && ! str_contains( $html, 'egov-law-watch-plan' ), 'watch tab omits plan display' );
    check_watch_display( str_contains( $html, '法令更新の最終確認：<span id="egov-law-watch-last-checked">未確認</span>' ), 'timestamp target uses server-rendered status' );
    check_watch_display( str_contains( $html, 'id="egov-law-watch-form"' ), 'registration form preserved' );
    if ( $watches ) {
        check_watch_display( str_contains( $html, 'egov-law-resume-button' ) && str_contains( $html, 'egov-law-unwatch-button' ) && str_contains( $html, '民法' ) && str_contains( $html, '商法' ), 'watch controls and keywords preserved' );
    }
}
foreach ( array( true, false ) as $enabled ) {
    $GLOBALS['test_notifications'] = $enabled; $GLOBALS['test_watches'] = array();
    $html = egov_law_monitor_render_search_page();
    check_watch_display( str_contains( $html, 'aria-checked="' . ( $enabled ? 'true' : 'false' ) . '"' ) && str_contains( $html, 'class="egov-watch-mail-value" aria-hidden="true">' . ( $enabled ? 'ON' : 'OFF' ) . '</span>' ), 'saved notification value rendered without keywords' );
    check_watch_display( str_contains( $html, '/watch-notifications' ) && str_contains( $html, 'data-rest-nonce="test-nonce"' ), 'notification endpoint and nonce supplied' );
    check_watch_display( str_contains( $html, 'class="egov-watch-mail-off" ' . ( $enabled ? 'hidden' : '' ) . '>' ), 'OFF guidance visibility follows preference' );
    check_watch_display( str_contains( $html, 'type="button" class="egov-watch-mail-toggle" role="switch" aria-label="メール通知"' ) && str_contains( $html, 'class="egov-watch-mail-track" aria-hidden="true"' ), 'native keyboard button exposes named switch with decorative track' );
    check_watch_display( str_contains( $html, 'class="egov-watch-mail-result" role="status" aria-live="polite"></p>' ) && ! str_contains( $html, '保存しました' ), 'initial status empty; no premature success message' );
}
check_watch_display( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'personal preferences request cache exclusion' );
echo "{$checks} display checks passed; no DB or API used.\n";
