# Standard申込・Payment Link発行（第3段階・第一段階）

ログイン中のFreeユーザーがSquareの決済画面へ進むための機能です。Subscription候補検索、契約自動登録、Webhook、定期照会、自動承認、自動解約はありません。プランの正本は引き続きユーザーメタです。申込や戻り先アクセスでプラン・AI利用回数は変わりません。

## 事前設定

### 開発中の公開制限

`EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC`が未定義または真偽値`false`の場合、申込機能は`manage_options`権限を持つ管理者と、`EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS`に指定したテストユーザーに限定します。テストIDはWordPressユーザーIDの整数配列で指定し、未定義は空配列です。文字列のIDや配列以外の値は許可に使いません。`PUBLIC`が真偽値`true`の場合だけ、全ログインユーザーが申込対象になります。公開してもFree・契約なし・申込状態・nonce等の既存条件は維持します。

本番で開発中の設定例（`wp-config.php`は運用者が別途設定）：

```php
define( 'EGOV_LAW_MONITOR_STANDARD_APPLICATION_PUBLIC', false );
define( 'EGOV_LAW_MONITOR_STANDARD_TEST_USER_IDS', array() );
// テストユーザーを許可する場合だけ、対象環境で確認した整数IDを配列に指定する。
```

一般公開時は、上の既存定義を`true`へ変更します。同じ定数を二重定義しないでください。Squareの環境・トークン・発行有効化とは別の制御です。テストユーザーに管理者権限は不要で、この配列で契約管理権限が付与されることもありません。

一般ユーザーには独立申込ページで準備中の案内だけを表示し、マイページでは現在プラン表示を残して申込リンクを隠します。発行・既存リンクの再表示・発行回復・利用者キャンセル申請のサーバー入口も同じ共通関数で拒否します。既存申込は削除・変更せず、管理者用の契約照会・登録・承認・キャンセル完了は既存の権限で利用できます。Square申込用のAJAX／RESTエンドポイントは現在ありません。

法令更新・AI要約・ウォッチ・通知設定の判定には、この公開制限を適用しません。既に配布されたSquare URL自体を無効化する制御でもありません。両申込関連ページは引き続きCDN／ページキャッシュから除外し、管理者の画面を一般ユーザーへ配信しないでください。

ステージング確認：未定義／falseで一般ユーザーの直接URL・旧リンク表示・直接POSTが止まり、Free表示・Free機能が維持されること、管理者と指定テストユーザーは従来の申込・キャンセルが可能なこと、非指定ユーザーは拒否されることを確認します。trueでは全ログインユーザーが既存条件に従って利用でき、未ログインは拒否されることも確認してください。実決済を伴う操作は別途承認したSandboxでのみ行います。

### Square接続設定

既存の環境／トークン定数を再利用します。秘密情報をコード、DB、ブラウザー、ログへ入力・保存しないでください。

- `EGOV_LAW_MONITOR_SQUARE_ENVIRONMENT`：`sandbox` または `production`。
- `EGOV_LAW_MONITOR_SQUARE_ACCESS_TOKEN_SANDBOX`／`..._PRODUCTION`：選択環境の既存トークン。

以下をサーバーの`wp-config.php`等に追加します（例示のみ。本作業では設定ファイルを変更しません）。値はSquare側で確認してください。両環境の定数を同時に設定する必要はありません。

```php
define( 'EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_SANDBOX', '<Sandbox Location ID>' );
define( 'EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_SANDBOX', '<Sandbox Plan Variation ID>' );
// 本番で使用する場合だけ、本番用の別IDを設定します。
// EGOV_LAW_MONITOR_SQUARE_LOCATION_ID_PRODUCTION
// EGOV_LAW_MONITOR_SQUARE_PLAN_VARIATION_ID_PRODUCTION
define( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL', 'https://example.com/standard-application/' );
// Square側の毎月500 JPY・税込・継続期間・税設定を確認してから明示的に有効化。
define( 'EGOV_LAW_MONITOR_SQUARE_CHECKOUT_ENABLED', true );
```

初期状態は無効です。戻り先は自サイトのHTTPS URL（認証情報・フラグメントなし）に限定します。このURLの固定ページへ`[egov_standard_application]`を配置してください。既存のマイページ本文はリポジトリに含まれないため、配置は別途WordPress上の作業になります。ログイン中の個人向けページをCDN／ページキャッシュで共有しないでください。

必要なCreatePaymentLink権限：`ORDERS_READ`、`ORDERS_WRITE`、`PAYMENTS_WRITE`。既存照会用の`SUBSCRIPTIONS_READ`のみのトークンでは発行できません。環境不正・選択トークン不足・ID不正・無効設定では発行しません。反対環境のトークンへフォールバックしません。

## Square側の500円設定とリクエスト

Square側に、JPY、毎月、500円、継続課金、有料1フェーズ、無料期間なしのSubscription Plan Variationを用意します。親Plan IDを指定してはいけません。APIのフィールドは`checkout_options.subscription_plan_id`ですが、値にはVariation IDを入れます。

`order.location_id`、`order.reference_id`（サーバー生成の36文字UUID）、1個・500 JPYの商品、チップなし・配送先収集なし・固定戻り先を送信します。UUIDは冪等キーにも使用します。ブラウザーからのユーザーID・環境・Location・Variation・金額を採用しません。

SquareはCheckout価格の不一致を上書きとして扱うため、**初回・継続請求の両方が税込500円になる設定の実測が必要です**。550円になる追加税やチップ・送料を設定しないでください。現在の実装はCatalog APIで周期や税設定を取得しません。有効化は運用上の設定確認を前提にします。作成応答の注文ID・UUID・Location・注文合計500 JPYが検証できない場合は、購入リンクを表示せず結果不明扱いにします。必要フィールドが応答にない場合も推定しません。これだけで継続請求額や税務上の内税明細を検証したとは扱いません。

## 保存と重複対策

`{prefix}egov_square_checkout_attempts`を追加します。独立したスキーマバージョン`egov_law_monitor_checkout_schema_version = 1`を使用し、新規有効化と`admin_init`で作成・検証します。契約テーブルの構造／既存スキーマバージョンは変更しません。既存データを削除・初期化しません。

カラム：`id`（BIGINT UNSIGNED、自動採番主キー）、`application_uuid`（CHAR36、UNIQUE）、`user_id`（BIGINT UNSIGNED、検索インデックス）、`environment`（VARCHAR10）、`request_json`（LONGTEXT）、`payment_link_id`／`template_order_id`（VARCHAR255、NULL可）、`checkout_url`（TEXT、NULL可）、`state`（VARCHAR20）、`claim_token`（CHAR32）、`claimed_at`／`created_at`／`updated_at`（DATETIME、UTC）。`environment, payment_link_id`にも全体UNIQUE制約を置きます。

保存は第2-Bの専用非永続mysqli接続を再利用します。同一DB・InnoDB・必要カラム／制約を確認できない構成、独自DBドロップイン、既存専用接続で再現できないTLS等は拒否します。共有接続へフォールバックしません。申込テーブルがない場合は、管理画面で更新処理を実行してから公開してください。

1. ユーザー行をロックし、DBからFreeを確認。既存契約・既存申込を確認。
2. UUIDと固定リクエストJSON、処理所有トークンを保存・COMMITし、専用接続を閉じる。
3. 固定SquareホストへCreatePaymentLinkを1回だけ送信（20秒、TLS検証、リダイレクト禁止、応答128 KiBまで）。
4. 別の短いトランザクションで所有トークンを検証し、結果を保存。確定後だけ購入リンクを返す。

HTTP通信中はトランザクションを保持しません。遅れて戻った古い処理は、新しい処理所有トークンの結果を上書きできません。保存のCOMMIT結果不明時は通常成功にせず、最初の予約が不明ならAPI自体を呼びません。結果保存が不明ならリンクを返さず、同じ記録から回復します。

状態は`creating`（発行処理中）、`ready`（リンク記録済み）、`unknown`（発行結果未確認）、`cancel_requested`（利用者のキャンセル申請中）、`closed`（管理者が確認してキャンセル完了を登録）です。**支払済み・Subscription生成済み・Standard承認済みを意味しません**。

- 発行済みは同じリンクを再表示し、APIを呼びません。決済済みリンクで再課金できると想定しません。
- 同時クリックは処理中として拒否します。`creating`の処理所有期間は90秒、`unknown`の再確認間隔は30秒です。
- 明示的な再確認は、作成から15分以内だけ、保存済みUUID・キー・JSON・APIバージョンで行います。設定変更・環境混在・所有者不一致を拒否します。
- 15分は本アプリの保守的な上限で、Squareの冪等キー保持期間の保証ではありません。長時間経過・複数の未解決記録・不整合は管理者確認に止め、新UUIDへ切り替えません。履歴が複数あっても`closed`は未解決件数に含めません。ユーザー行ロックの下で新規申込を1件に限定し、新UUID・冪等キーを発行します。
- PHP終了等で状態が`creating`に残っても、期間内なら同じリクエストで回復します。期限後はSquare側のAPI履歴等も確認し、DB行を消して再申込しないでください。
- Freeでも過去のSquare契約があれば、新規課金を防ぐため管理者確認を案内します。第2-BのFree変更はSquareの解約ではありません。`cancel_requested`と旧キャンセル状態はCreateの再試行対象にせず、新規申込・旧リンクの表示を停止します。

## マイページと独立申込ページの配置

- マイページ「ご利用プラン」タブには`[egov_plan_status]`を配置します。現在プランと本人の申込状況の閲覧、独立申込ページへの案内のみで、POST処理・Square購入URLの直接表示・API通信はありません。
- 独立した「Standardプランのお申し込み」固定ページには`[egov_standard_application]`を配置します。マイページ側からはこの旧ショートコードを外してください。固定ページ・タブ本文の編集はWordPress側の作業です。
- 導線は`home_url('/standard-application/')`から生成した同一サイトのHTTPS URLです。ホスト名をコードに固定せず、発行設定・トークン・戻り先定数にも依存しません。ステージングでは既存の`/standard-application/`固定ページを使用します。本番でも同じスラッグを準備してください。
- Free・未解決申込なし（closed履歴のみを含む）は申込ページへのリンク、ready／creating／unknownは申込状況と詳細リンク、cancel_requestedは手続き中と詳細リンクを表示します。登録済み契約・読み取り失敗・不整合・旧未解決状態では新規申込を案内せず、管理者確認を促します。StandardはWordPress上の利用状態を表示し、Square側の支払正常・契約有効とは断定しません。
- 両ショートコードは個人別のためキャッシュ除外を要求します。CDN・ページキャッシュでも両固定ページを除外してください。

### 切り替え時の重要事項

`EGOV_LAW_MONITOR_SQUARE_CHECKOUT_RETURN_URL`はコードから変更しません。運用者が別途、独立ページの正しいHTTPS URLへ設定してください。既存の申込フォームの送信先と、新規発行するSquareリンクの戻り先に使用するため、旧マイページのままでは新配置と一致しません。発行受付に必要な設定検証も正常であることを確認してください。

保存済みのrequest_json、redirect_url、UUID、冪等キーは書き換えません。既存readyリンクは作成時の旧戻り先を使う場合があるため、旧マイページには閲覧専用案内を残します。creating／unknownは、戻り先変更後の保存リクエスト一致検証で再試行を拒否し得ます。切り替え前に未解決申込を調査し、未解決のまま新UUIDを発行して解除しないでください。この移行で自動回復・自動移行は追加しません。

一時診断の表示・診断引数・診断専用テストは削除しました。通常検証の`egov_law_monitor_checkout_config_checks()`は維持しています。

## 管理者と利用者の画面

独立ページの申込パネルは本人の申込UUID・状態・検証済み購入URLだけを使い、トークン・環境・Location・Variation・保存リクエスト・処理所有トークンを公開しません。取得時もユーザー所属を検証します。未ログイン・Standardは発行不可です。戻り先は表示だけで、GETでAPIを呼んだりプラン変更したりしません。

発行済み`ready`リンクの表示は新規発行の可否と分離しています。本人のFreeユーザーで、保存環境が現在の有効な環境定数と一致し、URLが安全で、登録済み契約がなく契約情報も正常に取得できた場合は表示します。受付停止やトークン・Location・Variation・戻り先の発行用設定不備だけでは既存リンクを隠しません。契約あり・取得失敗・環境不一致・URL欠落／不正の場合は理由を表示し、再発行しません。`cancel_requested`ではリンクを表示しません。

既存Square契約管理に、対象ユーザーの最新50件の申込UUID・環境・申込状態・Payment Link ID・注文テンプレートID・UTC日時を表示します。管理者権限が必要です。API結果の生データ、カード情報、認証情報は保存・表示・ログ出力しません。契約登録・Standard承認・Free変更の既存処理は維持します。

## キャンセル申請と管理者による完了登録

SquareはPayment Link・決済・Subscription・継続課金・リンク削除を管理します。WordPressはキャンセル申請と管理者による完了登録を管理します。キャンセル関連処理からSquare APIは一切呼びません。既存のリンク発行・契約照会・手動承認・Free変更とは独立しています。テーブル・カラム・スキーマバージョンの追加変更はありません。

### 利用者の操作

独立した申込ページの`[egov_standard_application]`で、`ready`の申込を開き「申込のキャンセル申請」の注意事項を読み、「キャンセルを申請する」で確認ダイアログを開き、内容を確認して「キャンセルを申請する」を押します。「戻る」やEscでは申請しません。JavaScript無効・非対応ブラウザでは申請操作を無効にして管理者への問い合わせを案内します。**申請時点ではSquareのリンクは無効にならず、決済・契約のキャンセルも完了していません。** 管理者が処理するまで既に開いたSquare画面でも決済しないでください。

ログイン・所有者・ユーザーと申込別nonce・全行署名・状態を検証し、`ready → cancel_requested`と監査履歴を同一の専用DBトランザクションで保存します。申請後は「キャンセル申請中です。管理者が確認後、手続きを行います。」と表示し、旧リンクの表示と新規発行を止めます。二重申請や古い画面の操作は拒否します。申請処理にSquareトークンは不要で、API発行受付の停止中も申請中の表示を維持します。

利用者のキャンセル申請POSTは`template_redirect`でHTML出力前に1回だけ処理し、成功後は同一サイトの独立申込ページへ303リダイレクトします。以後のGET表示・再読み込みでは申請を再実行しません。ショートコード自体にはキャンセルPOST処理を持たせません。nonce・所有者・署名・状態の本当のエラーは表示し、成功として隠しません。別リクエストで古いPOSTを再送した場合は従来どおり競合として拒否します。

### 管理者のSquare側での確認・手動処理

1. Square契約管理のユーザー・申込UUID・環境・Payment Link ID・注文テンプレートIDを照合し、対象の取り違えを防ぎます。
2. 対応するSquare環境のDashboardで対象リンクを確認して削除します。WordPressのボタンはリンクを削除しません。Dashboardで対象の特定・削除ができない場合は、Squareサポートへ確認し、完了登録を保留してください。
3. 関連決済・Subscriptionの有無を確認します。リンク削除や注文テンプレートCANCELEDだけでは未決済・未契約と判断しません。決済済み・契約成立・処理中・関連付け不明・その他未解決がある場合は申請中のまま保留します。SandboxでSubscriptionが見つからないことも、本番の不存在を証明しません。
4. 未決済・契約未成立で、未解決事項がないことを十分に確認できた場合だけWordPressで完了登録します。返金・契約解約・課金停止はこの機能では行いません。

### WordPressでの完了登録

対象ユーザーのSquare契約管理で`cancel_requested`の行を選び、次の3点をチェックします。

- 対象リンクの削除確認
- 関連決済・Subscriptionを調べ、未決済・契約未成立であることの確認
- 未解決事項がないことの確認

処理理由・備考（対象を特定した根拠やSquareで行った作業）を入力して「キャンセル完了」を押します。**チェックは技術的な証明ではなく管理者の確認記録です。確認が不十分なら押さずに保留してください。** 登録時のUTC日時を確認日時・完了日時として監査履歴へ保存します。

`manage_options`、専用nonce、対象ユーザーの存在、所属・`cancel_requested`状態・全行署名を検証します。登録済みSquare契約がある場合は未成立としての完了登録を拒否します。ユーザー行と申込行のロック下で`closed`への更新と監査記録を一体で確定します。複数管理者・二重送信・古いフォームは拒否します。失敗・COMMIT結果不明は成功と断定せず、画面再読み込みと保存状態・監査履歴の確認を案内します。確認せずに再操作しないでください。

### 監査と再申込

既存の管理者操作履歴テーブルを再利用します。利用者申請は`checkout_cancel_requested`、`admin_user_id=0`、JSON内の`actor_type=user`と`actor_user_id`で記録し、管理画面でも利用者操作と表示します。`0`は管理者を表しません。完了登録は`checkout_cancel_completed`と実際の管理者IDを記録します。双方に対象ユーザー・申込UUID・前後状態・日時を残し、完了時は理由・確認事項も保存します。API応答・トークンは保存しません。外部で監査ログを集計する場合も、操作名とactor_typeで利用者と管理者を区別してください。

過去の申込がすべて`closed`で、本人がFree、登録済みSquare契約なしの場合にのみ、新UUID・冪等キーによる再申込が可能です。複数のclosed履歴を保持し、ユーザー行ロック下で未解決申込を最大1件に限定します。申請中や旧状態をCreateの回復対象にはしません。プラン、契約確認情報、AI利用回数、ウォッチ設定はキャンセル処理で変更しません。

旧実装の`closing`・`canceling`・`cancel_unknown`等は未解決のまま保持し、リンク表示・新規発行を停止します。`closed`や`cancel_requested`への自動変換、旧行の完了登録、再申込解除は行いません。過去にSquare側へ操作した可能性があるため、個別調査と別途承認された復旧手順が必要です。DBを手修正して無条件に解除しないでください。

## ローカル検証と反映手順

### 申込からのSquare契約照会・登録補助

ユーザー別のSquare契約管理で、`ready`の申込行の「Square契約を照会」を押します。対象ユーザーの未解決申込が1件で、所属・全行署名・ID・保存環境が確認できる場合だけ照会します。通常の画面表示ではAPIを呼びません。照会・登録確定は他の保存・承認・キャンセル操作と別々に実行してください。

照会は既存の環境別アクセストークンを使用し、保存済みrequest_jsonのLocation・Plan Variation・申込UUIDを基準にします。現在の発行用Locationや戻り先設定で過去の申込内容を置き換えません。保存環境と現在の環境が異なる場合は拒否し、反対環境にフォールバックしません。必要な読み取り権限は`SUBSCRIPTIONS_READ`と、注文テンプレート再照合用の`ORDERS_READ`です。Squareの契約を作成・変更・解約するAPIは呼びません。

元注文のID・reference_id・Locationを照合したうえでSearchSubscriptionsをLocation単位で検索し、全ページ・全phaseのorder_template_idを完全一致で比較します。1ページ100件、最大5ページ、全体30秒、各通信最大10秒・応答256KiBが上限です。同じSubscription内の複数phase一致は1件にまとめます。候補を見つけても後続ページまで確認します。phase欠落・カーソル異常・ページ上限・通信エラー・不整合は検索未完了として登録を停止します。古い契約などのphase不足でも止まる保守的な仕様です。

全検索で一致1件、必要IDが有効、Location・Variationが一致、statusがACTIVEの場合のみ、Customer／Subscription ID・状態・Location・Variation・環境・照会UTC日時を登録候補として表示します。ACTIVEも支払済みの証明ではありません。一致0件は契約不存在を意味しません。複数一致・CANCELED／PAUSED等・検索未完了の場合は既存の手動登録を利用してください。Sandboxで生成されない契約を、氏名・メール・金額の推測で補完しません。

管理者が「この契約を登録」を押すまでDBへ契約を書き込みません。候補は管理者・対象ユーザー・申込・環境・署名付きレコード状態に結び付けた署名で保護し、期限は5分です。確定時は全API検索と注文照合を再実行し、候補が変われば保存を拒否します。ブラウザのIDをそのまま保存しません。

API終了後、既存の独立mysqliによる契約保存処理を使います。保存直前に同一DB・InnoDB・既存スキーマ・申込スキーマを確認し、トランザクション内でユーザー行と未解決申込をロックし、所有者・ready状態・環境・全行署名を再確認します。この登録経路は新規申込・キャンセル処理と同じユーザー行で直列化します。状態変更や他ユーザーの契約を検出した場合は保存しません。既存UNIQUE制約と契約＋監査の原子性・結果不明処理を維持します。

管理者メモに申込UUID・環境・注文テンプレートID・最新照合UTC日時を記録し、既存のsubscription_created監査にも保存後レコードとして残します。生のAPI応答・トークン・署名用処理情報は保存しません。確認日時verified_at・verified_byはNULL、プランは変更せず、既存のStandard承認は別途実施します。申込状態readyも変更しません。新規テーブル・カラムはありません。

Dashboard導線は本番のDashboardトップ、SandboxのDeveloper Consoleのみです。Customer／Subscriptionの詳細URLは組み立てません。申込一覧のUUID・Payment Link ID・注文テンプレートIDで手動照合します。Sandbox DashboardはSubscription閲覧に対応していないため、API照会・サポートで確認してください。

**残る制約：** SearchSubscriptionsは検索全体のスナップショットや、複数API・WordPress DB間の分散トランザクションを保証するものではありません。検索後にSquare側の状態が変わる可能性は残ります。API一致は管理者確認の補助であり、支払保証や自動承認ではありません。既存の手動契約登録は従来のままで、その経路と新規リンク発行の同時実行を今回全面的に改修してはいません。既に開始したSquare通信も取り消しません。手動登録時も未解決申込を確認してください。

反映時は`includes/square-checkout-contracts.php`と更新したプラグイン本体・契約保存・申込表示・管理画面を一式配置します。実DB・実API・本番Checkoutからのテンプレート一致は未検証です。別途承認後、Sandboxで照会・権限不足・検索失敗・署名期限・登録後もFree／未確認・監査・重複拒否を確認してください。Subscription生成を確認できない場合は完全なCheckout経路の検証済みとは扱わず、手動登録を維持します。

仕様：[SearchSubscriptions](https://developer.squareup.com/reference/square/subscriptions/search-subscriptions)、[Sandboxの制約](https://developer.squareup.com/docs/devtools/sandbox/overview)。

```powershell
php tests/square-api-test.php
php tests/square-subscriptions-test.php
php tests/square-approval-test.php
php tests/square-checkout-test.php
php tests/square-application-access-test.php
php tests/square-checkout-contracts-test.php
php tests/square-checkout-cancel-test.php
php tests/square-checkout-prg-test.php
php tests/square-plan-status-test.php
node tests/square-checkout-dialog-test.js
```

新テストは既存承認ハーネスを再実行した後、独立セッションとHTTPの代替実装で申込を検証します。実通信・実DB接続はしません。テスト専用コールバックはHTTPフォームから設定できません。

反映は別途承認後に行います。

1. プラグインとDBのバックアップ。更新前は発行を無効のままにする。
2. `egov-law-monitor.php`、新規`includes/square-checkout.php`／`square-checkout-page.php`／`square-checkout-cancel.php`、更新した`includes/admin/admin-square-subscriptions.php`を一式配置。既存第2-Bの依存ファイルは維持。
3. WordPress管理画面で更新処理を動かし、新テーブルのInnoDB・列・主キー・UUID／環境別リンクUNIQUE・ユーザーインデックス、バージョン1を確認。
4. Sandbox側の設定と権限を確認し、設定定数と固定ページのショートコードを準備。有効化後、ログインした専用Freeユーザーで実行。
5. 500円表示、UUID保持、二重クリック、同じリンク再表示、戻り先でもFree維持、管理画面への申込表示を確認。
6. 既存契約登録・承認・Free変更の動作、他ユーザーへURLが漏れないこと、ログ・キャッシュに秘密情報が残らないことを確認。
7. 専用Sandboxユーザーでキャンセル申請し、申請監査・旧リンク非表示・発行停止を確認。管理者がSquare側を手動処理・確認した後、3チェックと理由で完了登録し、完了監査・closed・新UUIDでの再申込を確認。二重操作、古いフォーム、不十分な確認の拒否も確認する。
8. 実DBでの行ロック・監査失敗時の原子性・COMMIT結果不明は未検証。旧キャンセル状態の発行停止と、既存Standard承認・Free変更・AI利用状況・通知・ウォッチの不変も確認する。

未検証：実WordPress／MySQLでの行ロック・接続断・DDL更新・COMMIT応答喪失、実APIの応答フィールド／URL形式、トークン権限、初回・継続の税込500円、キャッシュ／外部プラグイン。ユーザー実測ではSandboxのリンク作成・テスト決済は成功しましたがSubscription生成は確認できていません。本番の生成・紐づけ成功は未保証です。本番の実課金確認は別途承認と手順が必要です。

公式仕様：
- [CreatePaymentLink](https://developer.squareup.com/reference/square/checkout/create-payment-link)
- [Subscription Plan Checkout](https://developer.squareup.com/docs/checkout-api/subscription-plan-checkout)
- [Order / reference_id](https://developer.squareup.com/reference/square/objects/order)
- [API生成リンクの制約](https://developer.squareup.com/docs/checkout-api/common-pitfalls)
