"""WordPress publishing service."""

import storage

from wordpress.builder import build_wp_post
from wordpress.client import (
    create_post,
    find_post,
    update_post,
)
from wordpress.post_builder import build_post_content

from models import WPResult


POST_TYPE = "posts"
LAW_UPDATE_CATEGORY_ID = 7


def sync_daily_post(
    date: str,
    storage_paths: storage.StoragePaths = storage.DEFAULT_STORAGE,
) -> dict:
    """Create or update a daily WordPress law update post."""

    laws = storage.load_laws(
        paths=storage_paths,
    )

    law_summaries = storage.load_law_summaries(
        paths=storage_paths,
    )

    statistics = storage.load_statistics(
        paths=storage_paths,
    )

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
            category_id=LAW_UPDATE_CATEGORY_ID,
        )

        return WPResult(
            status="success",
            action="created",
            post_id=post["id"],
            post_status=post["status"],
            link=post["link"],
        )

    post = update_post(
        post["id"],
        title=wp_post.title,
        content=content,
        excerpt=wp_post.excerpt,
        slug=f"{date}-update",
        status=post["status"],
        post_type=POST_TYPE,
        category_id=LAW_UPDATE_CATEGORY_ID,
    )

    return WPResult(
        status="success",
        action="updated",
        post_id=post["id"],
        post_status=post["status"],
        link=post["link"],
    )