"""LawText API client."""

from __future__ import annotations

from datetime import date

import requests

from sources.toc_api import fetch_law_toc
from toc_parser import build_sel_text_list

LAWTEXT_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawTextData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "e-Gov Law Monitor",
}


def fetch_law_text(
    law_id: str,
    law_data_id: int,
    sub_revision: str,
) -> dict:
    """Fetch law text."""

    toc = fetch_law_toc(
        law_data_id,
        sub_revision,
    )

    toc_body = toc["result"]["Toc_Data"]["TocBody"]

    payload = {
        "law_id": law_id,
        "law_data_id": law_data_id,
        "subRevision": sub_revision,
        "occasion": date.today().strftime("%Y/%m/%d"),
        "selTextList": build_sel_text_list(toc_body),
    }

    response = requests.post(
        LAWTEXT_URL,
        headers=HEADERS,
        json=payload,
        timeout=30,
    )

    response.raise_for_status()

    return response.json()