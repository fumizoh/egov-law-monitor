"""
Law Builder.
"""

import comparison
import sources.revision_api as revision_api
import summary.service as summary_service

from models import Law, LawGroup, LawRevision, Update


def create_law(
    group: LawGroup,
    revisions: list[LawRevision],
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


def build_law(
    group: LawGroup,
) -> tuple[Law, list[LawRevision]]:

    # DEBUG
    print(group.law_no, group.law_name)
    # DEBUG

    raw = revision_api.fetch_revisions(group.law_id)

    revisions = comparison.parse_revision_history(
        raw["result"]["Amendment_History"]
    )

    law = create_law(
        group,
        revisions,
    )

    return law, revisions


def build_laws(
    law_groups: list[LawGroup],
    previous_laws: dict[str, Law],
) -> list[Law]:
    """
    Build public Law models.
    """

    laws: list[Law] = []

    for group in law_groups:

        previous_law = previous_laws.get(group.law_id)

        law, revisions = build_law(group)

        summary, revision_keys = summary_service.build_future_summary(
            law_name=law["law_name"],
            revisions=revisions,
            previous_law=previous_law,
        )

        law["summary"] = summary
        law["summary_revision_keys"] = revision_keys

        laws.append(law)

    return laws