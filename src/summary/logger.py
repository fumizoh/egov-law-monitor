from __future__ import annotations

import json
from dataclasses import asdict
from pathlib import Path

from datetime import datetime

from models import (
    SummaryAction,
    SummaryLog,
    SummaryReason,
    SummaryUsage,
)

from config import AI_SUMMARY_LOG_JSON


def reset_summary_logs() -> None:
    """Clear summary logs."""
    AI_SUMMARY_LOG_JSON.parent.mkdir(parents=True, exist_ok=True)
    AI_SUMMARY_LOG_JSON.write_text("", encoding="utf-8")


def append_summary_log(log: SummaryLog) -> None:
    AI_SUMMARY_LOG_JSON.parent.mkdir(parents=True, exist_ok=True)

    record = asdict(log)

    # Enum を文字列に変換
    record["action"] = log.action.name
    record["reason"] = log.reason.name

    # datetime を ISO8601 文字列へ
    record["generated_at"] = log.generated_at.isoformat()

    with AI_SUMMARY_LOG_JSON.open("a", encoding="utf-8") as fp:
        json.dump(record, fp, ensure_ascii=False)
        fp.write("\n")


def log_summary(
    law_name: str,
    decision: SummaryDecision,
    usage: SummaryUsage | None = None,
) -> None:
    append_summary_log(
        SummaryLog(
            generated_at=datetime.now().astimezone(),
            law_name=law_name,
            action=decision.action,
            reason=decision.reason,
            usage=usage,
        )
    )


def load_summary_logs() -> list[SummaryLog]:
    if not AI_SUMMARY_LOG_JSON.exists():
        return []

    logs: list[SummaryLog] = []

    with AI_SUMMARY_LOG_JSON.open(encoding="utf-8") as fp:
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