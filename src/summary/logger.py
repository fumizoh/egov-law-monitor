from __future__ import annotations

import json
from dataclasses import asdict
from pathlib import Path

from models import SummaryLog

LOG_PATH = Path("data/logs/summary.jsonl")


def append_summary_log(log: SummaryLog) -> None:
    LOG_PATH.parent.mkdir(parents=True, exist_ok=True)

    record = asdict(log)

    # Enum を文字列に変換
    record["action"] = log.action.name
    record["reason"] = log.reason.name

    # datetime を ISO8601 文字列へ
    record["generated_at"] = log.generated_at.isoformat()

    with LOG_PATH.open("a", encoding="utf-8") as fp:
        json.dump(record, fp, ensure_ascii=False)
        fp.write("\n")