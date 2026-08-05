from datetime import datetime

from models import (
    LawSummary,
    DailySummaryResponse,
    AiSummaryLog,
)

import summary.constants as constants


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
        service=constants.LAW_SUMMARY,
        target=law_summary.summary_input.law_name,
        usage=law_summary.response.usage,
    )


def create_daily_summary_log(
    daily_summary: DailySummaryResponse,
) -> AiSummaryLog:

    timestamp = (
        datetime.now()
        .astimezone()
        .replace(microsecond=0)
        .isoformat()
    )

    return AiSummaryLog(
        timestamp=timestamp,
        service=constants.DAILY_SUMMARY,
        target=None,
        usage=daily_summary.usage,
    )