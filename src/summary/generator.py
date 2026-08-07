"""Generate AI summaries for laws."""

from __future__ import annotations

import logging
from datetime import date

import law_change

from models import RevisionHistory, LawSummaryInput, SummaryResponse
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


def generate_new_law_summary(
    law_id: str,
    law_name: str,
    revision: RevisionHistory,
) -> SummaryResponse:

    # DEBUG
    print("Generating new law summary...")

    summary_input = build_new_law_summary_input(
        law_id=law_id,
        revision=revision,
    )

    prompt_document = build_new_law_prompt_document(
        law_name=law_name,
        summary=summary_input,
    )

    return _generate_summary(prompt_document)


def generate_law_summary(
    summary_input: LawSummaryInput,
) -> SummaryResponse | None:

    law_name = summary_input.law_name
    revisions = summary_input.revisions

    # DEBUG
    print(
        summary_input.law_name,
        len(revisions),
        "summary revisions"
    )

    # New law
    if (
        len(revisions) == 1
        and revisions[0].is_new_law
    ):
        return generate_new_law_summary(
            law_id=summary_input.law_id,
            law_name=law_name,
            revision=revisions[0],
        )

    amendments: list[AmendmentSummaryInput] = []

    for revision in revisions:

        amendment = _build_amendment_summary_input(revision)

        if amendment is not None:
            amendments.append(amendment)

    if not amendments:
        return None

    # DEBUG
    print("Generating Gemini summary...")

    prompt_input = build_summary_input(
        law_name=law_name,
        amendments=amendments,
    )

    prompt_document = build_prompt_document(prompt_input)

    return _generate_summary(prompt_document)