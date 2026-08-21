"""Generate AI summaries for laws."""

from __future__ import annotations

import logging

import law_change
import table_change
import comparison
import toc_parser
import storage

from sources import toc_api
from sources import compare_api

from summary import builder
from summary import prompt
from summary import gemini_client
from summary import prompt_renderer
from summary import log

from models import (
    LawGroup,
    RevisionHistory,
    LawSummaryInput,
    SummaryResponse,
    LawSummary,
    AiSummaryLog,
)

from summary.input import (
    AmendmentSummaryInput,
    PromptDocument,
)


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

        amendment = _build_amendment_input(
            revision=revision,
        )

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


def generate(
    law_groups: list[LawGroup],
    storage_paths: storage.StoragePaths = storage.DEFAULT_STORAGE,
) -> tuple[
    list[LawSummary],
    list[AiSummaryLog],
]:

    cached_summaries = storage.load_law_summaries(
        paths=storage_paths,
    )

    law_summaries: list[LawSummary] = []

    logs: list[AiSummaryLog] = []

    for law_group in law_groups:

        summary_input = builder.build_law_summary_input(
            law_group,
        )

        previous_summary = cached_summaries.get(
            summary_input.law_id,
        )

        reused = (
            previous_summary is not None
            and previous_summary.summary_input == summary_input
        )

        if reused:
            logger.info(
                "Reuse summary: %s",
                summary_input.law_name,
            )

            law_summary = previous_summary

        else:
            logger.info(
                "Generate summary: %s",
                summary_input.law_name,
            )

            response = _generate_law_summary(
                summary_input,
            )

            if response is None:
                logger.info(
                    "FAILED: %s",
                    summary_input.law_name,
                )
            else:
                logger.info(
                    "OK: %s",
                    summary_input.law_name,
                )

            if response is None:
                continue

            law_summary = LawSummary(
                summary_input=summary_input,
                response=response,
            )

            logs.append(
                log.create_law_summary_log(
                    law_summary=law_summary,
                )
            )            

        law_summaries.append(
            law_summary,
        )

    return (
        law_summaries,
        logs,
    )