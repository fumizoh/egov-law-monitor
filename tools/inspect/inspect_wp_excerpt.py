"""Inspect generated WordPress post excerpt."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[2] / "src")
)

import storage
from wordpress.builder import build_wp_post


def main() -> None:
    """Build a WordPress post and print its excerpt."""

    laws = storage.load_laws()
    law_summaries = storage.load_law_summaries()
    statistics = storage.load_statistics()

    date = statistics["egov"]["last_update"]

    wp_post = build_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        statistics_data=statistics,
        date=date,
    )

    print()
    print("=== WordPress Excerpt ===")
    print()
    print(wp_post.excerpt)
    print()


if __name__ == "__main__":
    main()