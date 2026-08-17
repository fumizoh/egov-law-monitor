"""Inspect WordPress post data."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from wordpress.builder import (
    build_wp_post,
)

from storage import (
    load_law_summaries,
    load_laws,
    load_statistics,
)

def main() -> None:
    date = "20260814"

    laws = load_laws()
    law_summaries = load_law_summaries()
    statistics = load_statistics()

    print(f"Date: {date}")
    print(f"Laws: {len(laws)}")
    print(f"Law summaries: {len(law_summaries)}")

    wp_post = build_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        statistics_data=statistics,
        date=date,
    )

    assert wp_post.date == date
    assert len(wp_post.wp_laws) == len(laws)

    # Statistics
    wp_statistics = wp_post.statistics
    egov_statistics = statistics["egov"]

    assert wp_statistics.last_update == egov_statistics["last_update"]
    assert wp_statistics.update_count == egov_statistics["update_count"]
    assert (
        wp_statistics.updated_law_count
        == egov_statistics["updated_law_count"]
    )
    assert wp_statistics.law_type == egov_statistics["law_type"]
    assert wp_statistics.law_count == egov_statistics["law_count"]

    assert (
        len(wp_post.wp_laws)
        == wp_statistics.updated_law_count
    )

    # Law mapping
    for wp_law in wp_post.wp_laws:
        assert wp_law.law_id in laws

        law = laws[wp_law.law_id]

        assert wp_law.law_name == law["law_name"]
        assert wp_law.law_no == law["law_no"]
        assert wp_law.law_type == law["law_type"]
        assert wp_law.law_url == law["url"]

    # Summary
    for wp_law in wp_post.wp_laws:
        law_summary = law_summaries.get(wp_law.law_id)

        if law_summary is None:
            assert wp_law.summary is None
        else:
            assert wp_law.summary == (
                law_summary.response.summary
            )

    # Revision mapping
    for wp_law in wp_post.wp_laws:
        law = laws[wp_law.law_id]
        law_summary = law_summaries.get(wp_law.law_id)

        if law_summary is None:
            continue

        revisions = {
            revision.law_data_id: revision
            for revision in law_summary.summary_input.revisions
        }

        assert len(wp_law.wp_revisions) == len(
            law["updates"]
        )

        for wp_revision, update in zip(
            wp_law.wp_revisions,
            law["updates"],
        ):
            revision = revisions[update["law_data_id"]]

            assert (
                wp_revision.law_data_id
                == revision.law_data_id
            )
            assert (
                wp_revision.sub_revision
                == revision.sub_revision
            )
            assert (
                wp_revision.amendment_id
                == revision.amendment_id
            )
            assert (
                wp_revision.is_current
                == revision.is_current
            )
            assert (
                wp_revision.published_date
                == update["published_date"]
            )
            assert (
                wp_revision.amend_published_date
                == update["amend_published_date"]
            )
            assert (
                wp_revision.compare_url
                == update["compare_url"]
            )
            assert (
                wp_revision.pending
                == update["pending"]
            )

    print()
    print("WPPost validation passed.")
    print()
    print(f"date:       {wp_post.date}")
    print(f"title:      {wp_post.title}")
    print(f"laws:       {len(laws)}")
    print(f"summaries:  {len(law_summaries)}")
    print(f"wp_laws:    {len(wp_post.wp_laws)}")
    print()
    print("Statistics:")
    print(f"  last_update:        {wp_statistics.last_update}")
    print(f"  update_count:       {wp_statistics.update_count}")
    print(
        "  updated_law_count:  "
        f"{wp_statistics.updated_law_count}"
    )
    print(f"  law_type:           {wp_statistics.law_type}")
    print(f"  law_count:          {wp_statistics.law_count}")
    print()
    print("✓ statistics")
    print("✓ law mapping")
    print("✓ summaries")
    print("✓ revision mapping")
    print("✓ revision fields")


if __name__ == "__main__":
    main()