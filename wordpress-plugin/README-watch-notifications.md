# 法令ウォッチのメール通知設定

`[egov_law_search]`の「法令ウォッチのメール通知」ボタンでON／OFFを切り替えます。キーワード未登録でも設定でき、Free／Standardの両方で利用可能です。保存中は操作を無効にし、成功・失敗を表示します。切替にはJavaScriptが必要です。

既存user_meta `egov_law_monitor_notifications`を利用し、ONは文字列`'1'`、OFFは文字列`'0'`で保存します。未設定はON、保存済みOFFは維持します。新しいテーブル・認証情報は不要です。

`POST /wp-json/egov-law-monitor/v1/watch-notifications`にJSONの`notifications: true/false`と既存の`X-WP-Nonce`を送ります。ログイン・nonce・厳密な真偽値を検証し、ユーザーIDはログイン情報だけから取得します。`user_id`の指定は拒否します。同値保存は成功、保存失敗はエラーとし、成功時は現在値を返します。

設定は次回の通知対象取得時から反映します。すでに処理中のメールは送信される場合があります。OFF期間中の更新をON復帰時に遡って送信する機能は追加しません。個別キーワード・有効／停止状態・利用上限・プラン・解約等の契約通知は変更しません。

既存の内部APIが現在の設定を出力し、Python側の既存処理がOFFのユーザーを除外します。CDN・ページキャッシュでは、ログイン後のマイページを共有キャッシュ対象から除外してください。

ローカル検証：`php tests/watch-notifications-test.php`、`php tests/law-watch-page-test.php`、`.venv/Scripts/python.exe -B tests/watch_notifications_test.py`、`node tests/watch-notifications-ui-test.js`。実ブラウザ・実WordPress・実メール送信は別途確認してください。
