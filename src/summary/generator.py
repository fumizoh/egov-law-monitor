"""Generate AI summaries for laws."""

from __future__ import annotations

import logging
from datetime import date

import law_change

from models import RevisionHistory, SummaryResponse
from summary.input import AmendmentSummaryInput, PromptDocument
from sources.compare_api import fetch_compare
from comparison import parse_compare_result
from sources.toc_api import fetch_law_toc
from toc_parser import parse_toc
from summary.builder import build_amendment_summary_input, build_summary_input, build_new_law_summary_input
from summary.prompt import build_prompt_document, build_new_law_prompt_document
from summary.prompt_renderer import render_prompt
from summary.gemini_client import summarize

# DEBUG
from pathlib import Path
from collections import Counter
from pprint import pprint
import json
# DEBUG

logger = logging.getLogger(__name__)


def _build_amendment_summary_input(
    revision: RevisionHistory,
) -> AmendmentSummaryInput | None:

    compare_json = fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    if compare_json is None:

        # DEBUG
        print(
            f"Skip compare: "
            f"{revision.amendment_num} "
            f"{revision.enforcement_date}"
        )

        return None

    compare_result = parse_compare_result(compare_json)

    if compare_result is None:

        # DEBUG
        print(
            f"Skip summary: {revision.amendment_num} "
            f"({revision.enforcement_date})"
        )
        return None

    toc_json = fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    changes = law_change.build_law_changes(
        compare_result,
        index,
    )

    # DEBUG
    # counter = Counter(change.change_type for change in changes)
    # print(f"{revision.amendment_num}: {counter}")

    amendment_summary_input = build_amendment_summary_input(
        revision=revision,
        changes=changes,
    )

    return (amendment_summary_input)


def _generate_summary(
    prompt_document: PromptDocument,
) -> SummaryResponse:

    prompt = render_prompt(prompt_document)

    # DEBUG
    Path("prompt.md").write_text(
        prompt,
        encoding="utf-8",
    )

    return summarize(prompt)


def generate_amendment_summary(
    law_name: str,
    revision: RevisionHistory,
) -> SummaryResponse | None:

    # DEBUG
    print(
        "Summary:",
        revision.amendment_num,
        revision.enforcement_date,
        revision.law_data_id,
        revision.sub_revision,
    )

    amendment_summary_input = _build_amendment_summary_input(revision)

    # DEBUG
    print("Generating Gemini summary...")

    summary_input = build_summary_input(
        law_name=law_name,
        amendments=[amendment_summary_input],
    )

    prompt_document = build_prompt_document(summary_input)

    return _generate_summary(prompt_document)


def generate_new_law_summary(
    law_id: str,
    law_name: str,
    revision: RevisionHistory,
) -> SummaryResponse:

    logger.info(
        "Generating new law summary: %s",
        law_name,
    )

    # DEBUG
    print("Generating Gemini summary...")

    summary_input = build_new_law_summary_input(
        law_id=law_id,
        revision=revision,
    )

    prompt_document = build_new_law_prompt_document(
        law_name=law_name,
        summary=summary_input,
    )

    return _generate_summary(prompt_document)


def generate_future_summary(
    law_name: str,
    summary_revisions: list[SummaryRevision],
) -> SummaryResponse | None:

    # DEBUG
    print(len(summary_revisions), "summary revisions")

    amendments: list[AmendmentSummaryInput] = []

    for summary_revision in summary_revisions:

    # DEBUG
    # for i, summary_revision in enumerate(summary_revisions, start=1):

        # print(
        #     f"[{i}/{len(revisions)}] "
        #     f"{revision.amendment_num}"
        # )

        revision = summary_revision.revision

        amendment = _build_amendment_summary_input(revision)

        if amendment is not None:
            amendments.append(amendment)

    if not amendments:
        return None

    # DEBUG
    print("Generating Gemini summary...")

    summary_input = build_summary_input(
        law_name=law_name,
        amendments=amendments,
    )

    prompt_document = build_prompt_document(summary_input)

    return _generate_summary(prompt_document)