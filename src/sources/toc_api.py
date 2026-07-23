from __future__ import annotations

import requests

TOC_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawTocData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def fetch_law_toc(
    law_data_id: int,
    sub_revision: str,
) -> dict:
    payload = {
        "law_data_id": law_data_id,
        "subRevision": sub_revision,
    }

    response = requests.post(
        TOC_URL,
        headers=HEADERS,
        json=payload,
        timeout=30,
    )
    response.raise_for_status()

    return response.json()