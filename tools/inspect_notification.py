"""Inspect processing result notification."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage

from models import ProcessingResult, WPResult
from notification.generator import (
    create_email_body,
    create_email_subject,
)
from wordpress.service import sync_daily_post


def main() -> None:
    """Generate and inspect notification."""

    statistics = storage.load_statistics()

    date = statistics["egov"]["last_update"]

    laws = list(storage.load_laws().values())

    wp_result = sync_daily_post(
        date=date,
    )

    result = ProcessingResult(
        date=date,
        update_count=statistics["egov"]["update_count"],
        updated_law_count=statistics["egov"]["updated_law_count"],
        laws=laws,
        wp=wp_result,
    )

    subject = create_email_subject(result)
    body = create_email_body(result)

    print("=== Subject ===")
    print(subject)

    print()
    print("=== Body ===")
    print(body)

    # No Update
    no_update_result = ProcessingResult(
        date="20260817",
        update_count=0,
        updated_law_count=0,
        laws=[],
        wp=None,
    )

    subject = create_email_subject(no_update_result)
    body = create_email_body(no_update_result)

    print()
    print("=== No Update Subject ===")
    print(subject)

    print()
    print("=== No Update Body ===")
    print(body)


    # WP Error
    error_result = ProcessingResult(
        date="20260817",
        update_count=37,
        updated_law_count=15,
        laws=laws,
        wp=WPResult(
            status="error",
            error="WordPress REST APIへの接続に失敗しました",
        ),
    )

    subject = create_email_subject(error_result)
    body = create_email_body(error_result)

    print()
    print("=== WP Error Subject ===")
    print(subject)

    print()
    print("=== WP Error Body ===")
    print(body)


if __name__ == "__main__":
    main()