# Square契約状況の手動照会（第2-A段階）

ユーザー管理の「Square契約管理」で、対象契約の「Square契約状況を確認」を押すと、登録済みSubscription IDを使ってサーバーから1回だけGET照会します。通常表示・契約保存では照会しません。

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
```

HTTP・DBの代替実装を使用し、実通信は行いません。実WordPress・Square Sandboxでは、認証、権限、環境、実契約の一致／不一致、管理画面表示を別途確認してください。
