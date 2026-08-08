"""Create AI summary statistics."""

from models import (
    SummaryUsage,
    SummaryStatistics,
    AiSummaryLog,
    AiStatistics,
)

from summary import cost

from summary.constants import LAW_SUMMARY


def _create_summary_statistics(
    model: str,
    usages: list[SummaryUsage],
) -> SummaryStatistics:
    """Create aggregated statistics from SummaryUsage."""

    prompt_tokens = 0
    output_tokens = 0
    thoughts_tokens = 0
    total_tokens = 0

    elapsed_seconds = 0.0

    estimated_cost_usd = 0.0
    estimated_cost_jpy = 0.0

    for usage in usages:

        prompt_tokens += usage.prompt_tokens
        output_tokens += usage.output_tokens
        thoughts_tokens += usage.thoughts_tokens
        total_tokens += usage.total_tokens

        elapsed_seconds += usage.elapsed_seconds

        cost_usd, cost_jpy = cost.calculate_cost(
            usage,
        )

        estimated_cost_usd += cost_usd
        estimated_cost_jpy += cost_jpy

    count = len(usages)

    average_cost_jpy = (
        estimated_cost_jpy / count
        if count
        else 0.0
    )

    return SummaryStatistics(
        model=model,
        count=count,
        prompt_tokens=prompt_tokens,
        output_tokens=output_tokens,
        thoughts_tokens=thoughts_tokens,
        total_tokens=total_tokens,
        elapsed_seconds=round(
            elapsed_seconds,
            2,
        ),
        estimated_cost_usd=round(
            estimated_cost_usd,
            3,
        ),
        estimated_cost_jpy=round(
            estimated_cost_jpy,
            1,
        ),
        average_cost_jpy=round(
            average_cost_jpy,
            1,
        ),
    )


def create_statistics(
    logs: list[AiSummaryLog],
) -> AiStatistics:
    """Create AI summary statistics."""

    usages = [
        log.usage
        for log in logs
        if log.service == LAW_SUMMARY
    ]

    model = usages[0].model if usages else ""

    law_summary = _create_summary_statistics(
        model=model,
        usages=usages,
    )

    return AiStatistics(
        law_summary=law_summary,
    )