"""Build AI summary input."""

from __future__ import annotations

from pathlib import Path

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

from sources.lawtext_api import fetch_law_text
from sources.revision import get_revision_history

from lawtext_parser import parse_law_text

from law_group import match_revisions

from sources import egov_xml


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
                provision_text=None,
            )
        )

    return summary_changes


def _enrich_summary_changes(
    changes: list[SummaryChange],
    xml_path: Path,
) -> None:
    """Enrich summary changes with XML provision text."""

    for change in changes:
        if change.change_type != "added":
            continue

        if change.location.paragraph or change.location.item:
            continue

        _, provision_text = egov_xml.get_provision_text(
            xml_path,
            change.location,
        )

        change.provision_text = provision_text


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
    table_changes: list[TableChange],
) -> AmendmentSummaryInput:
    """Build AI summary input for one amendment."""

    summary_changes = _build_summary_changes(changes)

    summary_articles = _build_summary_articles(summary_changes)

    return AmendmentSummaryInput(
        amendment_name=revision.amendment_name,
        amendment_num=revision.amendment_num,
        enforcement_date=revision.enforcement_date,
        scheduled_enforcement_date=revision.scheduled_enforcement_date,
        enforcement_comment=revision.enforcement_comment,
        articles=summary_articles,
        table_changes=table_changes,
    )


def enrich_amendment_summary_input(
    law_id: str,
    revision: RevisionHistory,
    amendment: AmendmentSummaryInput,
) -> None:
    """Enrich amendment summary input with XML provision text."""

    xml_path = egov_xml.find_xml(
        law_id,
        revision,
    )

    changes = [
        change
        for article in amendment.articles
        for change in article.changes
    ]

    _enrich_summary_changes(
        changes,
        xml_path,
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


def build_law_summary_input(
    law_group: LawGroup,
) -> LawSummaryInput:

    revisions = get_revision_history(
        law_group.law_id,
    )

    summary_revisions = match_revisions(
        law_group,
        revisions,
    )

    return LawSummaryInput(
        law_id=law_group.law_id,
        law_name=law_group.law_name,
        revisions=summary_revisions,
    )