"""
Law grouping.
"""

from models import (
    Event,
    LawGroup,
    RevisionHistory,
)


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
                url=f"https://laws.e-gov.go.jp/law/{law_id}",
                events=[],
            )

        groups[law_id].events.append(event)

    return list(groups.values())


def match_revisions(
    law_group: LawGroup,
    revisions: list[RevisionHistory],
) -> list[RevisionHistory]:
    """Match law group events to revisions in Amendment_History order."""

    selected_law_data_ids: set[int] = set()

    for event in law_group.events:

        metadata = event["metadata"]

        amend_number = metadata["amend_number"]
        effective_date = metadata["effective_date"]

        # New law

        if not amend_number:

            for revision in revisions:

                if (
                    revision.is_new_law
                    and (
                        revision.enforcement_date
                        or revision.scheduled_enforcement_date
                    ) == effective_date
                ):
                    selected_law_data_ids.add(
                        revision.law_data_id,
                    )

            continue

        # Amendment

        # まずは改正法令番号＋施行日（予定施行日を含む）で一致
        matches = [
            revision
            for revision in revisions
            if (
                revision.amendment_num == amend_number
                and (
                    revision.enforcement_date
                    or revision.scheduled_enforcement_date
                ) == effective_date
            )
        ]

        # フォールバック
        if not matches:

            print(
                f"Fallback Revision Match: "
                f"{law_group.law_name} "
                f"{amend_number} "
                f"{effective_date}"
            )

            matches = [
                revision
                for revision in revisions
                if revision.amendment_num == amend_number
            ]

            if matches:

                matches.sort(
                    key=lambda revision: (
                        revision.enforcement_date
                        or revision.scheduled_enforcement_date
                        or ""
                    ),
                    reverse=True,
                )

                selected_law_data_ids.add(
                    matches[0].law_data_id,
                )

        else:

            for revision in matches:

                selected_law_data_ids.add(
                    revision.law_data_id,
                )

    # Preserve the original Amendment_History order.
    return [
        revision
        for revision in revisions
        if revision.law_data_id in selected_law_data_ids
    ]