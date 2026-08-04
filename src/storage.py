import os
import json
import zipfile

from dataclasses import asdict, is_dataclass
from pathlib import Path

from summary.revision import SummaryRevisionKey

from utils.dataclass import from_dict

from models import (
    Law,
    AiStatistics,
    DailySummaryResponse,
    LawSummary,
)

from config import (
    EXTRACT_DIR,
    DOCS_DATA,
    SOURCE_DATA_FILES,
    LAWS_JSON,
    LAW_SUMMARIES_JSON,
    DAILY_SUMMARY_JSON,
    STATISTICS_JSON,
    AI_STATISTICS_JSON,
    APP_JSON,
)


def extract_zip(zip_path: Path) -> Path:
    """
    ZIPファイルを展開する。
    戻り値は展開先フォルダ。
    """

    output_dir = EXTRACT_DIR / zip_path.stem

    output_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    with zipfile.ZipFile(zip_path, "r") as zip_file:
        zip_file.extractall(output_dir)

    return output_dir


def find_update_csv(extract_dir: Path) -> Path:
    """
    展開フォルダから更新一覧CSVを探す。
    """

    csv_files = list(extract_dir.glob("*.csv"))

    if not csv_files:
        raise FileNotFoundError("更新一覧CSVが見つかりません。")

    return csv_files[0]


def json_default(obj):
    """Convert unsupported objects to JSON-serializable values."""

    if is_dataclass(obj):
        return asdict(obj)

    raise TypeError(
        f"Object of type {type(obj).__name__} is not JSON serializable."
    )


def load_json(input_path: Path):
    """
    Loas JSON.
    """

    with open(input_path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_json(
    data,
    output_path: Path,
):
    """
    Save as JSON atomically.
    """

    tmp_path = output_path.with_suffix(
        output_path.suffix + ".tmp"
    )

    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(
            data,
            f,
            ensure_ascii=False,
            indent=2,
            default=json_default,
        )

    os.replace(
        tmp_path,
        output_path,
    )


def save_source_data(source, data):
    """
    Save source data as JSON.
    """

    save_json(
        data,
        SOURCE_DATA_FILES[source],
    )


def save_updates(source, updates):

    save_source_data(
        source,
        updates,
    )


def load_laws() -> dict[str, Law]:
    """
    Load previous Law view.
    """

    if not LAWS_JSON.exists():
        return {}

    try:
        laws = load_json(
            LAWS_JSON,
        )

    except json.JSONDecodeError:
        logger.exception(
            "Failed to load %s",
            LAWS_JSON,
        )
        return {}

    for law in laws:
        law["summary_revision_keys"] = [
            SummaryRevisionKey(**key)
            for key in law["summary_revision_keys"]
        ]

    return {
        law["law_id"]: law
        for law in laws
    }


def save_laws(laws):
    """
    Save Law view as laws.json.
    """

    save_json(
        laws,
        LAWS_JSON,
    )


def load_law_summaries() -> dict[str, LawSummary]:
    """Load cached law summaries."""

    if not LAW_SUMMARIES_JSON.exists():
        return {}

    try:
        data = load_json(
            LAW_SUMMARIES_JSON,
        )

    except json.JSONDecodeError:
        logger.exception(
            "Failed to load %s",
            LAW_SUMMARIES_JSON,
        )
        return {}

    summaries = [
        from_dict(LawSummary, item)
        for item in data
    ]

    return {
        summary.summary_input.law_id: summary
        for summary in summaries
    }


def save_law_summaries(
    summaries: list[LawSummary],
) -> None:

    save_json(
        summaries,
        LAW_SUMMARIES_JSON,
    )


def save_daily_summary(
    summary: DailySummaryResponse,
):
    """Save Daily Summary."""

    save_json(
        summary,
        DAILY_SUMMARY_JSON,
    )


def save_statistics(
    source,
    statistics,
):
    """
    情報源ごとの統計を statistics.json に保存する。
    """

    try:

        data = load_json(
            STATISTICS_JSON
        )

    except FileNotFoundError:

        data = {}

    data[source] = statistics

    save_json(
        data,
        STATISTICS_JSON,
    )


def save_ai_statistics(statistics: AiStatistics):
    """
    Save AI statistics as ai_statistics.json.
    """

    save_json(
        statistics,
        AI_STATISTICS_JSON,
    )
