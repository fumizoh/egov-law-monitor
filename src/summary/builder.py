"""Build AI summary input."""

from models import LawChange

from summary.input import (
    SummaryChange,
    SummaryArticle,
    SummaryInput,
)


def _build_summary_changes(
    changes: list[LawChange],
) -> list[SummaryChange]:
    """Build summary changes from law changes."""

    summary_changes: list[SummaryChange] = []

    for change in changes:
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


def build_summary_input(
    law_name: str,
    law_num: str,
    changes: list[LawChange],
) -> SummaryInput:
    """Build AI summary input."""

    summary_changes = _build_summary_changes(changes)

    summary_articles = _build_summary_articles(summary_changes)

    return SummaryInput(
        law_name=law_name,
        law_num=law_num,
        articles=summary_articles,
    )
