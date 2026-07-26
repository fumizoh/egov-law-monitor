"""Generate AI summaries for laws."""

from __future__ import annotations

import logging

from datetime import date

from models import RevisionHistory, Summary
from summary.input import AmendmentSummaryInput
from sources.compare_api import fetch_compare
from comparison import parse_compare_result
from sources.toc_api import fetch_law_toc
from toc_parser import parse_toc
from lawchange_builder import build_law_changes
from summary.builder import build_amendment_summary_input, build_summary_input
from summary.prompt import build_prompt_document
from summary.prompt_renderer import render_prompt
from summary.gemini_client import summarize

logger = logging.getLogger(__name__)


def build_amendment_summary(
    law_name: str,
    revision: RevisionHistory,
) -> AmendmentSummaryInput:

    compare_json = fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    compare_result = parse_compare_result(compare_json)

    toc_json = fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    changes = build_law_changes(
        compare_result,
        index,
    )

    amendment_summary_input = build_amendment_summary_input(
        revision=revision,
        changes=changes,
    )

    return(amendment_summary_input)


def is_effective(
    revision: RevisionHistory,
) -> bool:
    """Return True if the revision is effective today."""

    if revision.enforcement_date is None:
        return False

    return (
        date.fromisoformat(revision.enforcement_date)
        <= date.today()
    )


def generate_law_summary(
    law_name: str,
    revisions: list[RevisionHistory],
) -> Summary | None:

    amendments = [
        build_amendment_summary(
            law_name,
            revision,
        )
        for revision in revisions
        if is_effective(revision)
    ]

    summary_input = build_summary_input(
        law_name=law_name,
        amendments=amendments,
    )

    prompt_document = build_prompt_document(summary_input)
    prompt = render_prompt(prompt_document)

    return summarize(prompt)


def generate_summary(
    law_name: str,
    revision: RevisionHistory,
) -> Summary | None:

    compare_json = fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    compare_result = parse_compare_result(compare_json)

    toc_json = fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    changes = build_law_changes(
        compare_result,
        index,
    )

    summary_input = build_summary_input(
        law_name=law_name,
        law_num=compare_result.new.law_num,
        revision=revision,
        changes=changes,
    )

    prompt_document = build_prompt_document(summary_input)
    prompt = render_prompt(prompt_document)

    return summarize(prompt)
