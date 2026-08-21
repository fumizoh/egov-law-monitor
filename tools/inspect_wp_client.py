"""Inspect WordPress post creation."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage

from wordpress.builder import build_wp_post
from wordpress.client import find_post, create_post, update_post
from wordpress.post_builder import (
    build_post_content,
    build_post_title,
)

from wordpress.service import POST_TYPE


def main() -> None:
    """Create a WordPress draft post for inspection."""

    storage_paths = storage.REPROCESS_STORAGE

    laws = storage.load_laws(
        paths=storage_paths,
    )

    law_summaries = storage.load_law_summaries(
        paths=storage_paths,
    )

    statistics = storage.load_statistics(
        paths=storage_paths,
    )

    date = statistics["egov"]["last_update"]

    wp_post = build_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        statistics_data=statistics,
        date=date,
    )

    content = build_post_content(wp_post)

    post = find_post(
        slug=f"{date}-update",
        post_type=POST_TYPE,
    )

    if post is None:
        post = create_post(
            title=wp_post.title,
            content=content,
            excerpt=wp_post.excerpt,
            slug=f"{date}-update",
            status="draft",
            post_type=POST_TYPE,
        )
        print("Created")
    else:
        post = update_post(
            post["id"],
            title=wp_post.title,
            content=content,
            excerpt=wp_post.excerpt,
            slug=f"{date}-update",
            status=post["status"],
            post_type=POST_TYPE,
        )
        print("Updated")

    print(f"id:     {post['id']}")
    print(f"slug:   {post['slug']}")
    print(f"status: {post['status']}")
    print(f"link:   {post['link']}")


if __name__ == "__main__":
    main()