"""Revision API client."""

import requests

import time

from config import (
    TIMEOUT,
    REVISION_API_MAX_RETRIES,
    REVISION_API_RETRY_WAIT,
)

URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def _is_rate_limited(response: requests.Response) -> bool:
    return (
        response.url.endswith("/sorry/404-notfound.html")
        or "text/html" in response.headers.get("Content-Type", "")
    )


def fetch_revisions(law_id: str) -> dict:
    """Fetch revision history."""

    payload = {"law_id": law_id}

    for attempt in range(REVISION_API_MAX_RETRIES):
        response = requests.post(
            URL,
            json=payload,
            headers=HEADERS,
            timeout=TIMEOUT,
        )

        response.raise_for_status()

        if not _is_rate_limited(response):
            return response.json()

        if attempt + 1 < REVISION_API_MAX_RETRIES:
            wait = REVISION_API_RETRY_WAIT
            print(
                f"Revision API rate limited "
                f"({attempt + 1}/{REVISION_API_MAX_RETRIES}). "
                f"Waiting {wait} seconds..."
            )
            time.sleep(wait)

    raise RuntimeError(
        "Revision API rate limit exceeded."
    )
