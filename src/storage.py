""" storage.py """

import os
import json
import zipfile
import csv

from dataclasses import (
    asdict,
    dataclass,
    is_dataclass,
)

from pathlib import Path

from utils.dataclass import from_dict

from models import (
    Law,
    AiStatistics,
    LawSummary,
    AiSummaryLog,
)

from config import (
    EXTRACT_DIR,
    DOCS_DATA,
    LAWS_JSON,
    LAW_SUMMARIES_JSON,
    STATISTICS_JSON,
    AI_STATISTICS_JSON,
    AI_SUMMARY_LOG_JSONL,
    APP_JSON,
)

@dataclass(frozen=True)
class StoragePaths:
    """Paths for post generation data."""

    laws: Path
    law_summaries: Path
    statistics: Path

DEFAULT_STORAGE = StoragePaths(
    laws=LAWS_JSON,
    law_summaries=LAW_SUMMARIES_JSON,
    statistics=STATISTICS_JSON,
)

REPROCESS_DIR = DOCS_DATA / "reprocess"

REPROCESS_STORAGE = StoragePaths(
    laws=REPROCESS_DIR / "laws.json",
    law_summaries=REPROCESS_DIR / "law_summaries.json",
    statistics=REPROCESS_DIR / "statistics.json",
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


def load_events(csv_path: Path) -> list[dict]:
    """
    更新法令CSVを読み込む。
    """

    events = []

    with open(csv_path, encoding="utf-8-sig") as f:

        reader = csv.DictReader(f)

        for row in reader:
            events.append(row)

    return events


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

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

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


def load_laws(
    paths: StoragePaths = DEFAULT_STORAGE,
) -> dict[str, Law]:
    """
    Load previous Law view.
    """

    if not paths.laws.exists():
        return {}

    try:
        laws = load_json(
            paths.laws,
        )

    except json.JSONDecodeError:
        logger.exception(
            "Failed to load %s",
            paths.laws,
        )
        return {}

    return {
        law["law_id"]: law
        for law in laws
    }


def save_laws(
    laws,
    paths: StoragePaths = DEFAULT_STORAGE,
):
    """Save Law view as laws.json."""

    save_json(
        laws,
        paths.laws,
    )


def load_statistics(
    paths: StoragePaths = DEFAULT_STORAGE,
) -> dict:
    """
    Load statistics from statistics.json.
    """

    if not paths.statistics.exists():
        return {}

    try:
        return load_json(paths.statistics)
    except json.JSONDecodeError:
        return {}


def save_statistics(
    source,
    statistics,
    paths: StoragePaths = DEFAULT_STORAGE,
):
    """Save statistics."""

    try:
        data = load_json(paths.statistics)
    except FileNotFoundError:
        data = {}

    data[source] = statistics

    save_json(
        data,
        paths.statistics,
    )


def load_law_summaries(
    paths: StoragePaths = DEFAULT_STORAGE,
) -> dict[str, LawSummary]:
    """Load cached law summaries."""

    if not paths.law_summaries.exists():
        return {}

    try:
        data = load_json(
            paths.law_summaries,
        )

    except json.JSONDecodeError:
        logger.exception(
            "Failed to load %s",
            paths.law_summaries,
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
    paths: StoragePaths = DEFAULT_STORAGE,
) -> None:

    save_json(
        summaries,
        paths.law_summaries,
    )


def save_ai_statistics(statistics: AiStatistics):
    """
    Save AI statistics as ai_statistics.json.
    """

    save_json(
        statistics,
        AI_STATISTICS_JSON,
    )


def reset_ai_summary_logs() -> None:
    """Clear AI summary logs."""
    AI_SUMMARY_LOG_JSONL.parent.mkdir(parents=True, exist_ok=True)
    AI_SUMMARY_LOG_JSONL.write_text("", encoding="utf-8")


def load_ai_summary_logs() -> list[AiSummaryLog]:
    """
    Load AI summary logs.
    """

    if not AI_SUMMARY_LOG_JSONL.exists():
        return []

    logs: list[AiSummaryLog] = []

    with open(
        AI_SUMMARY_LOG_JSONL,
        "r",
        encoding="utf-8",
    ) as f:

        for line in f:

            line = line.strip()

            if not line:
                continue

            try:

                logs.append(
                    from_dict(
                        AiSummaryLog,
                        json.loads(line),
                    )
                )

            except json.JSONDecodeError as e:
                raise RuntimeError(
                    f"Invalid JSONL: {AI_SUMMARY_LOG_JSONL}"
                ) from e

    return logs


def append_ai_summary_logs(
    logs: list[AiSummaryLog],
):
    """
    Append AI summary logs.
    """

    with open(
        AI_SUMMARY_LOG_JSONL,
        "a",
        encoding="utf-8",
    ) as f:

        for log in logs:

            json.dump(
                log,
                f,
                ensure_ascii=False,
                default=json_default,
            )

            f.write("\n")