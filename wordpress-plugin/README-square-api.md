# Square契約状況の手動照会（第2-A段階）

ユーザー管理の「Square契約管理」で、対象契約の「Square契約状況を確認」を押すと、登録済みSubscription IDを使ってサーバーから1回だけGET照会します。通常表示・契約保存・手動承認では照会しません。

## Standardの手動承認（第2-B段階）

1. ユーザー管理から対象ユーザーのSquare契約管理を開きます。
2. Square Dashboardで、そのユーザーのCustomer／Subscription ID、契約・支払状況を確認します。API照会成功やACTIVE表示だけで支払済みとは判断しません。
3. 対象契約の「この契約でStandardを手動承認」を開き、確認チェックと確認理由を入力して承認します。複数契約がある場合も、対象契約を明示的に選びます。

プランの正本は既存のユーザーメタ`egov_law_monitor_plan`です。既存の独立した非永続mysqli接続で、プラン、対象契約の`verified_at`／`verified_by`、`standard_approved`監査履歴を同一トランザクションに保存します。確認日時はサイト時刻です。履歴の変更前後JSONにはプランと対象契約のスナップショットが残ります。確認記録は現在のプランの判定には使いません。新しいテーブル・プラン状態カラムは追加しません。

- `manage_options`、操作別nonce、ユーザー／契約所属、明示的確認、入力内容を検証します。ユーザー行・対象契約・プランメタをロックし、署名付きの契約／プラン状態とプラン変更履歴IDを比較します。二重承認、古い画面、Free→Standard→Freeを経由した古い画面は拒否します。
- 「法令ウォッチ ユーザー管理」は9列・従来の表示順を維持した閲覧専用一覧です。プランと通知は既存メタの現在値をテキスト表示します。プラン選択・通知チェック・保存・AI要約の「今月をリセット」はありません。旧保存／リセットPOSTも拒否します。ほかの呼び出し元がないAI要約リセット用DB関数も削除しました。利用者マイページ・通知設定・ウォッチAPIの処理は変更しません。
- プラン変更はユーザー別のSquare契約管理に集約します。既にStandardの場合は再承認できません。Free→Standardは登録済み契約の明示的承認のみで、契約IDなしのStandard化と旧ユーザー管理nonceは内部でも拒否します。
- Standard→Freeは、契約単位ではなく「このユーザーをFreeへ変更」から行います。変更理由は必須です。専用のユーザー別nonce、署名、現在Standardであることをロック後にも検証し、プランと`plan_changed`監査履歴を同一トランザクションに保存します。既にFreeなら拒否し、二重操作で履歴は増やしません。Square契約がないStandardユーザーも対象にできます。
- Free変更はSquare側の解約・課金停止ではありません。Square契約・確認情報・過去の承認履歴・AI要約回数は保持します。通知メタも更新しません。
- Freeの確定後、既存ウォッチ上限処理を実行し、有効件数が上限内であることとプラン状態を再確認します。失敗・再確認不能は「Free変更と監査履歴は確定済み、ウォッチ適用は未確認」と区別して表示します。一部だけ停止している可能性があります。キャッシュ更新失敗の場合は不正な上限適用を避けてウォッチ処理を行いません。COMMIT結果不明時もウォッチ処理を開始しません。
- ウォッチ適用警告ではFree変更を再送信せず、DBの現在プラン・履歴・キャッシュ・有効ウォッチを管理者が確認してください。ページ再読み込みだけでFree操作を再試行することはありません。障害解消後の既存ウォッチ一覧取得時にも通常の上限処理がありますが、復旧結果は別途確認が必要です。プラン確定とウォッチ処理は別トランザクションで、ウォッチ処理の完全な排他・一括取り消しは保証しません。
- Freeに戻しても確認記録と履歴を残します。再確認後のStandard再承認は新しい履歴になります。確認済み契約はメモだけ編集でき、Customer／Subscription IDを変更する場合は別契約として新規登録してください。
- ユーザー表は`$wpdb->users`／`$wpdb->usermeta`を使います。承認・プラン変更時は、同一DBの実テーブル・InnoDB・必要カラムと主キー、既存契約スキーマを専用接続で確認できない場合は拒否します。メタ重複や未知のプラン値は自動修復しません。既存専用接続のTLS・独自DB構成等の制限も引き継ぎます。
- 保存失敗時はROLLBACK結果を確認します。COMMIT応答喪失やROLLBACK失敗は結果不明です。再操作前にDBのプラン・対象契約確認情報・監査履歴を確認してください。自動再接続・自動再試行はしません。
- プランを変更しようとした後は、成功・失敗・結果不明を問わず`user_meta`キャッシュを無効化します。確定後のキャッシュ処理が例外になった場合は、DB保存済みとキャッシュ確認の必要性を表示します。キャッシュ障害が疑われる場合、画面表示だけでなくDBを確認してください。
- 専用SQL更新のため、WordPress標準の`update_user_metadata`フィルター、`update_user_meta`／`updated_user_meta`等のフックは実行しません。リポジトリ内にはこれらを利用する処理は見つかりませんでしたが、外部プラグインのフック、メタ読み取りフィルター、永続オブジェクトキャッシュとの互換性はステージングで確認が必要です。リポジトリ外から直接プランを書き換える処理は本ロック規約の対象外です。
- Free累計3回、Standard月100回のAI要約上限・既存利用回数は変更しません。承認で利用回数を初期化せず、停止中ウォッチの自動復帰もしません。Free変更時の既存ウォッチ上限適用は維持します。

Webhook、自動課金確認、自動プラン変更、Payment Link発行は追加していません。

## サーバー設定

既存の`wp-config.php`で定義されている次の定数を直接参照します。この機能のために`wp-config.php`を変更する必要はありません。実際のトークンをリポジトリや管理画面へ入力・保存しないでください。

- `EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT`：`sandbox` または `production`（必須）。
- `EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX`：Sandbox用アクセストークン。
- `EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION`：Production用アクセストークン。

環境に対応するトークンだけを使用します。不正・未設定の環境や、選択した環境のトークンが未設定・不正な場合はAPIへ問い合わせません。反対側の環境のトークン、旧共通定数`EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN`、環境変数へのフォールバックは行いません。

設定値はDB・監査ログ・画面へ保存／表示しません。既存のGoogle Cloud認証とは別の設定です。

## Square側の権限

必要なOAuth権限は **`SUBSCRIPTIONS_READ`のみ**です。この権限に限定したOAuthアクセストークンを用意してください。OAuth認可・トークン取得／更新フロー自体は今回実装していません。

Personal access tokenは権限を限定できないため、最小権限の構成にはなりません。コード側から、設定されたトークンの全権限を検証することはしていません。

- [Retrieve subscription（権限・レスポンス）](https://developer.squareup.com/reference/square/subscriptions-api/retrieve-subscription)
- [Squareアクセストークンの種類と環境](https://developer.squareup.com/docs/build-basics/access-tokens)

## 表示と制限

- API：`GET /v2/subscriptions/{subscription_id}`、固定バージョン`2026-09-16`。
- Sandbox：`https://connect.squareupsandbox.com`、本番：`https://connect.squareup.com`。
- プラン情報はレスポンス内のPlan Variation ID、存在する場合は旧Plan IDを表示します。プラン名を取得するCatalog APIは呼びません。
- Customer IDは大文字小文字も含めて比較します。不一致でも自動修正しません。
- API応答と照会日時は、そのリクエストの画面表示だけに使用します。DB・キャッシュ・監査履歴には保存しません。
- `ACTIVE`等の契約ステータスは支払済みの証明ではありません。請求書・決済履歴は取得しません。
- `verified_at`・`verified_by`、WordPressプラン、契約の登録・編集履歴は変更しません。
- タイムアウト10秒、リダイレクト禁止、TLS検証有効、応答サイズ上限64 KiB。自動再試行・定期実行はありません。
- 通信・APIエラーでは、トークンを含み得る生のエラー詳細やレスポンス本文を表示・記録しません。

## テスト

### Sandboxでの実測と検証範囲

- 別の検証用CLIによるユーザーの実測では、SandboxでPayment Link作成とテスト決済には成功しましたが、そのCheckoutによるSubscription生成は確認できませんでした。
- これは今回の検証条件での結果であり、Sandbox全体の仕様や、本番でのSubscription生成・紐付けを保証するものではありません。Square Developer Forumsへの質問は回答待ちです。
- 第2-A段階は、既に登録されたSubscription IDをGET照会する機能です。Payment Linkからの契約生成・ID自動取得は対象外であり、Sandboxでの決済成功を照会成功の検証とは扱いません。
- 実在するSubscriptionの取得とCustomer ID照合の成功経路は、実APIでは未検証です。以下のテストはHTTP応答の代替実装による検証です。

```powershell
php tests/square-api-test.php
php tests/square-subscriptions-test.php
php tests/square-approval-test.php
```

HTTP・DBの代替実装を使用し、実通信は行いません。実WordPress・Square Sandboxでは、認証、権限、環境、実契約の一致／不一致、管理画面表示を別途確認してください。
