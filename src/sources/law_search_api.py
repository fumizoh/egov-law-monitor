"""e-Gov law search API client."""

from datetime import date

import requests

from models import LawSearchResult


SEARCH_URL = (
    "https://laws.e-gov.go.jp/internal-api/SelectLaw.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "e-Gov Law Monitor",
}


LAW_TYPE_ARRAY = [1, 2, 7, 8, 3, 4, 5, 6]


def _build_payload(
    search_text: str,
    offset: int = 0,
) -> dict:
    """Build search request payload."""

    return {
        "searchType": 1,
        "lawType_array": [1, 2, 7, 8, 3, 4, 5, 6],
        "occasionDate": date.today().strftime("%Y/%m/%d"),
        "searchText": search_text,
        "searchTextSnt": "",
        "lawNo_1": "",
        "lawNo_2": "",
        "lawNo_3": "",
        "repealReason_array": [2, 1, 4],
        "lawName": "",
        "status_array": [1],
        "lawConstruction_array": [1, 2, 3, 4, 5, 6, 7, 8, 9],
        "promulgationDate_from": "",
        "promulgationDate_to": "",
        "categoryCd_array": list(range(1, 51)),
        "matchingTurnFlg": 0,
        "matchingWordCnt": 0,
        "matchingSoundFlg": 0,
        "dispCnt": 100,
        "sort": 2,
        "offset": offset,
    }


def _request_search(
    search_text: str,
    offset: int = 0,
) -> dict:
    """Fetch law search response."""

    response = requests.post(
        SEARCH_URL,
        json=_build_payload(
            search_text,
            offset,
        ),
        headers=HEADERS,
        timeout=30,
    )

    response.raise_for_status()

    raw = response.json()

    if not raw["result"]["success"]:
        raise RuntimeError(
            f"Law search API: "
            f"{raw['result'].get('errorMessage', 'unknown error')}"
        )

    return raw


def _parse_search_result(
    raw: dict,
) -> LawSearchResult:
    """Parse one search result."""

    return LawSearchResult(
        law_id=raw["law_id"],
        law_name=raw["law_name"],
        law_no=raw["law_no"],
        law_type=raw["law_type_label"],
    )


def search_laws(
    search_text: str,
    offset: int = 0,
) -> list[LawSearchResult]:
    """Search laws."""

    raw = _request_search(
        search_text,
        offset,
    )

    results = raw["result"]["searchResult_array"]

    return [
        _parse_search_result(item)
        for item in results
    ]