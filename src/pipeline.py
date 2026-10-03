""" pipeline.py """

import logging

from models import Law

import storage
import law_group
import law_builder

from statistics import create_source_statistics


logger = logging.getLogger(__name__)


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

    return laws