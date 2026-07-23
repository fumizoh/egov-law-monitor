"""Compare API client."""

from sources.toc_api import fetch_law_toc
from toc_parser import build_sel_text_list

import requests

URL = "https://laws.e-gov.go.jp/internal-api/SelectLawCompareData.json"

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def fetch_compare(
    new_law_data_id: int,
    new_sub_revision: str,
) -> dict:
    """Fetch compare data."""

    toc = fetch_law_toc(
        new_law_data_id,
        new_sub_revision,
    )

    toc_body = toc["result"]["Toc_Data"]["TocBody"]

    sel_text_list = build_sel_text_list(toc_body)

    return _request_compare(
        new_law_data_id,
        new_sub_revision,
        sel_text_list,
    )


def _request_compare(
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