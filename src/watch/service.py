"""Watch service."""

from models import (
    Law,
    LawSummary,
    WatchNotification,
)

from wordpress import client


def get_watch_ids() -> set[str]:
    """Get watched law IDs from WordPress."""

    watches = client.get_watches()

    return {
        watch["law_id"]
        for watch in watches
    }


def find_watched_laws(
    laws: list[Law],
) -> list[Law]:
    """Return laws that are being watched."""

    watch_ids = get_watch_ids()

    return [
        law
        for law in laws
        if law["law_id"] in watch_ids
    ]


def build_notifications(
    laws: list[Law],
    law_summaries: list[LawSummary],
) -> list[WatchNotification]:
    """Build watch notifications for watched laws."""

    watched_laws = find_watched_laws(laws)

    summaries = {
        law_summary.summary_input.law_id: law_summary.response.summary
        for law_summary in law_summaries
    }

    return [
        WatchNotification(
            law=law,
            summary=summaries.get(law["law_id"]),
        )
        for law in watched_laws
    ]