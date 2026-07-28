"""
Build public Law models.
"""

from sources.revision_api import fetch_revisions

from models import (
    Law,
    LawGroup,
    LawRevision,
)

from comparison import parse_revision_history

from law_builder import create_law
from summary.service import build_future_summary


def build_law(
    group: LawGroup,
) -> tuple[Law, list[LawRevision]]:

    # DEBUG
    print(group.law_no, group.law_name)
    # DEBUG

    raw = fetch_revisions(group.law_id)

    revisions = parse_revision_history(
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

        summary, revision_keys = build_future_summary(
            law_name=law["law_name"],
            revisions=revisions,
            previous_law=previous_law,
        )

        law["summary"] = summary
        law["summary_revision_keys"] = revision_keys

        laws.append(law)

    return laws