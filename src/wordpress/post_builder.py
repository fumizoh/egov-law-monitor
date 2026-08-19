"""Build WordPress post content."""

import markdown

from models import (
    WPPost,
    WPLaw,
    WPLawGroup,
    WPLawRevision,
)


def _render_summary_body(body: str) -> str:
    """Render AI summary body as HTML."""
    return markdown.markdown(body)


def _format_date(date: str) -> str:
    """Format YYYYMMDD as YYYY年MM月DD日."""

    return (
        f"{date[:4]}年"
        f"{date[4:6]}月"
        f"{date[6:8]}日"
    )


def build_post_title(post: WPPost) -> str:
    """Build WordPress post title."""

    return (
        f"{post.date[:4]}年"
        f"{post.date[4:6]}月"
        f"{post.date[6:8]}日の法令更新"
    )


def _build_dashboard(
    post: WPPost,
) -> str:
    """Build dashboard HTML."""

    statistics = post.statistics

    law_items = []

    for group in post.law_groups:
        law_items.append(
            f'<li class="egov-dashboard-law-group">'
            f'<strong>{group.law_type}</strong></li>'
        )

        for law in group.laws:
            count = len(law.wp_revisions)

            law_name = (
                law.law_name
                if count == 1
                else f"{law.law_name}（{count}件）"
            )

            summary_html = ""

            if law.summary:
                summary_html = (
                    f'<p class="egov-dashboard-summary-title">'
                    f'{law.summary.title}'
                    f'</p>'
                )

            law_items.append(
                f'<li>'
                f'<a href="#law-{law.law_id}">'
                f'{law_name}</a>'
                f'{summary_html}'
                f'</li>'
            )

    law_items_html = "".join(law_items)

    law_type_items = []

    for name, count in statistics.law_type.items():
        law_count = statistics.law_count.get(name, 0)

        law_type_items.append(
            f"""
<li>
    {name}：{count}件（{law_count}法令）
</li>
"""
        )

    law_type_html = "".join(law_type_items)

    return f"""
<section class="egov-dashboard">

    <h2 class="egov-dashboard-title">
        法令更新ダッシュボード
    </h2>

    <div class="egov-dashboard-statistics">
        <p>
            e-Gov更新日：{_format_date(statistics.last_update)}
        </p>
        <p>
            更新件数：{statistics.update_count}件
        </p>
        <p>
            更新法令数：{statistics.updated_law_count}法令
        </p>
    </div>

    <div class="egov-dashboard-law-types">
        <h3>法令種別</h3>
        <ul>
            {law_type_html}
        </ul>
    </div>

    <div class="egov-dashboard-laws">
        <h3>今回更新された法令</h3>
        <ul>
            {law_items_html}
        </ul>
    </div>

</section>
"""


def _build_revision(
    revision: WPLawRevision,
) -> str:
    """Build HTML for one revision."""

    effective_date = (
        revision.enforcement_date
        or revision.scheduled_enforcement_date
    )

    if effective_date:
        if revision.pending:
            effective_info = f"{effective_date}（未施行）"
        else:
            effective_info = effective_date
    else:
        effective_info = "施行日未定"

    enforcement_comment = ""

    if revision.enforcement_comment:
        enforcement_comment = (
            f'<span class="egov-effective-comment">'
            f"{revision.enforcement_comment}"
            f"</span>"
        )

    if revision.amendment_id is None:
        amendment_html = "新規制定"

    elif revision.compare_url:
        amendment_html = (
            f'<a href="{revision.compare_url}" '
            f'target="_blank" rel="noopener noreferrer">'
            f"{revision.amendment_name}　条文比較"
            f"</a>"
        )

    else:
        amendment_html = revision.amendment_name or ""

    return f"""
<div class="egov-update-history">
    <div class="egov-effective-info">
        <span class="egov-effective-date">{effective_info}</span>{enforcement_comment}
    </div>

    <p class="egov-amend-name">
        {amendment_html}
    </p>
</div>
"""


def _build_law_section(
    law: WPLaw,
) -> str:
    """Build HTML for one law."""

    summary_html = ""

    if law.summary:
        summary_html = f"""
<div class="egov-ai-summary">
    <h3 class="egov-summary-title">
        🤖 {law.summary.title}
    </h3>
    <div class="egov-summary-body">
        {_render_summary_body(law.summary.body)}
    </div>
</div>
"""

    revisions_html = "".join(
        _build_revision(revision)
        for revision in law.wp_revisions
    )

    pending_count = sum(
        revision.pending
        for revision in law.wp_revisions
    )

    active_count = (
        len(law.wp_revisions)
        - pending_count
    )

    return f"""
<section id="law-{law.law_id}" class="egov-law-card">
    <h2 class="egov-law-name">{law.law_name}</h2>

    <p class="egov-law-type">
        <strong>種別</strong> {law.law_type}
    </p>

    {summary_html}

    <details class="egov-update-details">
        <summary>
            今回の更新（{len(law.wp_revisions)}件）
        </summary>

        <div class="egov-update-count">
            <strong>施行済</strong> {active_count}件 / <strong>未施行</strong> {pending_count}件
        </div>

        {revisions_html}
    </details>

    <div class="egov-law-button-wrapper">
        <a href="{law.law_url}" class="egov-law-button" target="_blank" rel="noopener noreferrer">法令を見る</a>
    </div>
</section>
"""


def build_post_content(
    post: WPPost,
) -> str:
    """Build WordPress post content."""

    dashboard_html = _build_dashboard(post)

    laws_html = "".join(
        _build_law_section(law)
        for group in post.law_groups
        for law in group.laws
    )

    return f"""
<div class="egov-post">
    {dashboard_html}
    {laws_html}
</div>
""".strip()