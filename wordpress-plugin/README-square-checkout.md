# Standardプラン：固定Square決済リンク

正式リンク：[Squareでお支払いへ進む](https://square.link/u/30s16R3f)

Square Dashboardで作成した既存のサブスクリプション決済リンクを使用します。WordPressからPayment Linkや注文テンプレートを発行しません。カード・決済・Subscription・継続課金・リンク管理はSquare側で行います。管理者は請求書番号で照会し、本人確認後にStandardを承認します。詳しくは[README-square-api.md](README-square-api.md)を参照してください。

## 利用者の表示

- 独立した固定ページには既存の`[egov_standard_application]`を維持します。マイページには`[egov_plan_status]`を維持します。WordPressページ本文の再編集は不要です。
- ログイン済みFreeユーザーで、登録済み契約・未解決の旧申込がなく、公開条件を満たす場合に契約条件と同意フォームを表示します。WordPressプランや申込DBは更新しません。
- 正式リンクは本番用です。`EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT`が`production`の場合だけ案内し、Sandbox設定から本番決済へ誘導しません。APIトークン・Location・戻り先の発行設定の検証には依存しません。
- `EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC`未定義／falseでは管理者と`EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS`の整数ID配列の利用者だけに案内します。trueでは全ログインユーザーが対象です。wp-config.phpは自動変更しません。これはWordPressの案内制御であり、外部で共有された固定リンク自体のアクセス制限ではありません。
- Standardユーザーには利用中の表示だけを行います。Freeでも登録済みSquare契約があれば、新規決済ではなく管理者確認を案内します。APIで支払状況を自動確認しません。
- 支払済みの利用者は再度決済せず、切り替えを待つよう案内します。問い合わせ時には請求書番号を伝えてもらいます。個人向けページはCDN・ページキャッシュの共有対象から除外してください。

## 契約条件への同意

料金・利用上限・毎月自動更新・機能有効化・解約期限・返金条件と、利用規約／特定商取引法に基づく表記／プライバシーポリシーへのリンクを決済前に表示します。未チェックの同意欄はHTMLのrequiredとサーバー側で検証します。JavaScriptは不要です。

`template_redirect`でログイン・バージョンを含むnonce・同意値・既存の申込資格を再確認し、保存成功時のみサーバー固定のSquare URLへ303リダイレクトします。外部リダイレクトには固定URL限定の`wp_redirect`を使用し、許可ホスト設定は変更しません。POSTされた遷移先は使用しません。

user_meta `egov_law_monitor_standard_consent`に`agreed_at`（UTC ISO 8601）、`terms_version`、`conditions_version`の配列を保存します。再同意では最新値で更新し、同値保存も保存済み値を確認して成功扱いにします。履歴テーブル・IP情報は追加しません。規約と条件のバージョンは`standard-plan-page.php`の`EGOV_LAW_MONITOR_STANDARD_TERMS_VERSION`と`EGOV_LAW_MONITOR_STANDARD_CONDITIONS_VERSION`で明示します。公開文書の改定時に対応して更新し、旧版本文は別途保管してください。

同一リクエスト内の重複処理を防止します。戻る・再送信で再同意しても、新規リンク・契約・決済は作成しません。ただしSquareでの二重決済を防ぐものではありません。同意記録はWordPress上の確認記録であり、Squareの決済成立やSubscriptionとの対応を証明しません。固定リンクへの直接アクセスにはWordPressの同意制御は適用されません。

## 旧申込データの保持

`{prefix}egov_square_checkout_attempts`と既存スキーマバージョン・保存リクエスト・UUID・リンクID・注文テンプレートID・履歴は削除・更新しません。旧テーブルの新規作成フックは撤去します。新規サイトではこのテーブルは不要です。

旧テーブルがある場合だけ、対象ユーザーの未解決行の有無を読み取ります。`closed`以外は不明な旧状態も含めて新しい決済への誘導を停止します。テーブル不存在と読み取り失敗は区別し、読み取り失敗では安全側に停止します。閉じた履歴だけなら固定リンクを案内できます。

旧一覧・キャンセル申請・完了操作は撤去しますが、既存のSquareリンクが無効になったとはみなしません。未解決行が残る利用者はDashboardと請求書で管理者が確認してください。未決済の中断申込を再開させるためのデータ移行・終了登録は今回追加していません。状態を推測で閉じたり、履歴を削除して回避したりしないでください。

## 撤去したものと共有処理

- 動的発行、冪等再試行、注文テンプレート照合、SearchSubscriptions、旧キャンセル処理、旧管理一覧、専用JavaScriptと旧専用テストを撤去しました。専用AJAX／RESTルートは元々ありません。旧template_redirectのPOST処理もなくなります。
- 旧手入力登録・個別Subscription照会・個別Standard承認の画面とPOST入口を撤去しました。旧フォームの再送信は拒否します。承認解除のトランザクション入口はFreeのみ受け付け、Standard化は請求書経由に限定します。
- 契約テーブル・監査テーブルの作成／完全性検証、契約保存の内部処理、署名、プラン更新・監査、mysqli接続、承認解除、監査表示は維持します。請求書照会・一括登録承認の内部ファイルは変更していません。
- 過去のキャンセル等の監査ラベル、旧自動登録の環境証拠の読み取りも維持します。旧データを解釈するために必要です。

## 設定の整理

請求書照会では環境別のアクセストークン・Location ID・Plan Variation IDを引き続き使用します。`EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED`、`EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL`、旧共通トークン定数はプラグインから参照しません。不要設定の削除はwp-config.phpの別作業であり、この変更では行いません。

## 反映時の注意

1. プラグイン全体を差分どおり反映し、削除済みの`includes/square-checkout.php`、`square-checkout-page.php`、`square-checkout-contracts.php`、`square-checkout-cancel.php`、`assets/js/square-checkout.js`を配布物から除外してください。単なる上書き配布では旧ファイルが残る点に注意します。DBや保存データは削除しません。
2. 新しい`includes/standard-plan-page.php`、プラグイン本体、管理画面、共有ファイル、CSSを一式反映し、PHP OPcache／CDNの古いコードとCSSを更新します。
3. ステージングでは本番固定リンクから実決済しないでください。Free／Standard・既存契約あり・旧未解決申込あり・一般公開前の案内、旧POST拒否、請求書の照会・承認・環境確認・解除、監査履歴を確認します。
4. 旧Squareリンクの停止、既存Subscriptionの変更・解約は自動実行しません。必要なSquare側の確認・運用は管理者が別途行います。

検証専用worktreeやCLI、リポジトリ外の設定ファイル、WordPressの固定ページ、wp-config.php、本番データはこの整理の対象外です。

## 今回の変更ファイル

- プラグイン：`egov-law-monitor.php`、`includes/admin/admin-square-subscriptions.php`、`includes/square-api.php`、`includes/square-approval.php`、`includes/square-subscriptions.php`、`assets/css/egov-law-post.css`を整理し、`includes/standard-plan-page.php`を追加。
- 削除したプラグインファイル：上記反映手順の旧PHP 4ファイルと専用JavaScript 1ファイル。
- テスト：`square-api-test.php`、`square-subscriptions-test.php`、`square-approval-test.php`、`square-plan-status-test.php`、`square-application-access-test.php`を更新し、`standard-plan-test-bootstrap.php`、`square-cleanup-test.php`を追加。
- 削除した旧専用テスト：`square-checkout-test.php`、`square-checkout-contracts-test.php`、`square-checkout-cancel-test.php`、`square-checkout-prg-test.php`、`square-checkout-dialog-test.js`。請求書・環境確認・一括承認・監査のテストは維持。
- 文書：本ファイルと`README-square-api.md`を更新。
