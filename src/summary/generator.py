"""Generate AI summaries for laws."""

from __future__ import annotations

import logging

from models import Law, Summary

from summary.builder import build_summary_input
from summary.prompt import build_prompt_document
from summary.prompt_renderer import render_prompt
from summary.ai_client import summarize

logger = logging.getLogger(__name__)


def generate_summary(
    law_name: str,
    law_no: str,
    changes: list[LawChange],
) -> Summary:
    """Generate an AI summary for a law."""

    summary_input = build_summary_input(
        law_name=law_name,
        law_num=law_no,
        changes=changes,
    )

    prompt_document = build_prompt_document(summary_input)
    prompt = render_prompt(prompt_document)

    return summarize(prompt)


def generate_summaries(laws: list[Law]) -> None:
    """Generate AI summaries for multiple laws."""

    for law in laws:
        try:
            law.summary = generate_summary(
                law_name=law.name,
                law_no=law.law_num,
                changes=law.changes,
            )
        except Exception:
            logger.exception(
                "Failed to generate AI summary for %s (%s).",
                law.law_num,
                law.name,
            )
            law.summary = None