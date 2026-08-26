"""
プロジェクト共通設定
"""

from pathlib import Path


# Directories
DOWNLOAD_DIR = Path("data/downloads")
EXTRACT_DIR = Path("data/extracted")

DOCS_DATA = Path("docs/data")

LOGS_DATA = Path("data/logs")


# JSON
LAWS_JSON = DOCS_DATA / "laws.json"
LAW_SUMMARIES_JSON = DOCS_DATA / "law_summaries.json"

STATISTICS_JSON = DOCS_DATA / "statistics.json"
AI_STATISTICS_JSON = DOCS_DATA / "ai_statistics.json"

APP_JSON = DOCS_DATA / "app.json"
KEYWORDS_JSON = DOCS_DATA / "keywords.json"

AI_SUMMARY_LOG_JSONL = DOCS_DATA / "ai_summary_log.jsonl"

WATCH_JSON = DOCS_DATA / "watch.json"


# Sorting order
LAW_TYPE_ORDER = {
    "法律": 0,
    "政令": 1,
    "勅令": 2,
    "府省令": 3,
    "規則": 4,
}


# Change types
CHANGE_ADDED = "added"
CHANGE_REMOVED = "removed"
CHANGE_MODIFIED = "modified"
CHANGE_SAME = "same"


# Gemini
PROJECT_ID = "project-9dc19b38-12b0-40dd-871"

MODEL_NAME = "gemini-3.5-flash"
LOCATION = "global"

GEMINI_INPUT_PRICE_USD_PER_MILLION = 0.30
GEMINI_OUTPUT_PRICE_USD_PER_MILLION = 2.50

USD_TO_JPY_RATE = 150.0


# Pagination
PAGE_SIZE = 100