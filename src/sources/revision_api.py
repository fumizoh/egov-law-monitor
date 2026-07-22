"""Revision API client."""

import requests

URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def fetch_revisions(
    law_id: str,
) -> dict:
    """Fetch revision history."""

    payload = {
        "law_id": law_id,
    }

    response = requests.post(
        URL,
        json=payload,
        headers=HEADERS,
        timeout=30,
    )

    response.raise_for_status()

    return response.json()