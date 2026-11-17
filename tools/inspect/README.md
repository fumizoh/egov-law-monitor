# 手動診断スクリプト

リポジトリのルートから実行します。依存パッケージと、通信先に必要な既存設定を準備してください。importだけでは通信しません。実行時の通信は下表のとおりです。WordPress投稿、Gemini、Cloud Run、SMTP送信は行いません。

以下のID・日付は例です。調査する公開法令の値へ置き換えてください。

| ファイル | 用途・実行引数例 | 外部通信 | 書込み |
| --- | --- | --- | --- |
| `test_law_api.py` | 法令名取得。`--law-id 129AC0000000089` | e-Gov | なし |
| `test_revision_effective_date.py` | 施行日別の改正履歴。`--law-id 129AC0000000089 --date 2026-04-01`（日付は複数指定可） | e-Gov | なし |
| `test_egov_api.py` | 改正ID・法令本文・条項取得。`--law-id 129AC0000000089 --effective-date 2026-04-01`。必要に応じ`--amendment-id`、`--article`、`--paragraph`、`--item` | e-Gov | なし |
| `test_summary_pipeline.py` | Compare取得から差分抽出までの段階別診断。`--law-data-id 637185 --sub-revision 1`。`--details`で構造と最大5件の生ブロックを表示 | e-Gov（Compare・目次） | なし |
| `test_pipeline_no_ai.py` | AIなしの取得・集計・JSON生成。`--date 20260401`（省略時は最新） | e-Gov | 一時領域のみ |
| `inspect_wp_excerpt.py` | 保存済み通常データから記事抜粋を表示。引数なし | なし | なし |
| `inspect_watch_email.py` | 指定キーワードでメール本文をプレビュー。`--keyword 民法 --storage normal --date 20260401` | なし | 通常なし。明示した新規HTMLのみ |
| `test_watch_users.py` | ウォッチ利用者の設定・件数を診断。必要時`--user-id 123` | WordPress内部APIの読取り | なし |
| `inspect_watch_notifications.py` | 保存済みデータでキーワード一致候補と実際の通知対象を診断。必要時`--user-id 123` | WordPress内部APIの読取り | なし |

実行例：

```powershell
.venv\Scripts\python.exe -B tools/inspect/test_watch_users.py
.venv\Scripts\python.exe -B tools/inspect/inspect_watch_email.py --keyword 民法
.venv\Scripts\python.exe -B tools/inspect/test_pipeline_no_ai.py --date 20260401
```

## 出力と個人情報

WordPress利用者診断はメールアドレス、ユーザーID、登録キーワードを表示せず、表示順・プラン・通知設定・件数を表示します。`--user-id`は取得後の表示対象フィルターです。APIからは従来どおり全対象ユーザーの情報を取得します。

通知OFFの一致は`keyword-match-only`であり、通知対象件数は0です。これらのスクリプトはメールを送信しません。例外の詳細には認証情報が含まれる可能性があるため、今回修正した7本は例外の型だけを表示します。

Compareの詳細表示には公開法令の生データが含まれます。既存のCompare診断・抜粋診断の出力を共有する際も、内容を確認してください。

## ファイル出力

メールプレビューは通常、標準出力のみです。`--keyword`は複数指定可、`--storage`は`normal`または`reprocess`です。日付省略時は選択した保存領域の最終更新日を使います。HTML保存には`--output`で**リポジトリ外・既存の親ディレクトリ内の新規ファイル**を指定します。既存ファイルの上書きは拒否します。プレビュー内容の保管・共有には注意してください。

AIなしパイプラインはZIP、展開データ、生成JSONのすべてを専用の一時ディレクトリへ保存し、正常終了・例外終了時に削除します。通常・再処理用の保存データは書き換えません。診断中だけモジュールの保存先を切り替えるため、独立したコマンドとして実行し、アプリ内で並行呼出ししないでください。強制終了時は一時ファイルが残る場合があります。

各修正済みスクリプトは成功時0、処理失敗時1、引数不正時2を返します。Compare診断はデータなし3、ブロックなし4も区別します。

## オフライン検証

```powershell
.venv\Scripts\python.exe -B tests/inspection_safety_test.py
```

通信を禁止し、API応答は固定データに置き換えます。実際の本番APIへの接続性は確認しません。スクリプトとこのREADMEだけをGit管理の許可対象にし、認証情報、個人情報、実行結果、保存データは追加しないでください。
