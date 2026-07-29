"""Generate AI summaries for laws."""

from __future__ import annotations

import logging

from datetime import date

from models import RevisionHistory, SummaryResponse
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

# DEBUG
from pathlib import Path
from collections import Counter
from pprint import pprint
import json
# DEBUG

logger = logging.getLogger(__name__)


def build_amendment_summary(
    law_name: str,
    revision: RevisionHistory,
) -> AmendmentSummaryInput | None:

    # DEBUG
    print(
        "Summary:",
        revision.amendment_num,
        revision.enforcement_date,
        revision.law_data_id,
        revision.sub_revision,
    )
    # DEBUG

    compare_json = fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    # DEBUG
    # print(json.dumps(compare_json, indent=2)[:1000])
    # DEBUG

    if compare_json is None:

        # DEBUG
        print(
            f"Skip compare: "
            f"{revision.amendment_num} "
            f"{revision.enforcement_date}"
        )
        # DEBUG

        return None

    compare_result = parse_compare_result(compare_json)

    if compare_result is None:

        # DEBUG
        print(
            f"Skip summary: {revision.amendment_num} "
            f"({revision.enforcement_date})"
        )
        return None
        # DEBUG

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

    # DEBUG
    counter = Counter(change.change_type for change in changes)
    print(f"{revision.amendment_num}: {counter}")
    # DEBUG

    amendment_summary_input = build_amendment_summary_input(
        revision=revision,
        changes=changes,
    )

    return(amendment_summary_input)


def generate_future_summary(
    law_name: str,
    revisions: list[RevisionHistory],
) -> SummaryResponse | None:

    # DEBUG
    print(len(revisions), "summary revisions")
    # DEBUG

    amendments: list[AmendmentSummaryInput] = []

    # for revision in revisions:
    for i, revision in enumerate(revisions, start=1):

        # DEBUG
        print(
            f"[{i}/{len(revisions)}] "
            f"{revision.amendment_num}"
        )
        # DEBUG

        amendment = build_amendment_summary(
            law_name,
            revision,
        )

        if amendment is not None:
            amendments.append(amendment)

    if not amendments:
        return None

    # DEBUG
    print("Generating Gemini summary...")
    # DEBUG

    summary_input = build_summary_input(
        law_name=law_name,
        amendments=amendments,
    )

    prompt_document = build_prompt_document(summary_input)
    prompt = render_prompt(prompt_document)

    # DEBUG
    Path("prompt.md").write_text(
        prompt,
        encoding="utf-8",
    )
    # DEBUG

    return summarize(prompt)
