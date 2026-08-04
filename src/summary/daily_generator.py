"""Generate Daily AI summary."""

from __future__ import annotations

from models import SummaryResponse
from summary.input import DailySummaryInput, PromptDocument
from summary.daily_prompt import build_daily_prompt_document
from summary.prompt_renderer import render_prompt
from summary.gemini_client import summarize

# DEBUG
from pathlib import Path


def _generate_summary(
    prompt_document: PromptDocument,
) -> SummaryResponse:
    """Generate summary from prompt."""

    prompt = render_prompt(prompt_document)

    # DEBUG
    Path("daily_prompt.md").write_text(
        prompt,
        encoding="utf-8",
    )

    return summarize(prompt)


def generate_daily_summary_response(
    summary_input: DailySummaryInput,
) -> SummaryResponse:
    """Generate Daily Summary."""

    prompt_document = build_daily_prompt_document(
        summary_input,
    )

    return _generate_summary(prompt_document)