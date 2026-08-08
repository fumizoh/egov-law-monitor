""" sources/revision.py """

from models import RevisionHistory

from sources import revision_api
import comparison


def get_revision_history(
    law_id: str,
) -> list[RevisionHistory]:
    """Fetch revision history."""

    raw = revision_api.fetch_revisions(law_id)

    return comparison.parse_revision_history(
        raw["result"]["Amendment_History"],
    )