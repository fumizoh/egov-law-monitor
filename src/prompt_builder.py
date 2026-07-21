"""Build structured prompts for AI summaries."""

from models import (
    PromptDocument,
    PromptSection,
    SummaryArticle,
    SummaryChange,
    SummaryInput,
)


def build_change_body(change: SummaryChange) -> str:
    """Build prompt body for one change."""

    lines: list[str] = []

    header = change.change_type
    if change.paragraph:
        header += f" (Paragraph {change.paragraph})"

    lines.append(header)

    if change.before:
        lines.append("")
        lines.append("Before:")
        lines.append(change.before)

    if change.after:
        lines.append("")
        lines.append("After:")
        lines.append(change.after)

    return "\n".join(lines)


def build_section(article: SummaryArticle) -> PromptSection:
    """Build one prompt section."""

    body = "\n\n".join(
        build_change_body(change)
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
        build_section(article)
        for article in summary.articles
    ]

    return PromptDocument(
        title=f"{summary.law_name} 改正要約",
        system=(
            "あなたは法令改正を要約する専門家です。"
            "法令改正の内容を正確かつ簡潔に要約してください。"
        ),
        sections=sections,
    )