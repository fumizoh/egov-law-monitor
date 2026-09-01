"""Watch service."""

from models import (
    Law,
    LawSummary,
    WatchNotification,
    WatchSetting,
    WatchUser,
)

from wordpress import client


def get_watch_users() -> list[WatchUser]:
    """Get watch settings for all users."""

    users = client.get_watch_settings()

    return [
        WatchUser(
            user_id=user["user_id"],
            email=user["email"],
            watches=[
                WatchSetting(
                    law_id=watch["law_id"],
                    law_name=watch["law_name"],
                )
                for watch in user["watches"]
            ],
        )
        for user in users
    ]


def find_watched_laws(
    laws: list[Law],
    watches: list[WatchSetting],
) -> list[Law]:
    """Return laws that are being watched."""

    watch_ids = {
        watch.law_id
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


def build_user_notifications(
    laws: list[Law],
    law_summaries: list[LawSummary],
    user: WatchUser,
) -> list[WatchNotification]:
    """Build notifications for a user."""

    watched_laws = find_watched_laws(
        laws,
        user.watches,
    )

    return build_notifications(
        watched_laws,
        law_summaries,
    )