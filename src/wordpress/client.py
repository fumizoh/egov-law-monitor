"""WordPress REST API client."""

import os

import requests


def _get_config() -> tuple[str, str, str]:
    """Get WordPress API configuration."""

    wp_url = os.environ["WP_URL"].rstrip("/")
    username = os.environ["WP_USERNAME"]
    app_password = os.environ["WP_APP_PASSWORD"]

    return wp_url, username, app_password


def find_post(slug: str) -> dict | None:
    """Find a post by slug."""

    wp_url, username, app_password = _get_config()

    response = requests.get(
        f"{wp_url}/wp-json/wp/v2/posts",
        params={"slug": slug},
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    posts = response.json()

    if not posts:
        return None

    return posts[0]


def create_post(
    *,
    title: str,
    content: str,
    slug: str,
    status: str = "draft",
) -> dict:
    """Create a WordPress post."""

    wp_url, username, app_password = _get_config()

    response = requests.post(
        f"{wp_url}/wp-json/wp/v2/posts",
        json={
            "title": title,
            "content": content,
            "slug": slug,
            "status": status,
        },
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    return response.json()


def update_post(
    post_id: int,
    *,
    title: str,
    content: str,
    slug: str,
    status: str = "draft",
) -> dict:
    """Update a WordPress post."""

    wp_url, username, app_password = _get_config()

    response = requests.post(
        f"{wp_url}/wp-json/wp/v2/posts/{post_id}",
        json={
            "title": title,
            "content": content,
            "slug": slug,
            "status": status,
        },
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    return response.json()