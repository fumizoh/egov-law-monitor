"""Summary service."""

from datetime import date, datetime

from models import (
    Law,
    RevisionHistory,
    Summary,
    SummaryLog,
)

from summary.revision import (
    SummaryRevisionKey,
    SummaryAction,
    SummaryReason,
    SummaryDecision,
)

from summary.generator import generate_future_summary
from summary.logger import log_summary


def _should_include_summary(
    revision: RevisionHistory,
) -> bool:
    """Return True if the revision should be included in the future summary."""

    # Current law is never part of the future summary.
    if revision.is_current:
        return False

    # Include revisions with no enforcement date yet.
    if revision.enforcement_date is None:
        return True

    return (
        date.fromisoformat(revision.enforcement_date)
        > date.today()
    )


def get_summary_revisions(
    revisions: list[RevisionHistory],
) -> list[RevisionHistory]:
    """Return revisions included in the future summary."""

    summary_revisions = [
        revision
        for revision in revisions
        if _should_include_summary(revision)
    ]

    return sorted(
        summary_revisions,
        key=lambda revision: (
            revision.enforcement_date is None,
            revision.enforcement_date or "",
        ),
    )


def build_summary_revision_keys(
    revisions: list[RevisionHistory],
) -> list[SummaryRevisionKey]:

    return [
        SummaryRevisionKey(
            law_data_id=revision.law_data_id,
            sub_revision=revision.sub_revision,
        )
        for revision in revisions
    ]


def needs_summary(
    previous_law: Law | None,
    revision_keys: list[SummaryRevisionKey],
) -> SummaryDecision:
    """Determine whether the future summary should be generated."""

    if previous_law is None:
        return SummaryDecision(
            action=SummaryAction.GENERATE,
            reason=SummaryReason.NEW_LAW,
        )

    if (
        previous_law["summary"] is None
        or not previous_law["summary_revision_keys"]
    ):
        return SummaryDecision(
            action=SummaryAction.GENERATE,
            reason=SummaryReason.SUMMARY_MISSING,
        )

    if previous_law["summary_revision_keys"] != revision_keys:
        return SummaryDecision(
            action=SummaryAction.GENERATE,
            reason=SummaryReason.NEW_REVISION,
        )

    return SummaryDecision(
        action=SummaryAction.REUSE,
        reason=SummaryReason.UNCHANGED,
    )


def build_future_summary(
    law_name: str,
    revisions: list[RevisionHistory],
    previous_law: Law | None = None,
) -> tuple[Summary | None, list[SummaryRevisionKey]]:
    """Build the future summary."""

    summary_revisions = get_summary_revisions(revisions)

    if not summary_revisions:
        return None, []

    revision_keys = build_summary_revision_keys(
        summary_revisions,
    )

    # DEBUG
    # if previous_law is not None:
    #     print(revision_keys)
    #     print(previous_law["summary_revision_keys"])
    #     print(revision_keys == previous_law["summary_revision_keys"])
    # DEBUG

    decision = needs_summary(
        previous_law=previous_law,
        revision_keys=revision_keys,
    )

    # DEBUG
    # print(
    #     f"Summary: {decision.action.name} "
    #     f"({decision.reason.name})"
    # )
    # DEBUG

    if decision.action is SummaryAction.REUSE:

        log_summary(
            law_name=law_name,
            decision=decision,
        )

        return (
            previous_law["summary"],
            previous_law["summary_revision_keys"],
        )

    response = generate_future_summary(
        law_name=law_name,
        revisions=summary_revisions,
    )

    summary = response.summary
    usage = response.usage

    log_summary(
        law_name=law_name,
        decision=decision,
        usage=usage,
    )

    # DEBUG
    # print(
    #     f"AI Usage: "
    #     f"{usage.total_tokens} tokens "
    #     f"({usage.elapsed_seconds:.2f}s)"
    # )
    # DEBUG

    return (
        summary,
        revision_keys,
    )