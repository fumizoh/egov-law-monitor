from models import RevisionHistory, LawSummaryTarget
from summary.revision import (
    SummaryType,
    SummaryRevisionKey,
)


def _build_summary_revision_key(
    revision: RevisionHistory,
) -> SummaryRevisionKey:
    """Build a summary revision key from one revision."""
    
    return SummaryRevisionKey(
        law_data_id=revision.law_data_id,
        sub_revision=revision.sub_revision,
    )


def _build_law_summary_target(
    revision: RevisionHistory,
) -> LawSummaryTarget:

    return LawSummaryTarget(
        revisions=[revision],
        summary_type=SummaryType.NEW_LAW,
        revision_keys=[
            _build_summary_revision_key(revision),
        ],
    )


def select_law_summary_targets(
    revisions: list[RevisionHistory],
) -> list[LawSummaryTarget]:

    targets: list[LawSummaryTarget] = []

    for revision in revisions:

        if not revision.is_new_law:
            continue

        targets.append(
            _build_law_summary_target(revision)
        )

    return targets