"""e-Gov Law API client."""

from __future__ import annotations

import xml.etree.ElementTree as ET

import requests


LAW_API_URL = "https://laws.e-gov.go.jp/api/1/lawdata/{law_id}"


def fetch_law_name(law_id: str) -> str:
    """Fetch the current law name from e-Gov Law API."""

    response = requests.get(
        LAW_API_URL.format(law_id=law_id),
        timeout=30,
    )

    response.raise_for_status()

    root = ET.fromstring(response.content)

    law_title = root.find(
        "./ApplData/LawFullText/Law/LawBody/LawTitle"
    )

    if law_title is None or law_title.text is None:
        raise ValueError(
            f"LawTitle not found: law_id={law_id}"
        )

    return law_title.text