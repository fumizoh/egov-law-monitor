"""Build AI summary input."""

from models import LawChange, RevisionHistory

from summary.input import (
    SummaryChange,
    SummaryArticle,
    AmendmentSummaryInput,
    SummaryInput,
)


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


def build_summary_input(
    law_name: str,
    amendments: list[AmendmentSummaryInput],
) -> SummaryInput:
    """Build AI summary input."""

    return SummaryInput(
        law_name=law_name,
        amendments=amendments,
    )
