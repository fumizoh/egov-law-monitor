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
from summary.generator import generate_law_summary


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
) -> list[Law]:
    """
    Build public Law models.
    """

    laws: list[Law] = []

    for group in law_groups:

        law, revisions = build_law(group)

        '''
        law["summary"] = generate_law_summary(
            law_name=law["law_name"],
            revisions=revisions,
        )
        '''

        laws.append(law)

    return laws