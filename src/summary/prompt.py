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


# Amendment
SYSTEM_PROMPT = """
あなたは日本の法令改正を要約するアシスタントです。

入力として与えられた法令改正情報だけを使用してください。
入力に含まれていない情報を補ったり、外部の知識を用いたりしないでください。

正確性を最優先してください。
推測したり、入力情報から読み取れない改正の目的や意図を推論したりしないでください。

出力は指定されたスキーマに従ってください。
""".strip()


ROLE_PROMPT = """
あなたは、日本の法令改正について、読者に分かりやすく伝える編集者です。
""".strip()


TASK_PROMPT = """
入力に含まれる改正によって、この法令がどのように変わるのかを要約してください。

以下の項目を出力してください。

title:
- 改正の主な内容を表す簡潔な日本語タイトルにしてください。
- 15～30文字程度を目安としてください。
- 法令名ではなく、何が変わるのかに焦点を当ててください。
- 明確化のために必要な場合を除き、法令名を繰り返さないでください。

body:
- 改正全体の内容を、分かりやすく説明してください。
- 関連する改正内容を、意味のあるテーマごとに整理してください。
- 複数の改正を扱う場合は、改正ごとに説明するのではなく、関連する内容をまとめてください。
- 入力に含まれる主要な変更を漏らさないでください。
- 重要な制度変更や実務上意味のある変更を、過度に省略しないでください。
- 重要な変更と、それによって何が変わるのかに重点を置いてください。
- 必要な場合を除き、条文の文言をそのまま再現しないでください。
- 同じ内容を繰り返さないでください。
- 自然で読みやすい日本語で記述してください。
- 読み易くするため、適切に段落を分けてください。
""".strip()


# New Law
NEW_LAW_SYSTEM_PROMPT = """
あなたは日本の新しい法令を要約するアシスタントです。

入力として与えられた法令本文だけを使用してください。
入力に含まれていない情報を補ったり、外部の知識を用いたりしないでください。

正確性を最優先してください。
推測したり、入力情報から読み取れない法令の目的や意図を推論したりしないでください。

出力は指定されたスキーマに従ってください。
""".strip()


NEW_LAW_ROLE_PROMPT = """
あなたは、日本の新しい法令について、読者に分かりやすく伝える編集者です。
""".strip()


NEW_LAW_TASK_PROMPT = """
入力に含まれる法令が、どのような制度を定め、何を求めているのかを要約してください。

以下の項目を出力してください。

title:
- 法令の主な内容を表す簡潔な日本語タイトルにしてください。
- 15～30文字程度を目安としてください。
- 法令名ではなく、法令の内容に焦点を当ててください。
- 明確化のために必要な場合を除き、法令名を繰り返さないでください。

body:
- 法令全体の内容を、分かりやすく説明してください。
- 関連する規定を、意味のあるテーマごとに整理してください。
- 条文ごとに順番に説明するのではなく、関連する内容をまとめてください。
- 法令が定める主要な制度、義務、手続、対象などを説明してください。
- 入力に含まれる主要な内容を漏らさないでください。
- 重要な制度や実務上意味のある内容を、過度に省略しないでください。
- 必要な場合を除き、条文の文言をそのまま再現しないでください。
- 同じ内容を繰り返さないでください。
- 入力に含まれる情報だけを使用してください。
- 自然で読みやすい日本語で記述してください。
- 読み易くするため、適切に段落を分けてください。
""".strip()


def _build_amendment_section(
    amendment: AmendmentSummaryInput,
) -> PromptSection:
    """Build amendment section."""

    lines = []

    if amendment.amendment_name:
        lines.append(f"改正法: {amendment.amendment_name}")

    if amendment.enforcement_date:
        lines.append(f"施行日: {amendment.enforcement_date}")
    elif amendment.scheduled_enforcement_date:
        lines.append(
            f"施行予定日: {amendment.scheduled_enforcement_date}"
        )
    else:
        lines.append("施行日: 未定")

    if amendment.enforcement_comment:
        lines.append(
            f"施行日備考: {amendment.enforcement_comment}"
        )

    if amendment.table_changes:
        lines.append("")
        lines.append("別表変更:")
        for table_change in amendment.table_changes:
            lines.append(f"- {table_change.name}")

    return PromptSection(
        title=amendment.amendment_num,
        body="\n".join(lines),
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

    if change.provision_text:
        lines.append("")
        lines.append("＜条文本文＞")
        lines.append(change.provision_text)

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