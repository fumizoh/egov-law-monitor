from law_group import group_by_law

import law_builder
import summary.daily_service as daily_service
import summary.statistics as summary_statistics

from storage import (
    save_source_data,
    save_laws,
    save_statistics,
    save_daily_summary,
    save_law_summaries,
    append_ai_summary_logs,
    save_ai_statistics,
)

from statistics import(
    create_source_statistics,
)


def _save_statistics(
    source: str,
    updates,
    date,
) -> None:
    """Create and save statistics."""

    statistics = create_source_statistics(
        source=source,
        updates=updates,
        latest_date=date,
    )

    save_statistics(
        source=source,
        statistics=statistics,
    )

    print(f"{source}: データ保存・統計更新完了")


def process_egov(
    updates,
    date,
):
    """Process e-Gov updates."""

    save_source_data("egov", updates)

    # Law を公開データとして保存
    law_groups = group_by_law(updates)

    laws = law_builder.build_laws(law_groups)

    # DEBUG
    print("Total:", len(laws), "laws")

    save_laws(laws)

    # Daily Summary
    daily_summary, law_summaries, logs = daily_service.generate(
        date,
        law_groups,
    )

    save_daily_summary(daily_summary)

    save_law_summaries(law_summaries)

    append_ai_summary_logs(logs)

    # AI Summary Statistics
    statistics = summary_statistics.create_statistics(
        law_summaries,
        daily_summary,
        logs,
    )

    save_ai_statistics(statistics)

    # Save Statistics
    _save_statistics(
        "egov",
        updates,
        date,
    )


def process_public_comment(
    updates,
    date,
):
    """Process public comment updates."""

    save_source_data("public_comment", updates)

    # Save Statistics
    _save_statistics(
        "public_comment",
        updates,
        date,
    )