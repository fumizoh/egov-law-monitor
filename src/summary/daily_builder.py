"""Build Daily Summary input."""

from __future__ import annotations

from models import (
    SummaryResponse,
)

from summary.input import (
    DailySummaryInput,
)


def build_daily_summary_input(
    date: str,
    responses: list[SummaryResponse],
) -> DailySummaryInput:
    """Build Daily Summary input."""

    return DailySummaryInput(
        date=date,
        summaries=[
            response.summary
            for response in responses
        ],
    )