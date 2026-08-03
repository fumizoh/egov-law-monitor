import comparison
import sources.revision_api as revision_api

from models import (
    RevisionHistory,
    LawSummaryInput,
)


# DEBUG
from pprint import pprint


def _get_revision_history(
    law_id: str,
) -> list[RevisionHistory]:
    """Fetch and parse revision history."""

    raw = revision_api.fetch_revisions(law_id)

    return comparison.parse_revision_history(
        raw["result"]["Amendment_History"]
    )


def build_daily_summary_input(
    law_group: LawGroup,
) -> LawSummaryInput:

    revisions = _get_revision_history(law_group.law_id)

    summary_revisions: list[RevisionHistory] = []

    for event in law_group.events:

        metadata = event["metadata"]

        amend_number = metadata["amend_number"]

        if amend_number:

            summary_revisions.extend(
                revision
                for revision in revisions
                if revision.amendment_num == amend_number
            )

        else:

            summary_revisions.extend(
                revision
                for revision in revisions
                if revision.is_new_law
            )

    return LawSummaryInput(
        law_id=law_group.law_id,
        law_name=law_group.law_name,
        revisions=summary_revisions,
    )