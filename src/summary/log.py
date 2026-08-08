""" summary/log.py """

from models import (
    LawSummary,
    AiSummaryLog,
)

from datetime import datetime

from summary.constants import LAW_SUMMARY


def create_law_summary_log(
    law_summary: LawSummary,
) -> AiSummaryLog:

    timestamp = (
        datetime.now()
        .astimezone()
        .replace(microsecond=0)
        .isoformat()
    )

    return AiSummaryLog(
        timestamp=timestamp,
        service=LAW_SUMMARY,
        target=law_summary.summary_input.law_name,
        usage=law_summary.response.usage,
    )