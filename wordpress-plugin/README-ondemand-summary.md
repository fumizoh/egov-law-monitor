# オンデマンドAI要約・工程A

法令検索・改正履歴選択・内部版のメタデータ照合だけを行います。Cloud Run、Gemini、背景情報生成、Premium公開、決済、通常AIのキャッシュ・個人履歴・利用回数は使用しません。公式本文もこの工程では取得しません。

## 設置と停止

1. プラグイン反映時はOFFのままとします。
2. 固定ページをスラッグ `ondemand-summary` で作成し、本文へ `[egov_ondemand_summary]` を配置します。
3. マイページへ `[egov_ondemand_link]` のショートコードブロックを配置します。既存タブは変更しません。リンクは対象ページが公開済みで、機能ONかつ管理者のときだけ表示します。
4. LiteSpeed、ホスティング、CDNのページキャッシュから専用ページ、マイページ、`/wp-json/egov-law-monitor/v1/ondemand/` 配下を除外します。RESTが `?rest_route=` 形式の場合も除外します。既存キャッシュを管理者が消去してから検証します。
5. 「設定 → e-Gov Law Monitor AI」下部の独立フォーム「オンデマンド機能の管理者検証」をONにします。

`egov_law_monitor_ondemand_enabled` は文字列 `1` / `0`、未設定・不正値はOFFです。Settings APIのmanage_optionsとnonceで保護します。ONでもmanage_options必須です。Premium判定で管理者制限を解除する経路はありません。OFFに戻すと次の実行から拒否しますが、送信済みHTTP要求は中断しません。

ページはtemplate_redirectでHTML出力前に拒否し、ショートコードでも再検証します。DONOTCACHEPAGE、LiteSpeedのno-cacheアクション、no-cacheヘッダーを使用します。RESTは成功・失敗ともprivate/no-storeとVary: Cookieを設定します。ただし、PHP実行前に応答するキャッシュをコードだけで停止できるとは扱いません。上記除外設定と未ログイン／別ユーザーでの確認は本番反映の必須条件です。ショートコードはページ本文へ直接置き、同期パターン等へ隠さないでください。マイページをキャッシュ対象外にする点は運用上の変更です。

## 操作・データ構造

検索は公式v2の `/laws`（law_titleまたはlaw_num、20件・offset）、履歴は `/law_revisions/{law_id}` を使用します。検索結果から法令を確定し、公式版IDごとの履歴を選択します。日付で統合しません。履歴は取得後20件ずつ画面で表示します。

共通レコードはschema_version、law（id/title/number）、official_revision_id、amendment（id/title/number）、enforcement（api_date/pseudo_date/comment/kind）、revision_status、internalを持ちます。擬似日付がある場合はAPI上の施行日にも値があっても確定日として扱いません。日付未定・未施行も一覧に残します。未知の状態は「状態不明」です。

「内部版を照合」を押したときだけ内部Revision APIを取得します。選択IDがその法令の公式履歴に存在することを再確認し、改正法令IDと日付情報を照合します。内部状態はnot_checked / matched / unmatched / ambiguous / unavailableです。matchedはメタデータ上の一意候補であり本文の同一性の証拠ではありません。候補0件と通信・形式エラーは区別します。無日付で照合できないケースもunavailableです。

**工程Bの生成実行時にはCloud Run側で改正版・対象法令・日付・内部版の対応を再検証する必要があります。工程Aの画面や候補IDをそのまま信用して生成しないでください。** 通常要約入口の対象範囲と将来のPremium制御の境界も公開前の別設計です。

## REST・通信制限

- GET `/egov-law-monitor/v1/ondemand/laws`：mode、query、offset
- GET `/egov-law-monitor/v1/ondemand/revisions`：law_id
- POST `/egov-law-monitor/v1/ondemand/revision-match`：law_id、revision_id

すべてログイン、manage_options、機能ON、X-WP-Nonce（wp_rest）を検証します。サービス入口でも権限・ONを再確認します。外部接続先はe-Govの固定パスのみ、リダイレクト0、15秒、応答2MiB上限、自動再試行なしです。超過・不正形式・HTTP失敗は0件扱いにしません。

専用transient `egov_ondemand_v1_*` で検索・履歴を300秒保存し、権限確認後だけ読みます。通常キャッシュは読み書きしません。キャッシュ中は変更反映に最大5分程度かかります。履歴全件が2MiBを超える法令は本工程では取得エラーとし、全件巡回や上限の自動緩和はしません。

## 検証と本番確認

```powershell
php tests/ondemand-discovery-test.php
node tests/ondemand-ui-test.js
```

HTTP・WordPress APIをテストダブルにしたオフラインテストです。生の取得例に基づく固定データを使い、実サービスには接続しません。実WordPressのSettings API／REST cookie認証、LiteSpeed・CDN除外、テーマ・スマートフォン・スクリーンリーダー表示、e-Govの最新応答・応答サイズは本番反映前の確認事項です。反映後は管理者が少数の検索・照合だけを明示操作し、通常の履歴・回数・キャッシュが変わらないことを確認します。異常時は機能OFFにし、自動再送しません。
