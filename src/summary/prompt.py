"""Build structured prompts for AI summaries."""

from summary.input import (
    SummaryChange,
    SummaryArticle,
    AmendmentSummaryInput,
    SummaryInput,
    NewLawArticle,
    NewLawSummaryInput,
    PromptSection,
    PromptDocument,
)


# Update Law
SYSTEM_PROMPT = """
You are an expert legal analyst specializing in Japanese legislation.

Your task is to analyze amendments to Japanese laws and regulations and produce accurate, objective, and easy-to-understand summaries.

Always prioritize factual accuracy over completeness.
Do not speculate, infer intent beyond the provided information, or introduce external knowledge.
Base every statement solely on the supplied input.
""".strip()


ROLE_PROMPT = """
You are assisting legal professionals who need to quickly understand how the current law will change through future legislative amendments.

Your role is to explain the practical impact of upcoming amendments from the perspective of policy and institutional changes rather than individual article revisions.
""".strip()


TASK_PROMPT = """
Summarize how the current law will change through the upcoming legislative amendments.

Produce the following fields:

title:
- A concise Japanese title describing the main change.
- About 15–30 Japanese characters.
- Focus on what changes, not the law name.
- Do not repeat the law name unless necessary for clarity.
- Describe the change itself rather than the affected object.

body:
- Begin with a brief overview.
- Organize the summary by major policy or institutional changes, grouping related article amendments into a single topic.
- Each major topic must be written as a separate paragraph.
- Keep each paragraph focused on a single major topic.
- Insert exactly one blank line between paragraphs.
- Explain how the current law will change after the amendments take effect.
- Summarize only the essential changes.
- Focus on the practical effect of the amendment rather than the amendment process.
- Avoid reproducing statutory wording unless necessary.
- Avoid repeating the same information.
- Use only the information provided in the input.
- If the enforcement date of an amendment has not been determined, clearly state that it is not yet determined.
- Do not speculate.
- Write the summary in natural Japanese.
- Do not use Markdown formatting.
""".strip()


# New Law
NEW_LAW_SYSTEM_PROMPT = """
You are an expert legal analyst specializing in Japanese legislation.

Always prioritize factual accuracy over completeness.
Do not speculate or introduce external knowledge.
Base every statement solely on the supplied law text.
""".strip()


NEW_LAW_ROLE_PROMPT = """
You are assisting legal professionals who need to quickly understand newly enacted Japanese laws.
""".strip()


NEW_LAW_TASK_PROMPT = """
Produce the following fields:

title:
- A concise Japanese title describing the main purpose or subject of the law.
- About 15–30 Japanese characters.
- Focus on the substance of the law, not the law name.
- Do not repeat the law name unless necessary for clarity.

body:
- Begin with a brief overview of the purpose of the law.
- Organize the summary by major topics rather than by article number.
- Explain the main systems, obligations, procedures, and other important provisions.
- Do not summarize every article individually.
- Omit minor procedural details unless essential.
- Use only the supplied law text.
- Write the summary in natural Japanese.
- Separate major topics into short paragraphs.
- Insert a blank line between paragraphs.
- Do not use Markdown formatting.
- Do not speculate.
""".strip()


def _build_amendment_section(
    amendment: AmendmentSummaryInput,
) -> PromptSection:
    """Build amendment section."""

    title = amendment.amendment_num

    lines = []

    if amendment.amendment_name:
        lines.append(f"改正法: {amendment.amendment_name}")

    if amendment.enforcement_date:
        lines.append(f"施行日: {amendment.enforcement_date}")
    else:
        lines.append("施行日: 未定")

    body = "\n".join(lines)

    return PromptSection(
        title=title,
        body=body,
    )


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

    sections: list[PromptSection] = []

    for amendment in summary.amendments:
        sections.append(
            _build_amendment_section(amendment)
        )

        sections.extend(
            _build_section(article)
            for article in amendment.articles
        )

    return PromptDocument(
        title=summary.law_name,
        system=SYSTEM_PROMPT,
        role=ROLE_PROMPT,
        task=TASK_PROMPT,
        sections=sections,
    )


def _build_new_law_section(
    article: NewLawArticle,
) -> PromptSection:
    """Build one prompt section for a new law."""

    return PromptSection(
        title=article.article,
        body=article.text,
    )


def build_new_law_prompt_document(
    law_name: str,
    summary: NewLawSummaryInput,
) -> PromptDocument:
    """Build structured prompt for a new law."""

    sections = [
        _build_new_law_section(article)
        for article in summary.articles
    ]

    return PromptDocument(
        title=law_name,
        system=NEW_LAW_SYSTEM_PROMPT,
        role=NEW_LAW_ROLE_PROMPT,
        task=NEW_LAW_TASK_PROMPT,
        sections=sections,
    )