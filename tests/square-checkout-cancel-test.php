<?php
/** Offline local-only cancellation workflow and inherited payment/approval regressions. */
require __DIR__ . '/square-checkout-test.php';
$baseline_cancel = $checks;
function wp_safe_remote_request( ...$args ) { throw new LogicException( 'Cancellation must never call Square' ); }
function cancel_reset() { checkout_reset(); submit(); $GLOBALS['http'] = array(); }
function request_fields() {
    return array( 'checkout_cancel_id' => '1', 'checkout_cancel_nonce' => 'valid:egov_checkout_cancel_request_10_1',
        'checkout_cancel_token' => egov_law_monitor_square_record_token( $GLOBALS['wpdb']->attempts[1] ), 'checkout_cancel_confirmed' => '1' );
}
function complete_fields() {
    return array( 'checkout_cancel_id' => '1', 'checkout_cancel_nonce' => 'valid:egov_checkout_cancel_complete_10_1',
        'checkout_cancel_token' => egov_law_monitor_square_record_token( $GLOBALS['wpdb']->attempts[1] ), 'checkout_cancel_reason' => 'Sandboxの対象リンク削除、未決済・未契約を確認',
        'link_deleted' => '1', 'no_payment_subscription' => '1', 'no_unresolved_issue' => '1' );
}
function request_cancel( $fields = null ) { return egov_law_monitor_request_checkout_cancel( $fields ?? request_fields(), static fn() => new CheckoutSession( $GLOBALS['wpdb'] ) ); }
function complete_cancel( $fields = null, $uid = 10 ) { return egov_law_monitor_complete_checkout_cancel( $uid, $fields ?? complete_fields(), static fn() => new CheckoutSession( $GLOBALS['wpdb'] ) ); }

cancel_reset(); $before = array( $wpdb->meta, $wpdb->contracts, $wpdb->watches, $trial_usage, $monthly_usage );
$stale = request_fields(); $allowed = false;
check( request_cancel() === true && $wpdb->attempts[1]['state'] === 'cancel_requested' && ! $http, 'owner can request cancellation without admin rights or HTTP' );
$audit = $wpdb->logs[0]; $data = json_decode( $audit['new_value'], true );
check( $audit['action'] === 'checkout_cancel_requested' && $audit['admin_user_id'] === 0 && $audit['user_id'] === 10 && $data['actor_type'] === 'user' && $data['actor_user_id'] === 10 && isset( $data['recorded_at'], $data['application_uuid'] ), 'user audit has sentinel admin and explicit user actor UUID and time' );
check( json_decode( $audit['old_value'], true )['state'] === 'ready' && $data['state'] === 'cancel_requested', 'request audit records exact state transition' );
check( request_cancel( $stale )->code === 'cancel_conflict' && count( $wpdb->logs ) === 1, 'duplicate or concurrent stale user request rejected' );
$html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, 'キャンセル手続き中' ) && ! str_contains( $html, 'https://sandbox.square.link' ) && ! str_contains( $html, 'name="egov_square_checkout"' ), 'requested application hides payment link and new issuance form' );
foreach ( array( 'Standardプラン（月額500円・税込）', 'お申し込みのキャンセルを受け付けました。', '管理者がキャンセルの手続きを行います。手続き完了まで、しばらくお待ちください。', 'キャンセル完了後は、改めてお申し込みいただけます。' ) as $copy ) {
    check( str_contains( $html, $copy ), 'pending cancellation copy: ' . $copy );
}
check( ! str_contains( $html, 'Standardプランのお支払いは、Squareの決済ページで行います。' ) && ! str_contains( $html, 'お支払いの確認後、管理者がStandardプランへの切り替えを行います。' ), 'pending cancellation suppresses ordinary payment explanation' );
check( str_contains( $html, '<h3><svg' ) && str_contains( $html, 'class="egov-standard-pending-icon" aria-hidden="true"' ) && ! str_contains( $html, 'data-cancel-open' ), 'pending state has heading and decorative clock without another cancellation action' );
check( submit() instanceof WP_Error && ! $http, 'direct issuance request cannot recover a cancel_requested application' );
check( complete_cancel()->code === 'cancel_forbidden', 'completion requires manage_options' );
$allowed = true; $checkout_user = 99; $old = complete_fields();
check( complete_cancel() === true && $wpdb->attempts[1]['state'] === 'closed' && ! $http, 'administrator completes without any Square communication' );
$audit = $wpdb->logs[1]; $data = json_decode( $audit['new_value'], true );
check( $audit['action'] === 'checkout_cancel_completed' && $audit['admin_user_id'] === 99 && $audit['reason'] !== '' && $data['actor_type'] === 'administrator' && isset( $data['confirmed_at'] ) && count( $data['dashboard_confirmation'] ) === 3, 'completion audit records administrator UTC confirmation reason and checklist' );
check( $data['state'] === 'closed' && json_decode( $audit['old_value'], true )['state'] === 'cancel_requested', 'completion and audit preserve before/after states' );
check( complete_cancel( $old )->code === 'cancel_conflict' && count( $wpdb->logs ) === 2, 'second administrator using the same old form cannot complete twice' );
check( array( $wpdb->meta, $wpdb->contracts, $wpdb->watches, $trial_usage, $monthly_usage ) === $before, 'both stages preserve plan contracts AI usage and watches' );
$checkout_user = 10; $old_uuid = $wpdb->attempts[1]['application_uuid'];
$result = submit();
check( $result['state'] === 'ready' && $result['application_uuid'] !== $old_uuid && count( $wpdb->attempts ) === 2 && count( $http ) === 1, 'actual request-completion-reapplication chain retains old row and uses fresh UUID' );
$body = json_decode( $http[0][1]['body'], true );
check( $body['idempotency_key'] === $result['application_uuid'] && $body['order']['reference_id'] === $result['application_uuid'], 'reapplication key and order reference use the new UUID' );
check( submit()['application_uuid'] === $result['application_uuid'] && count( $http ) === 1, 'new ready link redisplayed without duplicate issuance' );

foreach ( array( 'checkout_cancel_nonce', 'checkout_cancel_token', 'checkout_cancel_confirmed', 'checkout_cancel_id' ) as $key ) {
    cancel_reset(); $fields = request_fields(); $fields[$key] = '';
    check( request_cancel( $fields ) instanceof WP_Error && $wpdb->attempts[1]['state'] === 'ready' && ! $wpdb->logs && ! $http, 'bad user request rejected: ' . $key );
}
cancel_reset(); $checkout_logged_in = false;
check( request_cancel()->code === 'cancel_login_required', 'logged-out request rejected' );
cancel_reset(); $checkout_user = 20; $fields = request_fields(); $fields['checkout_cancel_nonce'] = 'valid:egov_checkout_cancel_request_20_1';
check( request_cancel( $fields )->code === 'cancel_conflict' && ! $wpdb->logs, 'another user cannot request cancellation for this row' );
cancel_reset(); $fields = request_fields(); $wpdb->attempts[1]['updated_at'] = '2001-01-01 00:00:00';
check( request_cancel( $fields )->code === 'cancel_conflict', 'stale full-row signature rejected' );
foreach ( array( 'creating', 'unknown', 'closed', 'closing', 'canceling', 'cancel_unknown' ) as $state ) {
    cancel_reset(); $wpdb->attempts[1]['state'] = $state;
    check( request_cancel()->code === 'cancel_conflict' && complete_cancel()->code === 'cancel_conflict' && ! $wpdb->logs && ! $http, 'non-requested or legacy row cannot be silently completed: ' . $state );
    if ( $state !== 'closed' ) {
        check( submit() instanceof WP_Error && ! $http, 'legacy/unresolved state blocks new issuance: ' . $state );
    }
    if ( in_array( $state, array( 'closing', 'canceling', 'cancel_unknown' ), true ) ) {
        $html = egov_law_monitor_standard_application_shortcode();
        check( ! str_contains( $html, 'https://sandbox.square.link' ) && ! str_contains( $html, 'name="egov_square_checkout"' ), 'legacy unresolved state has no public link or issuance form: ' . $state );
    }
}
foreach ( array( 'link_deleted', 'no_payment_subscription', 'no_unresolved_issue', 'checkout_cancel_reason', 'checkout_cancel_nonce', 'checkout_cancel_token' ) as $key ) {
    cancel_reset(); request_cancel(); $fields = complete_fields(); $fields[$key] = '';
    check( complete_cancel( $fields ) instanceof WP_Error && $wpdb->attempts[1]['state'] === 'cancel_requested' && count( $wpdb->logs ) === 1 && ! $http, 'incomplete confirmation stays pending: ' . $key );
}
cancel_reset(); request_cancel();
check( complete_cancel( null, 999 ) instanceof WP_Error, 'nonexistent completion user rejected' );
$fields = complete_fields(); $fields['checkout_cancel_nonce'] = 'valid:egov_checkout_cancel_complete_20_1';
check( complete_cancel( $fields, 20 )->code === 'cancel_conflict', 'completion ownership checked independently of nonce' );
$wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
check( complete_cancel()->code === 'cancel_existing_contract' && $wpdb->attempts[1]['state'] === 'cancel_requested', 'known existing contract blocks completion as unestablished' );

foreach ( array( false, true ) as $complete ) {
    foreach ( array( 'cancel_write', 'cancel_noop', 'audit', 'audit_rollback', 'begin', 'commit', 'disconnect:commit' ) as $failure ) {
        cancel_reset(); if ( $complete ) { request_cancel(); }
        $state = $wpdb->attempts[1]['state']; $count = count( $wpdb->logs ); $wpdb->fail = $failure;
        $result = $complete ? complete_cancel() : request_cancel();
        check( $result instanceof WP_Error && $wpdb->attempts[1]['state'] === $state && count( $wpdb->logs ) === $count && ! $http, 'atomic failure preserves prior state and audit: ' . ( $complete ? 'complete ' : 'request ' ) . $failure );
        if ( in_array( $failure, array( 'commit', 'audit_rollback', 'disconnect:commit' ), true ) ) { check( ! str_contains( $result->get_error_message(), '取り消しました' ), 'uncertain transaction never promises rollback' ); }
    }
    cancel_reset(); if ( $complete ) { request_cancel(); }
    $wpdb->fail = 'commit'; $wpdb->commit_applied = true;
    $result = $complete ? complete_cancel() : request_cancel();
    check( $result instanceof WP_Error && $wpdb->attempts[1]['state'] === ( $complete ? 'closed' : 'cancel_requested' ) && ! $http, 'lost applied COMMIT reports uncertainty for either transition' );
}

cancel_reset(); request_cancel(); complete_cancel();
$wpdb->attempts[2] = array_replace( $wpdb->attempts[1], array( 'id' => 2, 'application_uuid' => wp_generate_uuid4() ) );
$http_hook = static function () { check( submit() instanceof WP_Error && count( $GLOBALS['http'] ) === 1, 'concurrent clicks after multiple closed histories create only one active attempt' ); };
$result = submit(); $http_hook = null;
check( $result['state'] === 'ready' && count( $wpdb->attempts ) === 3 && $wpdb->attempts[1]['state'] === 'closed' && $wpdb->attempts[2]['state'] === 'closed', 'multiple closed histories retained during new issuance' );
$wpdb->attempts[3]['state'] = 'closed'; $wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
check( submit()->code === 'checkout_existing_contract' && count( $http ) === 1, 'Free with registered contract cannot reapply after completion' );
$wpdb->contracts = array(); $wpdb->meta[1] = array( 'umeta_id' => 1, 'user_id' => 10, 'meta_key' => EGOV_LAW_MONITOR_META_PLAN, 'meta_value' => 'standard' );
check( submit()->code === 'checkout_free_only', 'Standard user cannot reapply after closed histories' );

cancel_reset(); $html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, '<summary>申込をキャンセルする</summary>' ) && str_contains( $html, 'お支払い済みの場合は、お問い合わせください。' ) && ! str_contains( $html, 'type="checkbox"' ) && str_contains( $html, 'name="checkout_cancel_confirmed" value=""' ), 'ready application replaces checkbox with initially unconfirmed dialog flow' );
foreach ( array( 'お申し込みを取りやめる場合は、下のリンクからキャンセルを申請してください。', '管理者がキャンセルの手続きを行います。手続き完了まで、しばらくお待ちください。', 'キャンセル完了後は、改めてお申し込みいただけます。', 'Standardプランの申し込みをキャンセルしますか？', 'キャンセルの手続きは管理者が行います。' ) as $copy ) {
    check( str_contains( $html, $copy ), 'requested cancellation copy: ' . $copy );
}
check( str_contains( $html, 'class="egov-law-button egov-standard-payment-button"' ) && str_contains( $html, 'Squareでお支払いへ進む' ), 'payment link reuses existing primary button style' );
check( str_contains( $html, 'type="button" data-cancel-open disabled' ) && str_contains( $html, 'data-cancel-back autofocus>戻る' ) && str_contains( $html, 'data-cancel-confirm>キャンセルを申請する' ) && str_contains( $html, 'checkout_cancel_nonce' ) && str_contains( $html, 'checkout_cancel_token' ) && str_contains( $html, 'JavaScriptと対応ブラウザが必要' ), 'dialog actions retain nonce signature and fail-closed no-JS guidance' );
$previous_position = -1;
foreach ( array( '<h2>Standardプラン（月額500円・税込）</h2>', '<p>下のボタンからSquareでお支払いをお願いします。</p>', '<p>お支払いの確認後、管理者がStandardプランへの切り替えを行います。</p>', '<p>切り替えが完了するまで、しばらくお待ちください。</p>', '申込ID：', 'Squareでお支払いへ進む', 'すでにお支払い済みの場合は、再度決済せず、プランの切り替えをお待ちください。', '<summary>申込をキャンセルする</summary>' ) as $part ) {
    $position = strpos( $html, $part );
    check( $position !== false && $position > $previous_position, 'ready content order: ' . $part );
    $previous_position = $position;
}
check( ! str_contains( $html, 'Standardプランのお支払いは、Squareの決済ページで行います。' ), 'ready explanation does not duplicate old introduction' );
$_POST = array( 'egov_square_checkout' => '', 'egov_checkout_cancel_request' => '' );
$html = egov_law_monitor_standard_application_shortcode();
check( $wpdb->attempts[1]['state'] === 'ready' && ! $http && str_contains( $html, '別々に操作' ), 'mixed issue/request POST rejected without mutations' );
$_POST = array(); request_cancel(); ob_start(); egov_law_monitor_render_checkout_attempts( 10 ); $html = ob_get_clean();
check( str_contains( $html, 'キャンセル完了' ) && str_contains( $html, 'link_deleted' ) && str_contains( $html, 'no_payment_subscription' ) && str_contains( $html, 'no_unresolved_issue' ) && str_contains( $html, '技術的に証明するものではありません' ), 'admin completion has three manual assertions and proof limitation warning' );
complete_cancel(); ob_start(); egov_law_monitor_render_checkout_attempts( 10 ); $html = ob_get_clean();
check( ! str_contains( $html, 'name="egov_checkout_cancel"' ) && str_contains( $html, '完了登録済み' ), 'closed history has no repeat completion action' );
check( ! function_exists( 'egov_law_monitor_checkout_cancel_api' ) && ! function_exists( 'egov_law_monitor_checkout_cancel_remote' ) && ! $http, 'automatic Square cancellation and inspection functions removed' );
cancel_reset(); $wpdb->contracts[1] = array( 'id' => 1, 'user_id' => 10 );
$html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, 'name="egov_checkout_cancel_request"' ) && ! str_contains( $html, 'https://sandbox.square.link' ), 'existing contract stops purchase but does not prevent user cancellation request' );
request_cancel(); $wpdb->meta[1] = array( 'umeta_id' => 1, 'user_id' => 10, 'meta_key' => EGOV_LAW_MONITOR_META_PLAN, 'meta_value' => 'standard' );
$cache[10] = 'standard'; $html = egov_law_monitor_standard_application_shortcode();
check( str_contains( $html, 'キャンセル手続き中' ), 'pending request remains visible even after plan changes' );
echo ( $checks - $baseline_cancel ) . " manual cancellation checks passed; no real DB or Square API used.\n";
