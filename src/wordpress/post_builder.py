"""Build WordPress post content."""


from models import (
    WPPost,
    WPLaw,
    WPLawRevision,
)


def build_post_title(post: WPPost) -> str:
    """Build WordPress post title."""

    return (
        f"{post.date[:4]}年"
        f"{post.date[4:6]}月"
        f"{post.date[6:8]}日の法令更新"
    )


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

    amendment_name = revision.amendment_name or ""

    if revision.compare_url:
        amendment_html = (
            f'<a href="{revision.compare_url}" '
            f'target="_blank" rel="noopener noreferrer">'
            f"{amendment_name}　条文比較"
            f"</a>"
        )
    else:
        amendment_html = amendment_name

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
        {law.summary.body}
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
<section class="egov-law-card">
    <h2 class="egov-law-name simple-h2">{law.law_name}</h2>

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

    laws_html = "".join(
        _build_law_section(law)
        for law in post.wp_laws
    )

    return f"""
<div class="egov-post">
    {laws_html}
</div>
""".strip()