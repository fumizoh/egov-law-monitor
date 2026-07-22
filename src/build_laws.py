"""
Build public Law models.
"""

from pathlib import Path
import sys
sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.revision_api import fetch_revisions

from models import (
    Law,
    LawGroup,
)

from comparison import parse_revision_history

from law_builder import create_law


def build_law(
    group: LawGroup,
) -> Law:

    # Fetch revision history
    raw = fetch_revisions(group.law_id)
    revisions = parse_revision_history(raw)

    current = next(
        revision
        for revision in revisions
        if revision.is_current
    )

    latest = revisions[0]

    return create_law(group)


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