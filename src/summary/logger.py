from __future__ import annotations

import json
from dataclasses import asdict
from pathlib import Path

from datetime import datetime, UTC

from models import (
    SummaryAction,
    SummaryLog,
    SummaryReason,
    SummaryUsage,
)

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


def log_summary(
    law_name: str,
    decision: SummaryDecision,
    usage: SummaryUsage | None = None,
) -> None:
    append_summary_log(
        SummaryLog(
            generated_at=datetime.now(UTC),
            law_name=law_name,
            action=decision.action,
            reason=decision.reason,
            usage=usage,
        )
    )


def load_summary_logs() -> list[SummaryLog]:
    if not LOG_PATH.exists():
        return []

    logs: list[SummaryLog] = []

    with LOG_PATH.open(encoding="utf-8") as fp:
        for line in fp:
            line = line.strip()

            if not line:
                continue

            record = json.loads(line)

            usage = None
            if record["usage"] is not None:
                usage = SummaryUsage(**record["usage"])

            logs.append(
                SummaryLog(
                    generated_at=datetime.fromisoformat(record["generated_at"]),
                    law_name=record["law_name"],
                    action=SummaryAction[record["action"]],
                    reason=SummaryReason[record["reason"]],
                    usage=usage,
                )
            )

    return logs