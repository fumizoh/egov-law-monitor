from models import SummaryInput


def build_prompt(summary: SummaryInput) -> str:
    """Build prompt for AI summary."""

    lines: list[str] = []

    lines.append("あなたは法令改正を要約する専門家です。")
    lines.append("")
    lines.append("以下の法令改正を簡潔に要約してください。")
    lines.append("")
    lines.append(f"法令名: {summary.law_name}")
    lines.append(f"法令番号: {summary.law_num}")
    lines.append("")

    for article in summary.articles:

        lines.append(f"## {article.article}")

        for change in article.changes:

            paragraph = (
                f" 第{change.paragraph}項"
                if change.paragraph
                else ""
            )

            lines.append(
                f"- {change.change_type}{paragraph}"
            )

            if change.before:
                lines.append("Before:")
                lines.append(change.before)

            if change.after:
                lines.append("After:")
                lines.append(change.after)

            lines.append("")

    return "\n".join(lines)