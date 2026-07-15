"""
Law view.
"""

from models import Event, Law, Update


def create_law_view(
    events: list[Event],
) -> list[Law]:
    """
    Create a Law view from events.
    Events with the same law_id are grouped into one Law.
    """

    laws: dict[str, Law] = {}

    for event in events:

        # e-Gov法令更新のみ対象
        if event["type"] != "law_update":
            continue

        metadata = event["metadata"]
        law_id = metadata["law_id"]

        update: Update = {
            "published_date": metadata["published_date"],
            "effective_date": metadata["effective_date"],
            "effective_comment": metadata["effective_comment"],
            "amend_name": metadata["amend_name"],
            "amend_no": metadata["amend_number"],
            "amend_published_date": metadata["amend_published_date"],
            "pending": metadata["future"],
        }

        if law_id not in laws:

            laws[law_id] = {
                "law_id": law_id,
                "law_no": metadata["law_number"],
                "law_name": event["title"],
                "law_type": metadata["law_type"],
                "url": event["url"],
                "updates": [],
            }

        laws[law_id]["updates"].append(update)

    return list(laws.values())