"""Inspect WordPress post creation."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage

from wordpress.builder import build_wp_post
from wordpress.client import create_post
from wordpress.post_builder import (
    build_post_content,
    build_post_title,
)


def main() -> None:
    """Create a WordPress draft post for inspection."""

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

    title = build_post_title(wp_post)
    content = build_post_content(wp_post)
    slug = wp_post.date

    post = create_post(
        title=title,
        content=content,
        slug=slug,
        status="draft",
    )

    print("WordPress draft created.")
    print()
    print(f"id:     {post['id']}")
    print(f"slug:   {post['slug']}")
    print(f"title:  {post['title']['rendered']}")
    print(f"status: {post['status']}")
    print(f"link:   {post['link']}")


if __name__ == "__main__":
    main()