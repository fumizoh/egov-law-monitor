"""
Law grouping.
"""

from models import Event, LawGroup


def group_by_law(
    events: list[Event],
) -> list[LawGroup]:
    """Group events by law."""

    groups: dict[str, LawGroup] = {}

    for event in events:

        if event["type"] != "law_update":
            continue

        metadata = event["metadata"]
        law_id = metadata["law_id"]

        if law_id not in groups:
            groups[law_id] = LawGroup(
                law_id=law_id,
                law_no=metadata["law_number"],
                law_name=event["title"],
                law_type=metadata["law_type"],
                url=event["url"],
                events=[],
            )

        groups[law_id].events.append(event)

    return list(groups.values())