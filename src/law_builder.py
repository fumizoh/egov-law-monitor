"""
Law Builder.
"""

from config import LAW_TYPE_ORDER

from models import (
    Law,
    LawGroup,
    Update,
)

from sources.revision import get_revision_history
from law_group import match_revisions


def _create_updates(
    group: LawGroup,
) -> list[Update]:
    """
    Create updates from a LawGroup.
    """

    revisions = get_revision_history(
        group.law_id,
    )

    matched_revisions = match_revisions(
        group,
        revisions,
    )

    updates: list[Update] = []

    for revision in matched_revisions:

        effective_date = (
            revision.enforcement_date
            or revision.scheduled_enforcement_date
        )

        if revision.is_new_law:

            matches = [
                event
                for event in group.events
                if (
                    not event["metadata"]["amend_number"]
                    and event["metadata"]["effective_date"]
                    == effective_date
                )
            ]

        else:

            matches = [
                event
                for event in group.events
                if (
                    event["metadata"]["amend_number"]
                    == revision.amendment_num
                    and event["metadata"]["effective_date"]
                    == effective_date
                )
            ]

        if not matches:
            continue

        metadata = matches[0]["metadata"]

        updates.append(
            {
                "law_data_id": revision.law_data_id,
                "sub_revision": revision.sub_revision,
                "published_date": metadata["published_date"],
                "effective_date": metadata["effective_date"],
                "effective_comment": metadata["effective_comment"],
                "amend_name": (
                    metadata["amend_name"]
                    or "新規制定"
                ),
                "amend_no": metadata["amend_number"],
                "amend_published_date": metadata[
                    "amend_published_date"
                ],
                "pending": metadata["future"],
            }
        )

    return updates


def create_law(
    group: LawGroup,
) -> Law:
    """
    Create one Law from a LawGroup.
    """

    return {
        "law_id": group.law_id,
        "law_no": group.law_no,
        "law_name": group.law_name,
        "law_type": group.law_type,
        "url": group.url,
        "updates": _create_updates(group),
    }


def build_laws(
    law_groups: list[LawGroup],
) -> list[Law]:
    """
    Build public Law models.
    """

    laws: list[Law] = []

    for group in law_groups:

        laws.append(
            create_law(group)
        )

    return laws


def sort_law_groups(
    law_groups: list[LawGroup],
) -> list[LawGroup]:
    """
    Sort LawGroups in public display order.
    """

    return sorted(
        law_groups,
        key=lambda group: (
            LAW_TYPE_ORDER.get(
                group.law_type,
                99,
            ),
            group.law_name,
        ),
    )