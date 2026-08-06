"""
Law Builder.
"""

import sources.revision_api as revision_api

from models import (
    Law,
    LawGroup,
    Update,
)


def _create_updates(
    group: LawGroup,
) -> list[Update]:
    """
    Create updates from a LawGroup.
    """

    updates: list[Update] = []

    for event in group.events:

        metadata = event["metadata"]

        updates.append(
            {
                "published_date": metadata["published_date"],
                "effective_date": metadata["effective_date"],
                "effective_comment": metadata["effective_comment"],
                "amend_name": (
                    metadata["amend_name"]
                    or "新規制定"
                ),
                "amend_no": metadata["amend_number"],
                "amend_published_date": metadata["amend_published_date"],
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


def build_law(
    group: LawGroup,
) -> Law:

    raw = revision_api.fetch_revisions(group.law_id)

    law = create_law(group)

    return law


def build_laws(
    law_groups: list[LawGroup],
) -> list[Law]:
    """
    Build public Law models.
    """

    LAW_TYPE_ORDER = {
        "法律": 0,
        "政令": 1,
        "府省令": 2,
        "規則": 3,
    }

    laws: list[Law] = []

    for group in law_groups:

        law = build_law(group)

        laws.append(law)

    laws.sort(
        key=lambda law: (
            LAW_TYPE_ORDER.get(
                law["law_type"],
                99,
            ),
            law["law_name"],
        )
    )

    return laws