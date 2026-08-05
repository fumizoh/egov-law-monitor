import law_group
import law_builder
import storage

import summary.daily_service as daily_service
import summary.statistics as summary_statistics

from statistics import create_source_statistics


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

    storage.save_statistics(
        source=source,
        statistics=statistics,
    )

    print(f"{source}: データ保存・統計更新完了")


def process_egov(
    updates,
    date,
):
    """Process e-Gov updates."""

    storage.save_source_data("egov", updates)

    # Law を公開データとして保存
    law_groups = law_group.group_by_law(updates)

    laws = law_builder.build_laws(law_groups)

    # DEBUG
    print("Total:", len(laws), "laws")

    storage.save_laws(laws)

    # Daily Summary
    daily_summary, law_summaries, logs = daily_service.generate(
        date,
        law_groups,
    )

    storage.save_daily_summary(daily_summary)

    storage.save_law_summaries(law_summaries)

    # Tool: Delete All AI Summary Log
    # storage.reset_ai_summary_logs()

    storage.append_ai_summary_logs(logs)

    # AI Statistics
    all_logs = storage.load_ai_summary_logs()

    statistics = summary_statistics.create_statistics(
        all_logs,
    )

    storage.save_ai_statistics(statistics)

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

    storage.save_source_data("public_comment", updates)

    # Save Statistics
    _save_statistics(
        "public_comment",
        updates,
        date,
    )