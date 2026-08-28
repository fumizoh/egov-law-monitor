"""Watch service."""

from models import (
    Law,
    LawSummary,
    WatchNotification,
)

from wordpress import client


def get_watches() -> list[dict]:
    """Get watched laws from WordPress."""

    return client.get_watches()


def find_watched_laws(
    laws: list[Law],
    watches: list[dict],
) -> list[Law]:
    """Return laws that are being watched."""

    watch_ids = {
        watch["law_id"]
        for watch in watches
    }

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

    summaries = {
        law_summary.summary_input.law_id: law_summary.response.summary
        for law_summary in law_summaries
    }

    return [
        WatchNotification(
            law=law,
            summary=summaries.get(law["law_id"]),
        )
        for law in laws
    ]