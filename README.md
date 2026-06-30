# eGov Law Monitor

e-Gov法令検索で公開される法令更新情報を自動取得し、更新された法令を一覧化するPythonツールです。

GitHub Actionsを利用した定期実行と、GitHub Pagesによる更新情報の公開を目的として開発しています。

---

## About

eGov Law Monitor は、e-Gov法令検索で公開される更新情報を効率的に確認するためのツールです。

更新XMLの取得から法令情報の解析、JSONデータの生成までを自動化し、日々の法令改正確認を支援します。

現在は行政書士業務での利用を想定して開発していますが、法務担当者や法令改正を継続的に確認する必要のある方にも活用できることを目指しています。

---

## Features

現在実装済みの機能

- 更新日の取得
- 更新XMLファイルのダウンロード
- ZIPファイルの展開
- XML解析
- 更新法令一覧（JSON）の生成
- GitHub Actionsによる自動実行

開発中

- GitHub Pagesによる公開
- バージョン情報配信
- 更新履歴表示

---

## Project Structure

```text
egov-law-monitor/
│
├── .github/
│   └── workflows/
│
├── docs/
│   ├── css/
│   ├── js/
│   ├── assets/
│   └── data/
│
├── src/
│
├── data/
│
├── README.md
├── CHANGELOG.md
└── requirements.txt
```

---

## Requirements

- Python 3.11 以上

インストール

```bash
pip install -r requirements.txt
```

---

## Usage

ローカルで実行

```bash
python src/main.py
```

または GitHub Actions により自動実行できます。

---

## Roadmap

### v0.1.0

- 基本機能
- GitHub Actions
- JSON生成

### v0.2.0

- GitHub Pages
- 公式サイト
- バージョン情報API

### v0.3.0

- 自動更新確認

### v0.4.0

- GUI対応

### v1.0.0

- 正式リリース

---

## License

MIT License