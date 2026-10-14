# e-Gov Law Monitor

e-Gov Law Monitorは、e-Gov法令検索の法令更新情報を自動取得・整理し、法令の更新状況を確認できるサービスです。AIによる法令改正の要約や、関心のある法令の更新通知にも対応しています。

## サービス

- **本番サイト**：[e-Gov Law Monitor](https://egovlm.oogushioffice.com/)
- **公開ダッシュボード**：[GitHub Pages](https://fumizoh.github.io/egov-law-monitor/)
- **ライセンス**：MIT License

## 主な機能

### 法令更新情報

- e-Gov法令検索の更新情報を自動取得
- 法令の更新情報を整理して公開
- 日ごとの法令更新件数などの統計情報を提供
- GitHub Pagesによる公開ダッシュボード

### AIによる法令要約

- 法令改正の内容をAIで要約
- 新規制定法令の要約
- 要約結果の履歴保存と再表示
- 条文比較など、原文を確認するための情報への導線

AI要約は、利用者が対象の法令・改正を選択して実行する仕組みです。要約の生成にはCloud Run上のAPIとGemini APIを利用します。

### 法令ウォッチ

- 関心のある法令を登録して更新を監視
- 登録した法令の更新をメールで通知
- キーワードを登録して関連する法令を監視
- 登録したキーワードの有効・停止状態を管理

利用できる機能や登録数は、会員区分などの利用条件により異なります。

### メール通知

法令更新情報をメールで通知します。通知には更新日、更新件数、更新された法令などの情報を含みます。

## 利用方法

サービスの利用方法や各機能の操作手順については、本番サイトの「使い方」ページをご覧ください。

[本番サイト](https://egovlm.oogushioffice.com/)

## システム構成

本プロジェクトは、法令更新情報の取得・加工を行うPythonアプリケーションと、利用者向け機能を提供するWordPressを中心に構成されています。

```text
e-Gov法令検索
    │
    ▼
Pythonアプリケーション
    │
    ├── 法令更新情報の取得・解析
    ├── 法令データの構造化
    ├── 更新統計の生成
    ├── 公開JSONデータの生成
    ├── WordPressへの更新記事投稿
    └── 法令更新通知メール
            │
            ▼
       公開データ・WordPress
            │
            ├── GitHub Pages
            ├── 法令更新情報の表示
            ├── 法令ウォッチ
            └── AI要約機能
                    │
                    ▼
                 Cloud Run
                    │
                    ▼
                 Gemini API
```

AI要約機能は、日次の法令更新処理とは分離されており、WordPressからの要求に応じて実行されます。

## 自動処理

GitHub Actionsにより、法令更新情報の取得とデータ更新などを定期的に実行します。

主な処理は次のとおりです。

1. e-Gov法令更新情報の取得
2. ZIPアーカイブの展開とCSVデータの解析
3. 法令更新イベントの構築
4. 法令情報と公開用データの生成
5. 更新統計の生成
6. GitHub Pages向けデータの更新
7. WordPressへの法令更新記事の投稿
8. 法令更新通知メールの送信

AI要約は別のAPI処理として実行されます。

## 公開データ

GitHub Pagesでは、構造化されたJSONデータを公開しています。

| ファイル | 説明 |
|---|---|
| `app.json` | アプリケーションのメタデータ |
| `statistics.json` | ダッシュボード用の統計データ |
| `laws.json` | 法令情報と更新履歴をまとめた公開データ |

公開データは、GitHub PagesやWordPressなどで利用します。

## ディレクトリ構成

```text
.
├── .github/
│   └── workflows/       # GitHub Actions
├── docs/                # GitHub Pages・公開データ
├── src/                 # Pythonアプリケーション
├── wordpress-plugin/    # WordPress連携プラグイン
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

## 使用技術

- Python 3.13
- WordPress / PHP
- GitHub Actions
- GitHub Pages
- HTML / CSS / JavaScript
- Cloud Run
- Gemini API
- SMTP

## 設計方針

法令情報の取得・加工と、利用者向け機能を分離した構成を採用しています。

Python側で法令情報を構造化し、WordPress側で法令情報の表示や会員向け機能を提供します。AI要約についても、入力データの構築と生成処理を分離することで、データ処理の再利用性と保守性を確保しています。

## License

MIT License
