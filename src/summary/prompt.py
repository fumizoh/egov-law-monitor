"""Build structured prompts for AI summaries."""

from summary.input import (
    SummaryChange,
    SummaryArticle,
    SummaryInput,
    PromptSection,
    PromptDocument,
)


SYSTEM_PROMPT = """
You are an expert legal analyst specializing in Japanese legislation.

Your task is to analyze amendments to Japanese laws and regulations and produce accurate, objective, and easy-to-understand summaries.

Always prioritize factual accuracy over completeness.
Do not speculate, infer intent beyond the provided information, or introduce external knowledge.
Base every statement solely on the supplied input.
""".strip()

ROLE_PROMPT = """
You are assisting legal professionals who need to quickly understand the substance of legislative amendments.

Your role is to identify the practical meaning of the amendments and explain them from the perspective of policy and institutional changes rather than individual article revisions.
""".strip()

TASK_PROMPT = """
Summarize the legislative amendments.

Requirements:
- Begin with a brief overview.
- Organize the summary by major policy or institutional changes.
- Treat related article amendments as a single topic.
- Merge related amendments into concise explanations.
- Avoid repeating the same information.
- Summarize only the essential changes.
- Do not explain the amendments in detail.
- Use concise bullet points whenever possible.
- Use only the information provided in the input.
- Do not speculate.
- Write the summary in Japanese.
""".strip()


def _build_change_body(change: SummaryChange) -> str:
    """Build prompt body for one change."""

    lines: list[str] = []

    lines.append(f"【{change.location.label}】")

    if change.before:
        lines.append("")
        lines.append("＜改正前＞")
        lines.append(change.before)

    if change.after:
        lines.append("")
        lines.append("＜改正後＞")
        lines.append(change.after)

    return "\n".join(lines)


def _build_section(article: SummaryArticle) -> PromptSection:
    """Build one prompt section."""

    body = "\n\n".join(
        _build_change_body(change)
        for change in article.changes
    )

    return PromptSection(
        title=article.article,
        body=body,
    )


def build_prompt_document(
    summary: SummaryInput,
) -> PromptDocument:
    """Build a structured prompt document."""

    sections = [
        _build_section(article)
        for article in summary.articles
    ]

    return PromptDocument(
        title=summary.law_name,
        system=SYSTEM_PROMPT,
        role=ROLE_PROMPT,
        task=TASK_PROMPT,
        sections=sections,
    )