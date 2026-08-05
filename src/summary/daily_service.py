from __future__ import annotations

import summary.builder as builder
import summary.generator as generator
import storage
import summary.log as log

from summary.daily_builder import build_daily_summary_input
from summary.daily_generator import generate_daily_summary_response
from summary.usage import merge_usage

from models import (
    LawGroup,
    SummaryResponse,
    DailySummaryResponse,
    LawSummary,
    AiSummaryLog,
)


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


def generate(
    date: str,
    law_groups: list[LawGroup],
) -> tuple[
    DailySummaryResponse,
    list[LawSummary],
    list[AiSummaryLog],
]:

    cached_summaries = storage.load_law_summaries()

    summary_responses: list[SummaryResponse] = []

    law_summaries: list[LawSummary] = []

    logs: list[AiSummaryLog] = []

    for law_group in law_groups:

        summary_input = builder.build_law_summary_input(
            law_group,
        )

        previous_summary = cached_summaries.get(
            summary_input.law_id,
        )

        reused = (
            previous_summary is not None
            and previous_summary.summary_input == summary_input
        )

        if reused:
            # DEBUG
            print(f"Reuse summary: {summary_input.law_name}")

            law_summary = previous_summary

        else:
            # DEBUG
            print(f"Generate summary: {summary_input.law_name}")

            response = generator.generate_law_summary(
                summary_input,
            )

            if response is None:
                continue

            law_summary = LawSummary(
                summary_input=summary_input,
                response=response,
            )

        summary_responses.append(
            law_summary.response,
        )

        law_summaries.append(
            law_summary,
        )

        logs.append(
            log.create_law_summary_log(
                date=date,
                law_summary=law_summary,
                reused=reused,
            )
        )

    # DEBUG
    print(f"Generate daily summary: {date}")

    daily_summary = generate_daily_summary(
        date,
        summary_responses,
    )

    logs.append(
        log.create_daily_summary_log(
            date=date,
            response=daily_summary,
        )
    )

    return (
        daily_summary,
        law_summaries,
        logs,
    )