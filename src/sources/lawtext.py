import requests

URL = "https://laws.e-gov.go.jp/internal-api/SelectLawTextData.json"

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}

def fetch_law_text(
    law_id: str,
    law_data_id: int,
    sub_revision: str,
    occasion: str,
    sel_text_list: list[str],
) -> dict:
    """Fetch law text."""

    payload = {
        "law_id": law_id,
        "law_data_id": law_data_id,
        "subRevision": sub_revision,
        "occasion": occasion,
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
