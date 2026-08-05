from models import (
    SummaryUsage,
    SummaryStatistics,
    AiSummaryLog,
    AiStatistics,
)

from summary import cost

from summary.constants import (
    LAW_SUMMARY,
    DAILY_SUMMARY,
)


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
        elapsed_seconds=round(elapsed_seconds, 2),
        estimated_cost_usd=round(estimated_cost_usd, 3),
        estimated_cost_jpy=round(estimated_cost_jpy, 1),
        average_cost_jpy=round(average_cost_jpy, 1),
    )


def _create_law_summary_statistics(
    logs: list[AiSummaryLog],
) -> SummaryStatistics:

    usages = [
        log.usage
        for log in logs
        if log.service == LAW_SUMMARY
    ]

    model = usages[0].model if usages else ""

    return _create_summary_statistics(
        model=model,
        usages=usages,
    )


def _create_daily_summary_statistics(
    logs: list[AiSummaryLog],
) -> SummaryStatistics:

    usages = [
        log.usage
        for log in logs
        if log.service == DAILY_SUMMARY
    ]

    model = usages[0].model if usages else ""

    return _create_summary_statistics(
        model=model,
        usages=usages,
    )


def _create_total_statistics(
    law: SummaryStatistics,
    daily: SummaryStatistics,
) -> SummaryStatistics:

    count = law.count + daily.count

    return SummaryStatistics(
        model=law.model if law.model == daily.model else "",
        count=count,
        prompt_tokens=law.prompt_tokens + daily.prompt_tokens,
        output_tokens=law.output_tokens + daily.output_tokens,
        thoughts_tokens=law.thoughts_tokens + daily.thoughts_tokens,
        total_tokens=law.total_tokens + daily.total_tokens,
        elapsed_seconds=round(
            law.elapsed_seconds + daily.elapsed_seconds,
            2,
        ),
        estimated_cost_usd=round(
            law.estimated_cost_usd + daily.estimated_cost_usd,
            3,
        ),
        estimated_cost_jpy=round(
            law.estimated_cost_jpy + daily.estimated_cost_jpy,
            1,
        ),
        average_cost_jpy=round(
            (
                law.estimated_cost_jpy
                + daily.estimated_cost_jpy
            ) / count,
            1,
        ) if count else 0.0,
    )


def create_statistics(
    logs: list[AiSummaryLog],
) -> AiStatistics:

    law_summary = _create_law_summary_statistics(
        logs,
    )

    daily_summary = _create_daily_summary_statistics(
        logs,
    )

    total = _create_total_statistics(
        law_summary,
        daily_summary,
    )

    return AiStatistics(
        law_summary=law_summary,
        daily_summary=daily_summary,
        total=total,
    )