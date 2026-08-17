"""Build WordPress post data."""

from models import (
    Law,
    LawSummary,
    RevisionHistory,
    Update,
    WPPost,
    WPLaw,
    WPLawRevision,
    WPStatistics,
)


def _build_statistics(
    data: dict,
) -> WPStatistics:
    """Build WordPress statistics."""

    return WPStatistics(
        last_update=data["last_update"],
        update_count=data["update_count"],
        updated_law_count=data["updated_law_count"],
        law_type=data["law_type"],
        law_count=data["law_count"],
    )


def _build_wp_revision(
    update: Update,
    revision: RevisionHistory,
) -> WPLawRevision:
    """Build one WordPress law revision."""

    return WPLawRevision(
        law_data_id=revision.law_data_id,
        sub_revision=revision.sub_revision,
        amendment_id=revision.amendment_id,
        amendment_name=revision.amendment_name,
        amendment_num=revision.amendment_num,
        enforcement_date=revision.enforcement_date,
        scheduled_enforcement_date=revision.scheduled_enforcement_date,
        enforcement_comment=revision.enforcement_comment,
        is_current=revision.is_current,
        published_date=update["published_date"],
        amend_published_date=update["amend_published_date"],
        compare_url=update["compare_url"],
        pending=update["pending"],
    )


def _build_wp_law(
    law: Law,
    law_summary: LawSummary,
) -> WPLaw:
    """Build one WordPress law."""

    revisions = {
        revision.law_data_id: revision
        for revision in law_summary.summary_input.revisions
    }

    wp_revisions = [
        _build_wp_revision(
            update=update,
            revision=revisions[update["law_data_id"]],
        )
        for update in law["updates"]
    ]

    return WPLaw(
        law_id=law["law_id"],
        law_no=law["law_no"],
        law_name=law["law_name"],
        law_type=law["law_type"],
        law_url=law["url"],
        wp_revisions=wp_revisions,
        summary=law_summary.response.summary if law_summary else None
    )


def build_wp_post(
    laws: dict[str, Law],
    law_summaries: dict[str, LawSummary],
    statistics_data: dict,
    date: str,
) -> WPPost:
    """Build a daily WordPress post."""

    statistics = _build_statistics(
        statistics_data["egov"],
    )

    wp_laws = [
        _build_wp_law(
            law=law,
            law_summary=law_summaries.get(law_id),
        )
        for law_id, law in laws.items()
    ]

    return WPPost(
        date=date,
        title=f"{date[:4]}年{date[4:6]}月{date[6:8]}日の法令更新",
        statistics=statistics,
        wp_laws=wp_laws,
    )