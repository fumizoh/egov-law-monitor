<?php
/** Display-only regression checks: no DB, Square API or filesystem writes. */
define( 'ABSPATH', __DIR__ );
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_userdata( $id ) { return $id === 99 ? (object) array( 'display_name' => '管理者<script>攻撃</script>' ) : false; }
require __DIR__ . '/../wordpress-plugin/includes/admin/admin-square-audit.php';
$checks = 0;
function check_audit( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function audit_summary( $action, $before, $after ) { return egov_law_monitor_square_audit_summary( $action, egov_law_monitor_square_audit_json( $before ), egov_law_monitor_square_audit_json( $after ) ); }
$labels = array( 'standard_approved' => 'Standard承認', 'contract_environment_confirmed' => '契約環境確認', 'plan_changed' => 'プラン変更', 'subscription_created' => '契約登録', 'subscription_updated' => '契約更新', 'checkout_cancel_requested' => '申込キャンセル申請', 'checkout_cancel_completed' => '申込キャンセル完了' );
foreach ( $labels as $code => $label ) { check_audit( egov_law_monitor_square_audit_label( $code ) === $label, 'Japanese action label: ' . $code ); }
check_audit( audit_summary( 'standard_approved', '{"plan":"free"}', '{"plan":"standard"}' ) === 'Free → Standard', 'approval plan transition' );
check_audit( audit_summary( 'plan_changed', '{"plan":"standard"}', '{"plan":"free"}' ) === 'Standard → Free', 'revocation plan transition' );
check_audit( audit_summary( 'contract_environment_confirmed', '{"environment":null}', '{"environment":"production"}' ) === '環境未登録 → production', 'explicit unknown environment transition' );
check_audit( audit_summary( 'contract_environment_confirmed', '{}', '{"environment":"production"}' ) === '契約環境確認', 'missing environment not inferred as unregistered' );
check_audit( audit_summary( 'contract_environment_confirmed', '{"environment":null}', '{"environment":"other"}' ) === '契約環境確認', 'unsupported environment not summarized' );
check_audit( audit_summary( 'subscription_created', null, '{"square_subscription_id":"SUB-1"}' ) === 'Subscription ID: SUB-1', 'registered subscription ID shown' );
check_audit( audit_summary( 'subscription_updated', '{"square_subscription_id":"OLD"}', '{"square_subscription_id":"NEW"}' ) === 'Subscription ID: OLD → NEW', 'edited subscription IDs shown' );
check_audit( audit_summary( 'subscription_updated', '{"square_customer_id":"OLD"}', '{"square_customer_id":"NEW"}' ) === 'Customer ID: OLD → NEW', 'edited customer IDs shown' );
check_audit( audit_summary( 'subscription_updated', '{"admin_note":"old"}', '{"admin_note":"new"}' ) === '契約更新', 'unhandled update shows action only' );
check_audit( audit_summary( 'checkout_cancel_requested', '{"state":"ready"}', '{"state":"cancel_requested"}' ) === 'リンク発行済み → キャンセル申請中', 'cancellation request transition' );
check_audit( audit_summary( 'checkout_cancel_completed', '{"state":"cancel_requested"}', '{"state":"closed"}' ) === 'キャンセル申請中 → キャンセル完了', 'cancellation completion transition' );
foreach ( array( null, '', '{invalid', 'true', '[]', '{"plan":[]}', '{"plan":"unsupported"}' ) as $raw ) {
    check_audit( audit_summary( 'standard_approved', $raw, '{"plan":"standard"}' ) === 'Standard承認', 'unsupported/malformed snapshot never inferred' );
}
check_audit( audit_summary( 'future_action', '{"plan":"free"}', '{"plan":"standard"}' ) === 'future_action', 'unknown action has no guessed summary' );
$json = egov_law_monitor_square_audit_json( '{"reason":"\u672c\u4eba\u78ba\u8a8d","nested":{"key":"日本語"}}' );
check_audit( $json['valid'] && str_contains( $json['text'], '本人確認' ) && ! str_contains( $json['text'], '\u672c' ) && str_contains( $json['text'], "\n    \"reason\"" ), 'Japanese JSON is decoded, indented and readable' );
check_audit( egov_law_monitor_square_audit_json( '{}' )['text'] === '{}' && egov_law_monitor_square_audit_json( '[]' )['text'] === '[]', 'empty object and array remain distinct' );
check_audit( egov_law_monitor_square_audit_json( null )['text'] === 'null', 'SQL null displayed as JSON null' );
$invalid = egov_law_monitor_square_audit_json( '{"bad":<script>}' );
check_audit( ! $invalid['valid'] && $invalid['text'] === '{"bad":<script>}', 'invalid JSON retains original for escaped display' );
check_audit( ! egov_law_monitor_square_audit_json( str_repeat( '[', 600 ) . str_repeat( ']', 600 ) )['valid'], 'excessive nesting fails gracefully' );
$logs = array();
foreach ( $labels as $action => $label ) { $logs[] = array( 'created_at' => '2026-11-12 10:00:00', 'admin_user_id' => $action === 'checkout_cancel_requested' ? 0 : 99, 'user_id' => 2, 'action' => $action, 'old_value' => '{"plan":"free","environment":null}', 'new_value' => '{"plan":"standard","environment":"production","reason":"本人確認"}', 'reason' => str_repeat( '長文<&>', 500 ) . "\n改行<script>危険</script>" ); }
$logs[] = array( 'created_at' => '2026-11-12 11:00:00', 'admin_user_id' => 404, 'user_id' => 2, 'action' => '<script>unknown</script>', 'old_value' => '{bad<script>', 'new_value' => '{"note":"</pre><img src=x onerror=alert(1)>"}', 'reason' => '<a href="javascript:alert(1)">理由</a>' );
$original = serialize( $logs );
ob_start(); egov_law_monitor_render_square_audit( $logs, false ); $html = ob_get_clean();
check_audit( serialize( $logs ) === $original, 'rendering never changes audit values' );
check_audit( str_contains( $html, '<th>日時</th><th>操作</th><th>変更内容</th><th>理由</th><th>詳細</th>' ), 'compact five-column header' );
check_audit( substr_count( $html, '<summary>詳細を表示</summary>' ) === count( $logs ) && substr_count( $html, '<pre>' ) === count( $logs ) * 2, 'every row has both JSON snapshots in native details' );
check_audit( str_contains( $html, '2026-11-12 10:00:00' ) && str_contains( $html, '管理者&lt;script&gt;' ) && str_contains( $html, '(ID: 99)' ), 'timestamp and escaped administrator name/ID retained' );
check_audit( str_contains( $html, '利用者による申請 (ID: 2)' ) && str_contains( $html, '削除済み管理者 (ID: 404)' ), 'user cancellation actor and deleted administrator distinguished' );
check_audit( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img ' ) && ! str_contains( $html, '<a href=' ) && str_contains( $html, '&lt;img' ), 'all JSON, action, reason and actor output escaped' );
check_audit( str_contains( $html, '保存済みJSONが不正' ) && str_contains( $html, '{bad&lt;script&gt;' ), 'bad JSON visible without breaking page' );
check_audit( str_contains( $html, '本人確認' ) && str_contains( $html, '&lt;script&gt;unknown&lt;/script&gt;' ), 'Japanese and unknown operation remain visible' );
check_audit( str_contains( $html, 'white-space:pre-wrap' ) && str_contains( $html, 'overflow-wrap:anywhere' ) && str_contains( $html, '@media (max-width:782px)' ) && str_contains( $html, 'data-label="理由"' ), 'long reasons/JSON wrap and narrow layout has cell labels' );
ob_start(); egov_law_monitor_render_square_audit( array(), false ); $empty = ob_get_clean();
check_audit( str_contains( $empty, 'colspan="5">操作履歴はありません' ), 'empty history row' );
ob_start(); egov_law_monitor_render_square_audit( array(), true ); $failed = ob_get_clean();
check_audit( ! str_contains( $failed, '操作履歴はありません' ), 'read failure not misrepresented as empty history' );
check_audit( egov_law_monitor_square_audit_label( 'subscription_cancellation_requested' ) === 'Standard解約申請' && egov_law_monitor_square_audit_label( 'subscription_cancellation_completed' ) === 'Standard利用終了・Free変更', 'service cancellation actions have dedicated Japanese labels' );
check_audit( audit_summary( 'subscription_end_date_set', '{"end_date":null}', '{"end_date":"2026-11-15"}' ) === '終了日未登録 → 2026-11-15', 'end date registration summary' );
check_audit( audit_summary( 'subscription_cancellation_completed', '{"plan":"standard"}', '{"plan":"free"}' ) === 'Standard → Free', 'cancellation completion summarizes actual entitlement change' );
echo "{$checks} audit display checks passed; no DB or API used.\n";
