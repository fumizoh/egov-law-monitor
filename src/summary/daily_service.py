from models import (
    DailySummaryResponse,
    SummaryResponse,
)
from summary.daily_builder import build_daily_summary_input
from summary.daily_generator import generate_daily_summary_response
from summary.usage import merge_usage


def generate_daily_summary(
    date: str,
    responses: list[SummaryResponse],
) -> DailySummaryResponse:
    """Generate Daily Summary."""

    daily_input = build_daily_summary_input(
        date=date,
        responses=responses,
    )

    response = generate_daily_summary_response(
        daily_input,
    )

    usage = merge_usage(
        usages=[
            r.usage
            for r in responses
        ],
        model=response.usage.model,
        response_id=response.usage.response_id,
        elapsed_seconds=(
            sum(
                r.usage.elapsed_seconds
                for r in responses
            )
            + response.usage.elapsed_seconds
        ),
    )

    return DailySummaryResponse(
        summary=response.summary,
        usage=usage,
    )