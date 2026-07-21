"""
プロジェクト共通設定
"""

# e-Gov API
BASE_URL = "https://laws.e-gov.go.jp/api/2/laws"

# API設定
TIMEOUT = 30

# GitHub Actionsとの互換性を考え、取得件数は定数化
PAGE_SIZE = 100


# 更新法令ダウンロード
UPDATE_DATE = "20240601"
ONLY_XML = True


# 一括ダウンロード
BULK_URL = "https://laws.e-gov.go.jp/bulkdownload"
BULK_PAGE_URL = "https://laws.e-gov.go.jp/bulkdownload/"
BULK_DOWNLOAD_URL = "https://laws.e-gov.go.jp/bulkdownload"


# ディレクトリ
DATA_DIR = "data"
OUTPUT_DIR = "output"

from pathlib import Path

DOWNLOAD_DIR = Path("data/downloads")

EXTRACT_DIR = Path("data/extracted")

DOCS_DIR = Path("docs")

DOCS_DATA = Path("docs/data")
KEYWORDS_JSON = DOCS_DATA / "keywords.json"
LAWS_JSON = DOCS_DATA / "laws.json"

NOTIFY_SOURCES = {
    "egov",
}

# Change types
CHANGE_ADDED = "added"
CHANGE_REMOVED = "removed"
CHANGE_MODIFIED = "modified"
CHANGE_SAME = "same"