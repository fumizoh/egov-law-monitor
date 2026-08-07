"""Build AI summary input."""

from __future__ import annotations

from sources.lawtext_api import fetch_law_text

from lawtext_parser import parse_law_text

from models import (
    LawGroup,
    LawChange,
    RevisionHistory,
    LawSummaryInput,
)

from summary.input import (
    SummaryChange,
    SummaryArticle,
    AmendmentSummaryInput,
    NewLawSummaryInput,
    SummaryInput,
)


import comparison
import sources.revision_api as revision_api


# DEBUG
from pprint import pprint


def _build_summary_changes(
    changes: list[LawChange],
) -> list[SummaryChange]:
    """Build summary changes from law changes."""

    summary_changes: list[SummaryChange] = []

    for change in changes:

        # DEBUG
        # print(change.location.article, change.change_type)
        # DEBUG

        if change.change_type == "same":
            continue

        summary_changes.append(
            SummaryChange(
                location=change.location,
                change_type=change.change_type,
                before=change.before,
                after=change.after,
            )
        )

    return summary_changes


def _build_summary_articles(
    changes: list[SummaryChange],
) -> list[SummaryArticle]:
    """Group summary changes by article."""

    grouped: dict[str, list[SummaryChange]] = {}

    for change in changes:
        grouped.setdefault(
            change.location.article,
            [],
        ).append(change)

    return [
        SummaryArticle(
            article=article,
            changes=article_changes,
        )
        for article, article_changes in grouped.items()
    ]


def build_amendment_summary_input(
    revision: RevisionHistory,
    changes: list[LawChange],
) -> AmendmentSummaryInput:

    summary_changes = _build_summary_changes(changes)

    summary_articles = _build_summary_articles(summary_changes)

    return AmendmentSummaryInput(
        amendment_name=revision.amendment_name,
        amendment_num=revision.amendment_num,
        enforcement_date=revision.enforcement_date,
        scheduled_enforcement_date=revision.scheduled_enforcement_date,
        enforcement_comment=revision.enforcement_comment,
        is_effective=True,
        articles=summary_articles,
    )


def build_new_law_summary_input(
    law_id: str,
    revision: RevisionHistory,
) -> NewLawSummaryInput:
    """Build AI summary input for a new law."""

    raw = fetch_law_text(
        law_id=law_id,
        law_data_id=revision.law_data_id,
        sub_revision=revision.sub_revision,
    )

    articles = parse_law_text(raw)

    return NewLawSummaryInput(
        enforcement_date=revision.enforcement_date,
        scheduled_enforcement_date=revision.scheduled_enforcement_date,
        enforcement_comment=revision.enforcement_comment,
        articles=articles,
    )


def build_summary_input(
    law_name: str,
    amendments: list[AmendmentSummaryInput],
) -> SummaryInput:
    """Build AI summary input."""

    return SummaryInput(
        law_name=law_name,
        amendments=amendments,
    )


def _get_revision_history(
    law_id: str,
) -> list[RevisionHistory]:
    """Fetch and parse revision history."""

    raw = revision_api.fetch_revisions(law_id)

    return comparison.parse_revision_history(
        raw["result"]["Amendment_History"]
    )


def build_law_summary_input(
    law_group: LawGroup,
) -> LawSummaryInput:

    revisions = _get_revision_history(law_group.law_id)

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
                    and revision.enforcement_date == effective_date
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

    summary_revisions = [
        revision
        for revision in revisions
        if revision.law_data_id in selected_law_data_ids
    ]

    return LawSummaryInput(
        law_id=law_group.law_id,
        law_name=law_group.law_name,
        revisions=summary_revisions,
    )