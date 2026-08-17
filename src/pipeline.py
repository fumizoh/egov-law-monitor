""" pipeline.py """

from models import Law

import law_group
import law_builder
import storage
from summary import generator
import summary.statistics as summary_statistics

from statistics import create_source_statistics


def _save_statistics(
    source: str,
    events,
    laws,
    date,
) -> None:
    """Create and save statistics."""

    statistics = create_source_statistics(
        source=source,
        events=events,
        laws=laws,
        latest_date=date,
    )

    storage.save_statistics(
        source=source,
        statistics=statistics,
    )

    print(f"{source}: データ保存・統計更新完了")


def process_egov(
    events,
    date,
) -> list[Law]:
    """Process e-Gov updates."""

    law_groups = law_group.group_by_law(events)

    law_groups = law_builder.sort_law_groups(law_groups)

    laws = law_builder.build_laws(law_groups)

    # DEBUG
    print("Total:", len(laws), "laws")

    # laws.json を公開データとして保存
    storage.save_laws(laws)

    # Save Statistics
    _save_statistics(
        "egov",
        events,
        laws,
        date,
    )

    # Daily service
    law_summaries, logs = generator.generate(law_groups)

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

    return laws