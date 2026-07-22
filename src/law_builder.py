"""
Law view.
"""

from models import (
    Law,
    LawGroup,
    Update,
)


def create_law(
    group: LawGroup,
) -> Law:
    """
    Create one Law from a LawGroup.
    """

    updates: list[Update] = []

    for event in group.events:

        metadata = event["metadata"]

        update: Update = {
            "published_date": metadata["published_date"],
            "effective_date": metadata["effective_date"],
            "effective_comment": metadata["effective_comment"],
            "amend_name": metadata["amend_name"],
            "amend_no": metadata["amend_number"],
            "amend_published_date": metadata["amend_published_date"],
            "pending": metadata["future"],
        }

        updates.append(update)

    return {
        "law_id": group.law_id,
        "law_no": group.law_no,
        "law_name": group.law_name,
        "law_type": group.law_type,
        "url": group.url,
        "updates": updates,
        "summary": None,
    }
