# Cloud Run deployment (PowerShell)

対象は `egov-law-monitor-summary`、リージョンは `asia-northeast1` です。
プロジェクトIDはデプロイ対象の `main:src/config.py` から取得します。
モデル名や過去のリビジョン名はスクリプトに固定しません。
Windows PowerShell 5.1 / PowerShell 7で実行できます。

## 1. モデル変更とローカル確認

1. 専用ブランチで `src/config.py` の `MODEL_NAME` を変更します。
2. プロンプト・JSONスキーマ・キャッシュ・利用回数は、変更が必要と確認できた場合だけ別途変更します。
3. 次を実行します。これらは実モデルを呼びません。

```powershell
Set-Location C:\GitHub\egov-law-monitor
.\.venv\Scripts\python.exe -B -X utf8 -m unittest discover -s tests -p '*_test.py'
php tests/ai-summary-month-test.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/cloud-run-deployment-test.ps1
git diff --check
```

SDK等の一部の依存パッケージと `python:3.12-slim` は未固定です。ソース差分がモデル名だけでも、再ビルドしたイメージの依存バージョンが旧環境と一致する保証はありません。
SDK更新は必要性を確認してから行い、デプロイした依存バージョンをCloud Build等で確認します。

## 2. 承認後の実通信テスト

実行前に管理者の承認を得ます。既存Google Cloudプロジェクトを利用し、ローカルからVertex AIのglobalエンドポイントへ直接、短い架空の固定入力を1回だけ送ります。
`main.py` / WordPress / 本番Cloud Run / キャッシュを経由しません。

ローカルSDK用認証は既存ADCを使用します。未設定の場合の操作は次です（ローカル認証設定が変わります）。

```powershell
gcloud auth application-default login
gcloud auth application-default set-quota-project <既存プロジェクトID>
```

単発テストは `genai.Client(vertexai=True, project=..., location="global")` と `api_version="v1"` を使います。
`HttpRetryOptions(attempts=1)` を明示し、既存 `gemini_client.summarize()` の429再試行ループを使わず、SDKの `generate_content()` を1回だけ呼びます。
`SummarySchema` と `response_mime_type="application/json"` を維持し、次を確認します。

- 生JSONとSDKの `parsed` が `title` / `body` のスキーマを満たすこと。
- 実際の `model_version`、生成呼び出し前後の `perf_counter()` 差分。
- `usage_metadata` の入力・出力・思考・合計トークン数。
- 認証情報、例外全文、HTTPヘッダーを出力しないこと。

失敗・タイムアウト時に自動再送しません。結果不明の場合、サーバーで生成・課金された可能性を考慮します。同じプロジェクトの課金・クォータ・Google側のログには影響します。

## 3. GitHubへ反映・mainを準備

レビュー後、ユーザーが承認した変更だけをコミット・pushします。PRはユーザーがGitHub画面で作成・マージします。
ユーザーの指示に従ってローカルmainを更新します。デプロイ直前には次を確認します。

```powershell
git fetch origin main
git rev-parse main origin/main
git status --short --branch
```

mainとorigin/mainが一致し、未追跡を含む作業ツリーがクリーンであることが必要です。
スクリプト自身はfetch・pull・ブランチ変更をしません。一致は取得済み情報との比較なので、fetchを省略して最新と判断しないでください。
Git権限エラーがあれば権限変更やロック強制削除はせず、ユーザー側PowerShellでGit操作を完了します。
作業ブランチからの実行も可能ですが、デプロイするコードは常に確認済みmainのコミットです。
このスクリプト自体の変更が未コミットの間は、通常のデプロイ実行は拒否されます。

## 4. 前提条件と既存設定

- Google Cloud CLI (`gcloud`) とGitをインストールし、デプロイ権限を持つユーザーで `gcloud auth login` 済みであること。CLIログインとSDK用ADCは別です。
- Cloud Run / Cloud Build / Artifact Registry等、ソースデプロイに必要なAPI・ビルド環境・権限が準備済みであること。
- 実行サービスアカウントとビルドサービスアカウントは別物です。どちらもスクリプトから変更しません。
- API有効化・権限付与を求めるプロンプトが出た場合は中断し、別途確認します。スクリプトはそれらを自動修復しません。
- 他の管理者による同時デプロイ・設定変更・トラフィック変更は避けます。Cloud Run側を横断ロックする仕組みはありません。

既存サービスが見つからない場合は停止し、新規サービスを作成しません。
最新作成リビジョンと最新Readyリビジョンが一致しない場合、または最新テンプレートのReadyリビジョンが配信対象でない場合も停止します。
これは未配信の設定を意図せず継承しないためです。原因を確認し、独断で別のリビジョンへ戻さないでください。

## 5. トラフィックを移さずデプロイ

```powershell
.\scripts\deploy-cloud-run.ps1
```

必要なら記録先だけを変更できます（リポジトリ外を指定）。

```powershell
.\scripts\deploy-cloud-run.ps1 -RecordRoot C:\DeploymentRecords\egov
```

スクリプトは次を行います。

1. Git状態とmain/origin/mainの一致を確認し、そのコミットSHAを固定。
2. 既存サービス・旧リビジョン・IAM・配信割合を読み取り。
3. `Dockerfile`、`requirements.txt`、`main.py`、`src/`だけを `git archive` で一時ディレクトリへ展開。
4. `.env`、秘密鍵、典型的なサービスアカウントJSON等を検出した場合は送信前に停止。
5. 既存設定のカテゴリ別SHA-256と旧リビジョン・配信割合をリポジトリ外に記録。
6. `gcloud run deploy --source=... --no-traffic --tag=... --revision-suffix=...` を実行。
7. 新リビジョンの設定と既存配信割合を検証。
8. 成否にかかわらず、絶対パスを検証して一時アーカイブ・ソースを削除。

記録先の既定値は `%LOCALAPPDATA%\egov-law-monitor\deployments\<UUID>\deployment.json` です。
記録は設定復元用のバックアップではなく、差分検出用のハッシュです。生の環境変数値、Secret値、トークンは保存しません。管理者が記録の改変を防ぎ、実行結果と一緒に保管してください。
ファイル名・内容の検査は一般的な認証情報の混入防止であり、任意形式で埋め込まれた秘密情報すべてを検出する保証はありません。mainのコードレビューも必要です。

環境変数・Secret・IAM・実行サービスアカウント・CPU等を変更するフラグは使用しません。
差分や配信変更を検出した場合も、自動修正・自動ロールバックはしません。
CLIの生エラーは機密情報を含む可能性があるため抑制し、操作名・終了コードを表示します。詳細は管理者がCloud Build / Cloud Runコンソールで確認します。
失敗メッセージだけで新リビジョンが存在しないとは判断しないでください。通信断・結果不明時は記録された新リビジョン名を確認してから再実行を判断します。

## 6. 検証

デプロイ出力に表示された記録ディレクトリを指定します。

```powershell
.\scripts\check-cloud-run.ps1 -RecordDirectory '<記録ディレクトリ>'
```

新旧リビジョンとデプロイ前の記録を比較します。

- コンテナイメージの差分は許容。
- 環境変数・Secret参照、サービスアカウント、コンテナ実行条件・リソース・ボリューム、Ingress等の設定、IAMの差分は拒否。
- 自動生成されるデプロイ管理用annotation、IAM ETag等は比較から除外。
- 記録した配信割合が維持されていることを確認し、リビジョン名・割合を表示。
- タグが記録の新リビジョンを指すことを確認し、そのHTTPS URLの `GET /` だけを実行。

ヘルスチェックには `gcloud auth print-identity-token` をメモリ内で受け取り使用します。ログへ表示せず、リダイレクト先へ認証情報を転送しません。
実行ユーザーにはCloud Run呼び出し権限が必要です。Ingress等で到達できない場合も、公開設定へ変更せず停止します。
生の応答やエラー内容は出力しません。

`GET /`成功は起動確認です。実モデルの生成成功を意味しません。
必要なら別途承認後、タグ付きURLの `POST /summary` に実在する法令識別情報を1件送ります。WordPressを経由しなければWordPressキャッシュ・利用回数は更新されませんが、e-Gov・Vertex AIへの通信と課金は発生します。
現在の `/summary` 応答にはモデル名・トークン数が含まれません。キャッシュ応答だけでモデル変更成功とは判断しないでください。

## 7. 本番切替（管理者の手動操作のみ）

記録ファイルからproject、region、service、新リビジョン名を確認し、値を置き換えます。

```powershell
gcloud run services update-traffic <service> `
  --project=<project> --region=<region> `
  --to-revisions='<新リビジョン名>=100'
```

新旧リビジョンは削除せず保持します。切替後にサービスURL・エラー率・レイテンシを確認します。
切替後は事前配信割合と異なるため、`check-cloud-run.ps1` はその不一致を検出して停止します。これは想定どおりであり、記録を書き換えて回避しないでください。

## 8. ロールバック（管理者の手動操作のみ）

旧配信先が1つ・100%だった場合は、記録の旧配信リビジョンへ戻します。

```powershell
gcloud run services update-traffic <service> `
  --project=<project> --region=<region> `
  --to-revisions='<旧配信リビジョン名>=100'
```

元が分割配信なら、記録されたすべてのリビジョンと割合を指定します。

```powershell
gcloud run services update-traffic <service> `
  --project=<project> --region=<region> `
  --to-revisions='<旧リビジョンA>=80,<旧リビジョンB>=20'
```

配信を戻しても、処理中のリクエスト・課金・サービス全体に別途行われた設定変更は取り消されません。
タグ付きURLは通常配信割合と独立して新リビジョンを呼び出せるため、不要になったタグの削除も管理者が判断します。
スクリプトは切替・ロールバック・タグ削除・リビジョン削除を自動実行しません。

## 未検証事項

オフラインテストでは実際のGit/gcloud/HTTPを模擬します。実Cloud Build、依存解決、IAM、Secret参照、Cloud Runの自動付与設定、タグURLへの認証・到達性は検証できません。
初回の実デプロイは必ずトラフィック0%で実施し、設定比較が失敗した場合は値を管理画面で確認してください。比較除外を安易に増やして通過させないでください。
