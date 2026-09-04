"""WordPress REST API client."""

import os
import time

from dotenv import load_dotenv
import requests

load_dotenv()


def _get_config() -> tuple[str, str, str]:
    """Get WordPress API configuration."""

    wp_url = os.environ["WP_URL"].rstrip("/")
    username = os.environ["WP_USERNAME"]
    app_password = os.environ["WP_APP_PASSWORD"]

    return wp_url, username, app_password


def _get_api_config() -> tuple[str, str, str]:
    """Get WordPress internal API configuration."""

    wp_url = os.environ["WP_URL"].rstrip("/")
    username = os.environ["WP_API_USERNAME"]
    app_password = os.environ["WP_API_APP_PASSWORD"]

    return wp_url, username, app_password


def _get_endpoint(
    wp_url: str,
    post_type: str,
) -> str:
    """Get WordPress REST API endpoint."""

    return f"{wp_url}/wp-json/wp/v2/{post_type}"


def find_post(
    slug: str,
    *,
    post_type: str = "posts",
) -> dict | None:
    """Find a WordPress post by slug."""

    wp_url, username, app_password = _get_config()

    retry_delays = (10, 30)

    for attempt in range(3):
        try:
            response = requests.get(
                _get_endpoint(wp_url, post_type),
                params={
                    "slug": slug,
                    "status": "any",
                },
                auth=(username, app_password),
                timeout=30,
            )

            if response.status_code in {502, 503, 504}:
                if attempt < 2:
                    time.sleep(retry_delays[attempt])
                    continue

            response.raise_for_status()

            posts = response.json()

            if not posts:
                return None

            return posts[0]

        except (
            requests.exceptions.ConnectTimeout,
            requests.exceptions.ConnectionError,
        ):
            if attempt == 2:
                raise

            time.sleep(retry_delays[attempt])

    raise RuntimeError("WordPressへの接続に失敗しました。")


def create_post(
    *,
    title: str,
    content: str,
    excerpt: str,
    slug: str,
    status: str = "draft",
    post_type: str = "posts",
    category_id: int,
) -> dict:
    """Create a WordPress post."""

    wp_url, username, app_password = _get_config()

    response = requests.post(
        _get_endpoint(wp_url, post_type),
        json={
            "title": title,
            "content": content,
            "excerpt": excerpt,
            "slug": slug,
            "status": status,
            "categories": [category_id],
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
    excerpt: str,
    slug: str,
    status: str = "draft",
    post_type: str = "posts",
    category_id: int,
) -> dict:
    """Update a WordPress post."""

    wp_url, username, app_password = _get_config()

    response = requests.post(
        f"{_get_endpoint(wp_url, post_type)}/{post_id}",
        json={
            "title": title,
            "content": content,
            "excerpt": excerpt,
            "slug": slug,
            "status": status,
            "categories": [category_id],
        },
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    return response.json()


def get_watch_settings() -> list[dict]:
    """Get watch settings for all users from WordPress."""

    wp_url, username, app_password = _get_api_config()

    response = requests.get(
        f"{wp_url}/wp-json/egov-law-monitor/v1/internal/watch-settings",
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    return response.json()["users"]