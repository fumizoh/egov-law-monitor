from law_group import group_by_law

import law_builder

from storage import (
    save_source_data,
    save_laws,
    save_statistics,
    load_json,
    load_laws,
    save_ai_statistics,
)

from summary.logger import (
    reset_summary_logs,
    load_summary_logs,
)

from statistics import(
    create_statistics,
    create_ai_statistics,
)


def _save_statistics(
    source: str,
    updates,
    date,
) -> None:
    """Create and save statistics."""

    statistics = create_statistics(
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

    # Law View を公開データとして保存
    reset_summary_logs()

    law_groups = group_by_law(updates)

    previous_laws = load_laws()

    laws = law_builder.build_laws(
        law_groups,
        previous_laws=previous_laws,
    )

    print("Total:", len(laws), "laws")

    save_laws(laws)

    # AI Summary ログ集計
    logs = load_summary_logs()

    ai_statistics = create_ai_statistics(logs)

    save_ai_statistics(ai_statistics)

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

    _save_statistics(
        "public_comment",
        updates,
        date,
    )