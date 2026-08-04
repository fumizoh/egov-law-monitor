from models import (
    SummaryUsage,
    SummaryStatistics,
    LawSummary,
    AiSummaryLog,
    DailySummaryResponse,
    AiStatistics,
)

from config import (
    GEMINI_INPUT_PRICE_USD_PER_MILLION,
    GEMINI_OUTPUT_PRICE_USD_PER_MILLION,
    USD_TO_JPY_RATE,
)

from summary.constants import (
    LAW_SUMMARY,
    DAILY_SUMMARY,
)


def _count_actions(
    logs: list[AiSummaryLog],
    service: str,
) -> tuple[int, int]:
    """Count generated and reused summaries."""

    generated = 0
    reused = 0

    for log in logs:

        if log.service != service:
            continue

        if log.reused:
            reused += 1
        else:
            generated += 1

    return generated, reused


def _create_summary_statistics(
    model: str,
    usages: list[SummaryUsage],
    generated: int,
    reused: int,
) -> SummaryStatistics:
    """Create aggregated statistics from SummaryUsage."""

    prompt_tokens = 0
    output_tokens = 0
    thoughts_tokens = 0
    total_tokens = 0

    elapsed_seconds = 0.0

    for usage in usages:
        prompt_tokens += usage.prompt_tokens
        output_tokens += usage.output_tokens
        thoughts_tokens += usage.thoughts_tokens
        total_tokens += usage.total_tokens

        elapsed_seconds += usage.elapsed_seconds

    input_cost_usd = (
        prompt_tokens
        / 1_000_000
        * GEMINI_INPUT_PRICE_USD_PER_MILLION
    )

    output_cost_usd = (
        (output_tokens + thoughts_tokens)
        / 1_000_000
        * GEMINI_OUTPUT_PRICE_USD_PER_MILLION
    )

    estimated_cost_usd = input_cost_usd + output_cost_usd
    estimated_cost_jpy = estimated_cost_usd * USD_TO_JPY_RATE

    average_cost_jpy = (
        estimated_cost_jpy / generated
        if generated
        else 0.0
    )

    return SummaryStatistics(
        model=model,
        generated=generated,
        reused=reused,
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
    law_summaries: list[LawSummary],
    logs: list[AiSummaryLog],
) -> SummaryStatistics:

    model = ""

    if law_summaries:
        model = law_summaries[0].response.usage.model

    usages = [
        log.usage
        for log in logs
        if (
            log.service == LAW_SUMMARY
            and log.usage is not None
        )
    ]

    generated, reused = _count_actions(
        logs,
        LAW_SUMMARY,
    )

    return _create_summary_statistics(
        model=model,
        usages=usages,
        generated=generated,
        reused=reused,
    )


def _create_daily_summary_statistics(
    daily_summary: DailySummaryResponse,
    logs: list[AiSummaryLog],
) -> SummaryStatistics:

    generated, reused = _count_actions(
        logs,
        DAILY_SUMMARY,
    )

    return _create_summary_statistics(
        model=daily_summary.usage.model,
        usages=[daily_summary.usage],
        generated=generated,
        reused=reused,
    )


def _create_total_statistics(
    law: SummaryStatistics,
    daily: SummaryStatistics,
) -> SummaryStatistics:

    return SummaryStatistics(
        model=law.model if law.model == daily.model else "",
        generated=law.generated + daily.generated,
        reused=law.reused + daily.reused,
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
            )
            / (law.generated + daily.generated),
            1,
        )
        if (law.generated + daily.generated)
        else 0.0,
    )


def create_statistics(
    law_summaries: list[LawSummary],
    daily_summary: DailySummaryResponse,
    logs: list[AiSummaryLog],
) -> AiStatistics:

    law_summary = _create_law_summary_statistics(
        law_summaries,
        logs,
    )

    daily_summary_statistics = (
        _create_daily_summary_statistics(
            daily_summary,
            logs,
        )
    )

    total = _create_total_statistics(
        law_summary,
        daily_summary_statistics,
    )

    return AiStatistics(
        law_summary=law_summary,
        daily_summary=daily_summary_statistics,
        total=total,
    )