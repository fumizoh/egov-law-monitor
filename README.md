# eGov Law Monitor

e-Gov法令検索で公開される法令更新情報を毎日自動取得し、GitHub Pagesで公開するツールです。

日々更新される法令情報を、すばやく確認することを目的として開発しました。

GitHub Actionsにより毎日自動実行され、最新の法令更新情報を確認できます。

---

## 主な機能

- e-Gov法令更新情報の自動取得
- GitHub Actionsによる毎日の自動実行
- 更新法令一覧の生成
- 更新件数・更新日の表示
- 法令種別ごとの件数集計
- e-Gov本文へのリンク
- GitHub Pagesへの自動公開

---

## 公開ページ

GitHub Pages

https://fumizoh.github.io/egov-law-monitor/

---

## 画面イメージ

トップページでは更新件数や法令種別ごとの集計を確認できます。
詳細ページでは更新された法令一覧とe-Govへのリンクを表示します。

### ダッシュボード

![ダッシュボード](docs/images/dashboard.png)

### 更新法令一覧

![更新法令一覧](docs/images/updates.png)

---

## ディレクトリ構成

```text
.
├── .github/
│   └── workflows/
│       └── check.yml          GitHub Actions
│
├── docs/                      GitHub Pages
│   ├── css/
│   │   └── style.css
│   ├── data/
│   │   ├── app_info.json
│   │   ├── statistics.json
│   │   └── updates.json
│   ├── js/
│   │   ├── script.js
│   │   └── updates.js
│   ├── index.html
│   └── updates.html
│
├── src/
│   ├── config.py
│   ├── egov_bulk.py
│   ├── monitor.py
│   ├── storage.py
│   ├── summary.py
│   └── update_parser.py
│
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

---

## 動作イメージ

```text
e-Gov
   │
   ▼
GitHub Actions（毎日実行）
   │
   ▼
Python
   │
   ▼
updates.json / statistics.json
   │
   ▼
GitHub Pages
   │
   ▼
法令更新ダッシュボード
```

---

## データ構成

### updates.json

更新された法令一覧を保存します。
GitHub Pagesでは更新法令一覧画面の表示に使用します。

### statistics.json

更新日・更新件数・法令種別ごとの件数を保存します。

### app_info.json

アプリケーションのバージョンなどの情報を保存します。

---

## 自動実行

GitHub Actionsにより毎日自動実行されます。

処理内容

1. e-Gov更新情報取得
2. ZIPダウンロード
3. CSV展開
4. JSON生成
5. GitHubへ自動Commit
6. GitHub Pages更新

---

## 使用技術

- Python 3.13
- GitHub Actions
- GitHub Pages
- HTML
- CSS
- JavaScript

---

## 今後の予定

- 法令名検索
- 法令種別フィルター
- スマートフォン表示の改善
- 日付表示の改善

---

## ライセンス

MIT License