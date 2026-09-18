"""Revision data source."""

import logging
import time

import requests

import comparison

from models import RevisionHistory


logger = logging.getLogger(__name__)


REVISION_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "e-Gov Law Monitor",
}

REVISION_API_MAX_RETRIES = 3
REVISION_API_RETRY_WAIT = 300


def _is_rate_limited(response: requests.Response) -> bool:
    return (
        response.url.endswith("/sorry/404-notfound.html")
        or "text/html" in response.headers.get("Content-Type", "")
    )


def fetch_revisions(law_id: str) -> dict:
    """Fetch revision history from e-Gov."""

    payload = {"law_id": law_id}

    for attempt in range(REVISION_API_MAX_RETRIES):
        response = requests.post(
            REVISION_URL,
            json=payload,
            headers=HEADERS,
            timeout=30,
        )

        response.raise_for_status()

        if not _is_rate_limited(response):
            return response.json()

        if attempt + 1 < REVISION_API_MAX_RETRIES:
            wait = REVISION_API_RETRY_WAIT

            logger.info(
                "Revision API rate limited (%d/%d). Waiting %d seconds...",
                attempt + 1,
                REVISION_API_MAX_RETRIES,
                wait,
            )

            time.sleep(wait)

    raise RuntimeError(
        "Revision API rate limit exceeded."
    )


def get_revision_history(
    law_id: str,
) -> list[RevisionHistory]:
    """Fetch and parse revision history."""

    raw = fetch_revisions(law_id)

    return comparison.parse_revision_history(
        raw["result"]["Amendment_History"],
    )