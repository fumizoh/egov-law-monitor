"""
プロジェクト共通設定
"""

from pathlib import Path

# Sources
NOTIFY_SOURCES = {
    "egov",
}


# Directories
DOWNLOAD_DIR = Path("data/downloads")
EXTRACT_DIR = Path("data/extracted")

DOCS_DATA = Path("docs/data")

LOGS_DATA = Path("data/logs")


# JSON
LAWS_JSON = DOCS_DATA / "laws.json"
STATISTICS_JSON = DOCS_DATA / "statistics.json"
AI_STATISTICS_JSON = DOCS_DATA / "ai_statistics.json"
APP_JSON = DOCS_DATA / "app.json"
KEYWORDS_JSON = DOCS_DATA / "keywords.json"

AI_SUMMARY_LOG_JSON = LOGS_DATA / "ai_summary_log.jsonl"

SOURCE_DATA_FILES = {
    "egov": DOCS_DATA / "egov_updates.json",
    "public_comment": DOCS_DATA / "public_comments.json",
}


# Change types
CHANGE_ADDED = "added"
CHANGE_REMOVED = "removed"
CHANGE_MODIFIED = "modified"
CHANGE_SAME = "same"


# Gemini
PROJECT_ID = "project-9dc19b38-12b0-40dd-871"
LOCATION = "us-central1"

MODEL_NAME = "gemini-2.5-flash"

GEMINI_INPUT_PRICE_USD_PER_MILLION = 0.30
GEMINI_OUTPUT_PRICE_USD_PER_MILLION = 2.50

USD_TO_JPY_RATE = 150.0


# Pagination
PAGE_SIZE = 100