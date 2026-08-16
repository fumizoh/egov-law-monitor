"""Validate WordPress post data."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage
from wordpress.builder import build_wp_post


def validate_wp_post(
    laws,
    law_summaries,
    wp_post,
) -> None:
    """Validate generated WordPress post."""

    errors = []

    # Law count
    if len(wp_post.wp_laws) != len(laws):
        errors.append(
            f"law count mismatch: "
            f"{len(laws)} -> {len(wp_post.wp_laws)}"
        )

    # Law-level validation
    for law_id, law in laws.items():
        wp_law = next(
            (
                item
                for item in wp_post.wp_laws
                if item.law_id == law_id
            ),
            None,
        )

        if wp_law is None:
            errors.append(
                f"missing WP law: {law_id}"
            )
            continue

        # law_url
        expected_url = law["url"]
        if wp_law.law_url != expected_url:
            errors.append(
                f"law_url mismatch: {law_id}"
            )

        # Summary
        law_summary = law_summaries.get(law_id)

        if law_summary is None:
            if wp_law.summary is not None:
                errors.append(
                    f"unexpected summary: {law_id}"
                )
        elif wp_law.summary != law_summary.response.summary:
            errors.append(
                f"summary mismatch: {law_id}"
            )

        # Revision count
        if law_summary is not None:
            expected_count = len(
                law_summary.summary_input.revisions
            )

            if len(wp_law.wp_revisions) != len(law["updates"]):
                errors.append(
                    f"revision count mismatch: {law_id}"
                )

            if expected_count != len(wp_law.wp_revisions):
                errors.append(
                    f"summary revision count mismatch: {law_id}"
                )

            # Revision mapping
            expected_revisions = {
                revision.law_data_id: revision
                for revision in law_summary.summary_input.revisions
            }

            actual_revisions = {
                revision.law_data_id: revision
                for revision in wp_law.wp_revisions
            }

            if set(expected_revisions) != set(actual_revisions):
                errors.append(
                    f"revision mapping mismatch: {law_id}"
                )

            # Revision fields
            for law_data_id, revision in expected_revisions.items():
                wp_revision = actual_revisions.get(law_data_id)

                if wp_revision is None:
                    continue

                if wp_revision.is_current != revision.is_current:
                    errors.append(
                        f"is_current mismatch: "
                        f"{law_id}/{law_data_id}"
                    )

                if (
                    wp_revision.enforcement_date
                    != revision.enforcement_date
                ):
                    errors.append(
                        f"enforcement_date mismatch: "
                        f"{law_id}/{law_data_id}"
                    )

                if (
                    wp_revision.scheduled_enforcement_date
                    != revision.scheduled_enforcement_date
                ):
                    errors.append(
                        f"scheduled_enforcement_date mismatch: "
                        f"{law_id}/{law_data_id}"
                    )

                if (
                    wp_revision.enforcement_comment
                    != revision.enforcement_comment
                ):
                    errors.append(
                        f"enforcement_comment mismatch: "
                        f"{law_id}/{law_data_id}"
                    )

    if errors:
        print("Validation failed.")
        print()

        for error in errors:
            print(f"✗ {error}")

        raise SystemExit(1)

    print("WPPost validation passed.")
    print()
    print(f"date:       {wp_post.date}")
    print(f"title:      {wp_post.title}")
    print(f"laws:       {len(laws)}")
    print(f"summaries:  {len(law_summaries)}")
    print(f"wp_laws:    {len(wp_post.wp_laws)}")
    print()
    print("✓ law mapping")
    print("✓ law_url")
    print("✓ summaries")
    print("✓ revision mapping")
    print("✓ revision fields")


def main() -> None:
    """Run WordPress post validation."""

    laws = storage.load_laws()
    law_summaries = storage.load_law_summaries()

    statistics = storage.load_statistics()
    date = statistics["egov"]["last_update"]

    wp_post = build_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        date=date,
    )

    validate_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        wp_post=wp_post,
    )


if __name__ == "__main__":
    main()