""" pipeline.py """

import logging

from models import Law

import storage
import law_group
import law_builder

from summary import generator

import summary.statistics as summary_statistics

from statistics import create_source_statistics


logger = logging.getLogger(__name__)

BATCH_SIZE = 10


def _save_statistics(
    source: str,
    events,
    laws,
    date,
    storage_paths: storage.StoragePaths,
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
        paths=storage_paths,
    )

    logger.info(
        "%s: データ保存・統計更新完了",
        source,
    )


def process_egov(
    events,
    date,
    storage_paths: storage.StoragePaths = storage.DEFAULT_STORAGE,
) -> list[Law]:
    """Process e-Gov updates."""

    law_groups = law_group.group_by_law(events)

    law_groups = law_builder.sort_law_groups(law_groups)

    laws = law_builder.build_laws(law_groups)

    logger.info("Total %d laws", len(laws))

    storage.save_laws(
        laws,
        paths=storage_paths,
    )

    _save_statistics(
        "egov",
        events,
        laws,
        date,
        storage_paths=storage_paths,
    )

    storage.prepare_law_summaries(
        date=date,
        paths=storage_paths,
    )

    total_batches = (len(law_groups) + BATCH_SIZE - 1) // BATCH_SIZE

    for i in range(0, len(law_groups), BATCH_SIZE):
        batch = law_groups[i:i + BATCH_SIZE]

        law_summaries, logs = generator.generate(
            batch,
            storage_paths=storage_paths,
        )

        storage.upsert_law_summaries(
            law_summaries,
            date=date,
            paths=storage_paths,
        )

        storage.append_ai_summary_logs(logs)

        batch_number = i // BATCH_SIZE + 1
        processed = min(i + BATCH_SIZE, len(law_groups))

        logger.info(
            "Summary batch %d/%d completed (%d/%d)",
            batch_number,
            total_batches,
            processed,
            len(law_groups),
        )

    all_logs = storage.load_ai_summary_logs()

    statistics = summary_statistics.create_statistics(
        all_logs,
    )

    storage.save_ai_statistics(statistics)

    return laws