"""
Build public Law models.
"""

from sources.revision_api import fetch_revisions

from models import (
    Law,
    LawGroup,
)

from comparison import parse_revision_history

from law_builder import create_law

# DEBUG
# import time
# DEBUG

def build_law(
    group: LawGroup,
) -> Law:

    # DEBUG
    # print(group.law_no, group.law_name)
    # DEBUG

    raw = fetch_revisions(group.law_id)

    revisions = parse_revision_history(raw)

    return create_law(
        group,
        revisions,
    )


def build_laws(
    law_groups: list[LawGroup],
) -> list[Law]:
    """
    Build public Law models.
    """

    laws: list[Law] = []

    for group in law_groups:
        laws.append(build_law(group))

    return laws