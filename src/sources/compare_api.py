"""Compare API client."""

import requests

URL = "https://laws.e-gov.go.jp/internal-api/SelectLawCompareData.json"

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def fetch_compare(
    new_law_data_id: int,
    new_sub_revision: str,
    sel_text_list: list[str],
) -> dict:
    """Fetch Compare API response."""

    payload = {
        "new_law_data_id": new_law_data_id,
        "new_subRevision": new_sub_revision,
        "selTextList": sel_text_list,
    }

    response = requests.post(
        URL,
        json=payload,
        headers=HEADERS,
        timeout=30,
    )

    response.raise_for_status()

    return response.json()