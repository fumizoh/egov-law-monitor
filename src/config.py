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