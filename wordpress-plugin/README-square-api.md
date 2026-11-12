# Square契約管理：請求書番号による登録・承認

固定の決済リンクと利用者向け表示は[README-square-checkout.md](README-square-checkout.md)を参照してください。管理者の契約登録・再承認は請求書番号から行います。

## 請求書番号の照会と一括登録・承認

ユーザー管理から対象ユーザーのSquare契約管理を開き、「請求書番号でSquare契約を照会」にDashboardの番号（例：`000004`）を入力します。先頭ゼロを保持して完全一致検索します。この操作では契約登録・Standard承認・確認記録・監査・申込状態を一切更新しません。

既存の`EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT`、選択環境の`EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_PRODUCTION`または`EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX`、`EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION`／`_SANDBOX`、`EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_PRODUCTION`／`_SANDBOX`を使用します。受付有効化・戻り先URLの設定には依存しません。照会に必要な権限は`INVOICES_READ`と`SUBSCRIPTIONS_READ`です。wp-config.php自体は変更しません。

ListInvoicesは最大200件×5ページ、照会全体30秒・各通信最大10秒で検索します。上限到達・ページ送り不整合・通信失敗は検索未完了として扱い、途中の1件を確定しません。0件・複数候補・認証／権限／404／429・応答不正を区別します。取得したInvoice IDをURLエンコードしてGetInvoice、続いてRetrieveSubscriptionで再取得します。

PAID、請求額と支払済み額の各合計500 JPY、ACTIVE、設定済みLocation／Variation、InvoiceとSubscriptionのCustomer ID一致をそれぞれ表示します。返金済み状態では支払済み金額が残る場合があるため金額だけで判断しません。照会時点の単一請求書の確認であり、過去・将来の全請求の支払い保証ではありません。本人確認も自動化しません。ページ上部のWordPressユーザー名・メールと請求先氏名・メールを並べて確認し、Dashboardや本人との連絡で確認してください。不一致・項目欠落は要確認です。

本番での確認：管理者で対象ユーザーを選び、設定環境・Locationを確認した上で、既知の500円PAID請求書を照会しDashboardと各項目を比較します。存在しない番号、不正nonce、権限不足も確認してください。照会の前後でプラン・契約・確認記録・監査履歴が変わらないことを確認します。SandboxではSubscription付きInvoiceを本番同様に再現できない場合があります。APIトークンやカード情報を画面・ログへ出さず、API応答全文も保存しません。

### 確認後の「契約を登録してStandardを承認」

1. 正常な照会結果は要約表示し、詳細は折りたたみます。WordPressユーザーとSquare請求先の氏名・メールを比較し、Dashboardや本人との連絡で本人確認します。
2. 「対象ユーザー本人の契約であることを確認した」にチェックし、本人確認の根拠を入力します。正常な検証結果と安全なローカル状態を確認できた場合だけ、確定ボタンを表示します。
3. 確定時には管理者・専用nonce・管理者と対象ユーザーに結び付いた署名付き候補（5分）を検証します。GetInvoiceとRetrieveSubscriptionを直接再実行し、ID・状態・金額・顧客・Location・Variation・取得可能なversion／updated_at等が変わった場合は再照会を要求します。
4. API通信を終えてから独立mysqli接続で同一DB・InnoDB・完全スキーマを確認し、ユーザー行と契約・プランをロックします。署名付きプラン履歴リビジョンと契約状態を再検証し、契約・確認記録・Standard化・監査を1トランザクションで保存します。Squareの作成・更新・解約APIは使いません。新規テーブル・カラムは不要です。

同一ユーザーの同一契約はCustomer ID・記録済み環境が一致した場合に再利用します。環境の根拠は、既存の`subscription_created`監査に記録された「請求書照合: 」「申込照合: 」の環境情報、または下記の明示的な環境確認監査から取得します。現在の`admin_note`への文字列追加・書き換えだけでは環境一致と判定しません。現在メモ・監査に明確な環境不一致がある場合や、所属・ID不一致は拒否します。関連監査の読み取り失敗・形式不正・検索上限（作成／環境確認の最新100件）到達時も停止します。

旧手動登録契約などで環境が不明でも、所属ユーザー・Subscription ID・Customer IDが今回の正常なAPI照会結果とすべて一致する場合は、追加確認付きフォームを表示します。「既存契約のSquare環境情報が未登録です」の案内に従い、表示された照会環境のDashboardでLocation・契約・顧客を確認し、本人確認とは別に「環境確認」チェックと「環境確認の根拠」を入力してください。未確認・根拠未入力では承認できず、productionの自動補完も行いません。

確定時の再照会・契約再検証後、`contract_environment_confirmed`監査に契約ID・環境・Invoice／Subscription／Customer ID・確認日時・管理者・確認根拠を保存し、Standard承認と同一トランザクションで確定します。元の管理者メモ、過去の承認・解除履歴は保持し、新規契約レコードは作りません。以後の環境判定はこの監査を利用するため、Freeへの解除後も確認済み環境を再利用できます。監査・承認の一部失敗はまとめて取り消し、確定・取り消し結果不明時は再操作前に契約・プラン・環境確認監査を確認してください。承認解除は維持します。旧手入力登録・個別承認の画面と入口は廃止しました。

新規登録時は`subscription_created`と`standard_approved`、既存契約の再利用時は`standard_approved`を記録します。監査には先頭ゼロを保持した請求書番号、Invoice ID、環境、契約・顧客・Location・Variation、500円PAIDの根拠、確認日時、管理者、本人確認理由を含めます。API応答全文やカード情報は保存しません。同じ契約で既にStandardなら二重保存せず案内します。Free変更後の再承認には新しい照会と確認が必要です。

COMMIT応答喪失・ROLLBACK失敗は結果不明です。再実行前にプラン・契約・監査を確認してください。COMMIT後はWordPressユーザーメタキャッシュを無効化します。キャッシュ例外は確定済みのDB更新と区別します。直接SQLのためWordPress標準メタ更新フックは実行しません（既存承認処理と同じ）。外部プラグインのフック依存・永続キャッシュは実環境で確認が必要です。Square再照会後とDB確定の間の外部状態変化を完全に排他制御するものではありません。

反映後の検証は、テストユーザーで新規承認、同一ユーザー既存契約の再利用、二重送信、他ユーザー契約の拒否、旧手動登録契約の追加環境確認、確認不足・環境不一致の拒否、照会後5分超過、承認解除後の再承認を確認します。環境確認・承認監査が同時に記録され、元のメモが保持されることも確認してください。失敗試験は本番データで行わず、ステージング・モックで実施してください。AI回数・ウォッチ設定・申込履歴が変わらないことも確認します。

## Standard承認解除と既存権限の維持

StandardからFreeへ戻す場合は、対象ユーザーのSquare契約管理にある「Standard承認を解除」を使用します。理由を入力し、WordPressの利用権限だけを変更することを確認して実行してください。管理者権限・専用nonce・確認チェック・署名付きプラン状態を検証します。契約レコードのないStandardテストユーザーも同じ操作で戻せます。

既存のFree変更トランザクションを再利用し、プランと`plan_changed`監査を一体で保存します。契約・申込・過去の監査・AI利用回数は削除しません。Freeのウォッチ上限処理は維持し、その失敗は確定済みのプラン変更と区別して表示します。SquareへのAPI通信・解約・課金停止は行いません。COMMIT結果不明時やキャッシュ警告時は再実行せず、現在プランと履歴を確認してください。

プランの正本は既存のユーザーメタ`egov_law_monitor_plan`です。既存の独立した非永続mysqli接続で、プラン、対象契約の`verified_at`／`verified_by`、`standard_approved`監査履歴を同一トランザクションに保存します。確認日時はサイト時刻です。履歴の変更前後JSONにはプランと対象契約のスナップショットが残ります。確認記録は現在のプランの判定には使いません。新しいテーブル・プラン状態カラムは追加しません。

- `manage_options`、操作別nonce、ユーザー／契約所属、明示的確認、入力内容を検証します。ユーザー行・対象契約・プランメタをロックし、署名付きの契約／プラン状態とプラン変更履歴IDを比較します。二重承認、古い画面、Free→Standard→Freeを経由した古い画面は拒否します。
- 「法令ウォッチ ユーザー管理」は9列・従来の表示順を維持した閲覧専用一覧です。プランと通知は既存メタの現在値をテキスト表示します。プラン選択・通知チェック・保存・AI要約の「今月をリセット」はありません。旧保存／リセットPOSTも拒否します。ほかの呼び出し元がないAI要約リセット用DB関数も削除しました。利用者マイページ・通知設定・ウォッチAPIの処理は変更しません。
- プラン変更はユーザー別のSquare契約管理に集約します。既にStandardの場合は再承認できません。Free→Standardは請求書の再照会と本人確認後の一括承認だけです。旧個別承認・手入力登録POSTと旧ユーザー管理nonceは拒否します。
- Standard→Freeは、契約単位ではなく「Standard承認を解除」から行います。変更理由は必須です。専用のユーザー別nonce、署名、現在Standardであることをロック後にも検証し、プランと`plan_changed`監査履歴を同一トランザクションに保存します。既にFreeなら拒否し、二重操作で履歴は増やしません。Square契約がないStandardユーザーも対象にできます。
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

アクセストークンはDB・監査ログ・画面へ保存／表示しません。環境・Location・Variationは照合情報として表示・記録します。既存のGoogle Cloud認証とは別の設定です。

## Square側の権限

必要なOAuth権限は **`INVOICES_READ`と`SUBSCRIPTIONS_READ`**です。この権限に限定したOAuthアクセストークンを用意してください。OAuth認可・トークン取得／更新フロー自体は今回実装していません。

Personal access tokenは権限を限定できないため、最小権限の構成にはなりません。コード側から、設定されたトークンの全権限を検証することはしていません。

- [Retrieve subscription（権限・レスポンス）](https://developer.squareup.com/reference/square/subscriptions-api/retrieve-subscription)
- [Squareアクセストークンの種類と環境](https://developer.squareup.com/docs/build-basics/access-tokens)

## 現行の表示・通信と制限

- Square通信は管理者の請求書照会・承認確定時だけです。ListInvoices、GetInvoice、RetrieveSubscriptionのGETだけを使用します。契約作成・変更・解約・リンク発行・SearchSubscriptionsは呼びません。
- WordPressユーザーと請求先の本人確認は管理者の責任で行います。API上の一致だけで本人確定・自動承認はしません。
- API認証・Location・Plan Variation設定、独立mysqli接続、既存契約／監査スキーマは維持します。既存DB・履歴は削除せず、旧キャンセルや契約編集の監査も引き続き日本語ラベルと詳細JSONで確認できます。
- 生の通信エラー、API応答全文、カード情報、アクセストークンは表示・保存しません。

## テスト

```powershell
php tests/square-api-test.php
php tests/square-invoices-test.php
php tests/square-invoice-approval-test.php
php tests/square-approval-test.php
php tests/square-subscriptions-test.php
php tests/square-audit-display-test.php
php tests/square-plan-status-test.php
php tests/square-application-access-test.php
php tests/square-cleanup-test.php
```

すべてHTTP／DBの代替実装によるローカル検証です。今回の整理では実API・実DBへ接続しません。請求書承認の本番正常動作は管理者が確認済みですが、この整理後の読み込み・表示・承認・解除・キャッシュはステージングで改めて確認してください。
