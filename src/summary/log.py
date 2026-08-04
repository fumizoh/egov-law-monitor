import summary.constants as constants

from models import (
    LawSummary,
    DailySummaryResponse,
    AiSummaryLog,
)


def create_law_summary_log(
    date: str,
    law_summary: LawSummary,
    reused: bool,
) -> AiSummaryLog:

    return AiSummaryLog(
        date=date,
        service=constants.LAW_SUMMARY,
        target=law_summary.summary_input.law_name,
        reused=reused,
        usage=None if reused else law_summary.response.usage,
    )


def create_daily_summary_log(
    date: str,
    response: DailySummaryResponse,
) -> AiSummaryLog:

    return AiSummaryLog(
        date=date,
        service=constants.DAILY_SUMMARY,
        target=None,
        reused=False,
        usage=response.usage,
    )