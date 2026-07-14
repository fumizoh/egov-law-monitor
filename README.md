# eGov Law Monitor

eGov Law Monitor は、e-Gov の法令更新情報を継続的に監視し、
GitHub Pages で見やすく公開するオープンソースのツールです。

現在対応している情報源：

- e-Gov 法令更新情報
- e-Gov パブリックコメント

法令更新情報を中心に、関連する行政情報もあわせて確認できるよう設計されています。

また、将来的には次のような公式情報源への対応を予定しています。

- e-Gov データポータル
- 内閣法制局「最近成立した法律」 など

---

## 主な機能

- e-Gov 法令更新情報の自動取得
- e-Gov パブリックコメントの取得
- GitHub Pages による更新情報の公開
- メール通知
- 情報源ごとの統計情報生成
- 共通データモデルによるマルチソース対応

---

## GitHub Pages

GitHub Pages では次のページを公開しています。

- ダッシュボード
- 法令更新一覧
- パブリックコメント一覧

<https://fumizoh.github.io/egov-law-monitor/>

---

## 画面イメージ

バージョンが進んだところで更新します。

---

## ディレクトリ構成

```text
.
├── .github/
│   └── workflows/
│       └── check.yml
│
├── docs/
│   ├── css/
│   ├── data/
│   │   ├── app.json
│   │   ├── egov_updates.json
│   │   ├── keywords.json
│   │   ├── public_comments.json
│   │   └── statistics.json
│   │
│   ├── js/
│   │   ├── dashboard.js
│   │   ├── law-updates.js
│   │   ├── public-comments.js
│   │   └── utils.js
│   │
│   ├── index.html
│   ├── law-updates.html
│   └── public-comments.html
│
├── src/
│   ├── sources/
│   │   ├── egov.py
│   │   └── public_comment.py
│   │
│   ├── config.py
│   ├── email_generator.py
│   ├── egov_bulk.py
│   ├── mailer.py
│   ├── monitor.py
│   ├── pipeline.py
│   ├── statistics.py
│   ├── storage.py
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
   ├── egov_updates.json
   ├── public_comments.json
   ├── statistics.json
   └── メール通知
   │
   ▼
GitHub Pages
   │
   ▼
ダッシュボード
   │
   ├── 法令更新一覧
   └── パブリックコメント一覧
```

---

## データ構成

### egov_updates.json

e-Gov法令更新情報を保存します。

### public_comments.json

e-Govパブリックコメントを保存します。

### statistics.json

情報源ごとの統計情報を保存します。

### keywords.json

監視対象キーワードを保存します。

### app.json

アプリケーション情報（バージョンなど）を保存します。

---

## 自動実行

GitHub Actionsにより毎日自動実行されます。

処理内容

1. e-Gov法令更新データ取得
2. ZIPダウンロード
3. CSV展開・解析
4. パブリックコメント取得
5. JSON生成
6. GitHub Pages更新
7. メール通知

---

## メール通知

更新があった日のみ、更新通知メールを送信します。
（※現在は法令更新情報のみ通知します。）

メールには

- 更新日
- 更新件数
- 更新法令一覧
- キーワード強調表示
- GitHub Pagesへのリンク

を掲載し、更新履歴として保存できます。

---

## 使用技術

- Python 3.13
- GitHub Actions
- GitHub Pages
- HTML
- CSS
- JavaScript
- SMTP（メール通知）

---

## 今後の予定

- e-Gov データポータルへの対応
- 内閣法制局情報への対応
- 法令更新情報の詳細表示の充実
- Python側での同一法令グループ化
- 改正法令名・改正法令公布日の表示

---

## ライセンス

MIT License