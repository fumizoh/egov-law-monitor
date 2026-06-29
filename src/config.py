"""
プロジェクト共通設定
"""

# API
BASE_URL = "https://laws.e-gov.go.jp/api/2/laws"

# ディレクトリ
DATA_DIR = "data"
OUTPUT_DIR = "output"

# API設定
TIMEOUT = 30

# GitHub Actionsとの互換性を考え、取得件数は定数化
PAGE_SIZE = 100


# 更新法令ダウンロード

BULK_DOWNLOAD_URL = "https://laws.e-gov.go.jp/bulkdownload"

UPDATE_DATE = "20240601"

ONLY_XML = True


# 一括ダウンロード
BULK_URL = "https://laws.e-gov.go.jp/bulkdownload"

# 一覧ページ
BULK_PAGE_URL = "https://laws.e-gov.go.jp/bulkdownload/"

# ダウンロードURL
BULK_DOWNLOAD_URL = "https://laws.e-gov.go.jp/bulkdownload"

# データ保存先
from pathlib import Path
DOWNLOAD_DIR = Path("data/downloads")

EXTRACT_DIR = Path("data/extracted")

DOCS_DIR = Path("docs")
JSON_PATH = DOCS_DIR / "data" / "updates.json"