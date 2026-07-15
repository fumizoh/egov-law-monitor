"""
Law view.
"""

from models import Event, Law, Update


def create_law_view(
    events: list[Event],
) -> list[Law]:
    """
    Create a Law view from events.

    Currently converts one Event into one Law.
    Grouping by law_id will be added later.
    """

    laws: list[Law] = []

    for event in events:

        # e-Gov法令更新以外は対象外
        if event["type"] != "law_update":
            continue

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

        law: Law = {
            "law_id": metadata["law_id"],
            "law_no": metadata["law_number"],
            "law_name": event["title"],
            "law_type": metadata["law_type"],
            "url": event["url"],
            "updates": [update],
        }

        laws.append(law)

    return laws