"""Generate AI summaries for laws."""

from __future__ import annotations

import logging
from dataclasses import dataclass

import hashlib
import json

import law_change
import table_change
import comparison
import toc_parser

from sources import toc_api
from sources import compare_api

from summary import builder
from summary import prompt
from summary import gemini_client
from summary import prompt_renderer

from models import (
    RevisionHistory,
    LawSummaryInput,
    SummaryResponse,
    LawSummary,
)

from summary.input import (
    AmendmentSummaryInput,
    PromptDocument,
)

@dataclass(slots=True)
class RevisionSummaryResult:
    law_summary: LawSummary
    revision_hash: str
    amendment_name: str | None
    comparison_effective_date: str | None

logger = logging.getLogger(__name__)

MAX_XML_CHANGES = 50

def _build_amendment_input(
    revision: RevisionHistory,
) -> AmendmentSummaryInput | None:

    compare_json = compare_api.fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    compare_result = comparison.parse_compare_result(compare_json)

    if compare_result is None:
        return None

    toc_json = toc_api.fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = toc_parser.parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    changes = law_change.build_law_changes(
        compare_result,
        index,
    )

    table_changes = table_change.build_table_changes(
        compare_result,
        index,
    )

    amendment_summary_input = builder.build_amendment_summary_input(
        revision=revision,
        changes=changes,
        table_changes=table_changes,
    )

    return amendment_summary_input

def _generate_summary(
    prompt_document: PromptDocument,
) -> SummaryResponse:

    prompt = prompt_renderer.render_prompt(prompt_document)

    return gemini_client.summarize(prompt)

def _generate_new_law_summary(
    law_id: str,
    law_name: str,
    revision: RevisionHistory,
) -> SummaryResponse:

    summary_input = builder.build_new_law_summary_input(
        law_id=law_id,
        revision=revision,
    )

    prompt_document = prompt.build_new_law_prompt_document(
        law_name=law_name,
        summary=summary_input,
    )

    return _generate_summary(prompt_document)

def _generate_law_summary(
    summary_input: LawSummaryInput,
) -> SummaryResponse | None:

    law_name = summary_input.law_name
    revisions = summary_input.revisions

    # New law
    if (
        len(revisions) == 1
        and revisions[0].is_new_law
    ):
        return _generate_new_law_summary(
            law_id=summary_input.law_id,
            law_name=law_name,
            revision=revisions[0],
        )

    amendments: list[AmendmentSummaryInput] = []
    amendment_revisions: list[RevisionHistory] = []

    for revision in revisions:

        try:
            amendment = _build_amendment_input(
                revision=revision,
            )
        except compare_api.CompareDataNotFoundError as exc:
            logger.warning(
                "Compare data unavailable: law=%s, law_data_id=%s, sub_revision=%s, error=%s",
                law_name,
                revision.law_data_id,
                revision.sub_revision,
                exc,
            )
            continue

        if amendment is not None:
            amendments.append(amendment)
            amendment_revisions.append(revision)

    if not amendments:
        return None

    change_count = sum(
        len(article.changes)
        for amendment in amendments
        for article in amendment.articles
    )

    if change_count <= MAX_XML_CHANGES:
        for revision, amendment in zip(
            amendment_revisions,
            amendments,
        ):
            builder.enrich_amendment_summary_input(
                law_id=summary_input.law_id,
                revision=revision,
                amendment=amendment,
            )

    prompt_input = builder.build_summary_input(
        law_name=law_name,
        amendments=amendments,
    )

    prompt_document = prompt.build_prompt_document(prompt_input)

    return _generate_summary(prompt_document)

def _calculate_revision_hash(
    revisions: list[RevisionHistory],
) -> str:
    """Calculate a stable hash for a revision set."""

    revision_data = sorted(
        (
            revision.law_data_id,
            revision.sub_revision,
        )
        for revision in revisions
    )

    payload = json.dumps(
        revision_data,
        ensure_ascii=False,
        separators=(",", ":"),
    )

    return hashlib.sha256(
        payload.encode("utf-8")
    ).hexdigest()

def _calculate_single_revision_hash(
    revision: RevisionHistory,
) -> str:
    """Calculate a stable hash for one revision."""

    return _calculate_revision_hash([revision])

def _get_comparison_effective_date(
    revision: RevisionHistory,
) -> str | None:
    """Get the effective date of the revision used for comparison."""

    compare_json = compare_api.fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    compare_result = comparison.parse_compare_result(compare_json)

    if compare_result is None:
        return None

    return (
        compare_result.old.enforcement_date
        or compare_result.old.scheduled_enforcement_date
    )


def get_revision_hash_for_revision(
    law_id: str,
    law_name: str,
    effective_date: str,
    law_data_id: int,
    sub_revision: str,
) -> str:
    """Get the current revision hash for one specific revision."""

    summary_input = builder.build_law_summary_input_for_revision(
        law_id=law_id,
        law_name=law_name,
        effective_date=effective_date,
        law_data_id=law_data_id,
        sub_revision=sub_revision,
    )

    return _calculate_single_revision_hash(
        summary_input.revisions[0]
    )

def get_revision_metadata_for_revision(
    law_id: str,
    law_name: str,
    effective_date: str,
    law_data_id: int,
    sub_revision: str,
) -> tuple[str | None, str | None]:
    """Get metadata for one revision without generating an AI summary."""

    summary_input = builder.build_law_summary_input_for_revision(
        law_id=law_id,
        law_name=law_name,
        effective_date=effective_date,
        law_data_id=law_data_id,
        sub_revision=sub_revision,
    )

    revision = summary_input.revisions[0]

    # 新規制定には比較対象となる旧法令がないため、
    # Compare APIを呼び出さない。
    if revision.is_new_law:
        return None, None

    comparison_effective_date = _get_comparison_effective_date(
        revision
    )

    return revision.amendment_name, comparison_effective_date

def generate_for_revision(
    law_id: str,
    law_name: str,
    effective_date: str,
    law_data_id: int,
    sub_revision: str,
) -> RevisionSummaryResult | None:
    """Generate an AI summary for one specific revision."""

    summary_input = builder.build_law_summary_input_for_revision(
        law_id=law_id,
        law_name=law_name,
        effective_date=effective_date,
        law_data_id=law_data_id,
        sub_revision=sub_revision,
    )

    revision = summary_input.revisions[0]

    revision_hash = _calculate_single_revision_hash(
        revision
    )

    comparison_effective_date = None

    # 新規制定には比較対象となる旧法令がないため、
    # Compare APIを呼び出さず、新規制定用の要約処理へ進む。
    if not revision.is_new_law:
        comparison_effective_date = _get_comparison_effective_date(
            revision
        )

    response = _generate_law_summary(summary_input)

    if response is None:
        return None

    return RevisionSummaryResult(
        law_summary=LawSummary(
            summary_input=summary_input,
            response=response,
        ),
        revision_hash=revision_hash,
        amendment_name=revision.amendment_name,
        comparison_effective_date=comparison_effective_date,
    )
