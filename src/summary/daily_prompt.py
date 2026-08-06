"""Build structured prompts for Daily Summary."""

from summary.input import (
    DailySummaryInput,
    PromptDocument,
    PromptSection,
)

from models import Summary


SYSTEM_PROMPT = """
You are an expert legal analyst specializing in Japanese legislation.

Your task is to summarize the daily legal updates in Japan based only on the supplied law summaries.

Always prioritize factual accuracy over completeness.
Do not speculate, infer intent beyond the provided information, or introduce external knowledge.
Base every statement solely on the supplied input.
""".strip()


ROLE_PROMPT = """
You are assisting legal professionals who need to quickly understand today's overall legal updates.

Your summary will be displayed at the top of the website as a daily overview before users browse individual laws.

Your role is to organize multiple law summaries into a concise daily overview.
""".strip()


TASK_PROMPT = """
Summarize today's legal updates.

Requirements:
- Begin with a brief overview.
- Explain today's overall legal changes rather than simply listing each law.
- Highlight only the most significant changes.
- Group related legal changes into major topics whenever appropriate.
- Avoid repeating the same information.
- Keep the summary concise (about 200–300 Japanese characters).
- Use only the supplied law summaries.
- Write the summary in natural Japanese.
- Do not use Markdown formatting.
- Do not speculate.
""".strip()


def _build_summary_section(
    summary: Summary,
) -> PromptSection:
    """Build one law summary section."""

    return PromptSection(
        title=summary.title,
        body=summary.body,
    )


def build_daily_prompt_document(
    summary: DailySummaryInput,
) -> PromptDocument:
    """Build structured prompt document for Daily Summary."""

    sections = [
        _build_summary_section(item)
        for item in summary.summaries
    ]

    return PromptDocument(
        title=f"{summary.date} 法令改正サマリー",
        system=SYSTEM_PROMPT,
        role=ROLE_PROMPT,
        task=TASK_PROMPT,
        sections=sections,
    )