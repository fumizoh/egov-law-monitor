# sources/egov_api.py

import re

import logging

import requests

from models import Location, RevisionHistory


logger = logging.getLogger(__name__)

API_BASE_URL = "https://laws.e-gov.go.jp/api/2"


KANJI_DIGITS = {
    "零": 0,
    "〇": 0,
    "一": 1,
    "二": 2,
    "三": 3,
    "四": 4,
    "五": 5,
    "六": 6,
    "七": 7,
    "八": 8,
    "九": 9,
}

KANJI_UNITS = {
    "十": 10,
    "百": 100,
    "千": 1000,
}


def _kanji_number_to_int(text: str) -> int:
    """Convert a Japanese kanji number to an integer."""

    if text.isdigit():
        return int(text)

    total = 0
    current = 0

    for char in text:
        if char in KANJI_DIGITS:
            current = KANJI_DIGITS[char]
        elif char in KANJI_UNITS:
            unit = KANJI_UNITS[char]

            if current == 0:
                current = 1

            total += current * unit
            current = 0
        else:
            raise ValueError(
                f"Unsupported kanji number: {text}"
            )

    return total + current


def _location_number(
    label: str,
    suffix: str,
) -> int:
    """Extract a number from a Location label."""

    pattern = rf"^第(.+?){suffix}"
    match = re.match(pattern, label)

    if match is None:
        raise ValueError(
            f"Cannot parse location: {label}"
        )

    return _kanji_number_to_int(match.group(1))


def _location_article_num(label: str) -> str:
    """Convert an article Location label to API Article Num."""

    if not label.startswith("第"):
        raise ValueError(
            f"Cannot parse article location: {label}"
        )

    text = label[1:]

    text = text.split("（", 1)[0]

    if "条" not in text:
        raise ValueError(
            f"Cannot parse article location: {label}"
        )

    text = text.replace("条", "", 1)

    parts = text.split("の")

    return "_".join(
        str(_kanji_number_to_int(part))
        for part in parts
    )


def fetch_revisions(law_id: str) -> list[dict]:
    """Fetch revisions from e-Gov Law API v2."""

    response = requests.get(
        f"{API_BASE_URL}/law_revisions/{law_id}",
        timeout=30,
    )
    response.raise_for_status()

    data = response.json()
    return data["revisions"]


def find_revision_id(
    law_id: str,
    revision: RevisionHistory,
) -> str:
    """Find e-Gov API v2 law_revision_id."""

    effective_date = (
        revision.enforcement_date
        or revision.scheduled_enforcement_date
    )

    if not effective_date:
        raise ValueError(
            "Revision has neither enforcement date "
            "nor scheduled enforcement date."
        )

    revisions = fetch_revisions(law_id)

    matches = [
        item
        for item in revisions
        if (
            item.get("amendment_enforcement_date")
            or item.get("amendment_scheduled_enforcement_date")
        ) == effective_date
        and item.get("amendment_law_id") == revision.amendment_id
    ]

    if not matches:
        raise FileNotFoundError(
            f"e-Gov API revision not found: "
            f"{law_id}, {effective_date}, {revision.amendment_id}"
        )

    return matches[0]["law_revision_id"]


def fetch_law_data(revision_id: str) -> dict:
    """Fetch law data from e-Gov Law API v2."""

    response = requests.get(
        f"{API_BASE_URL}/law_data/{revision_id}",
        timeout=30,
    )
    response.raise_for_status()

    return response.json()


def _text_of(node: dict | str | None) -> str | None:
    """Return normalized text from an e-Gov API JSON node."""

    if node is None:
        return None

    if isinstance(node, str):
        text = node.strip()
        return text or None

    parts: list[str] = []

    for child in node.get("children", []):
        text = _text_of(child)

        if text:
            parts.append(text)

    text = " ".join(parts).strip()

    return text or None


def _find_child(
    node: dict,
    tag: str,
) -> dict | None:
    """Find the first direct child with the specified tag."""

    for child in node.get("children", []):
        if child.get("tag") == tag:
            return child

    return None


def _find_children(
    node: dict,
    tag: str,
) -> list[dict]:
    """Find direct children with the specified tag."""

    return [
        child
        for child in node.get("children", [])
        if child.get("tag") == tag
    ]


def _find_article(
    law_full_text: dict,
    article_num: str,
) -> dict | None:
    """Find Article by Article Num."""

    law_body = next(
        (
            child
            for child in law_full_text.get("children", [])
            if child.get("tag") == "LawBody"
        ),
        None,
    )

    if law_body is None:
        return None

    main_provision = _find_child(
        law_body,
        "MainProvision",
    )

    if main_provision is None:
        return None

    for article in _find_children(main_provision, "Article"):
        if article.get("attr", {}).get("Num") == article_num:
            return article

    return None


def get_provision_text(
    data: dict,
    location: Location,
) -> tuple[str | None, str | None]:
    """Find provision text for a Location."""

    article_num = _location_article_num(
        location.article
    )

    article = _find_article(
        data["law_full_text"],
        article_num,
    )

    if article is None:
        return None, None

    caption_node = _find_child(
        article,
        "ArticleCaption",
    )

    caption = _text_of(caption_node)

    target = article

    if location.paragraph:
        paragraph_num = str(
            _location_number(location.paragraph, "項")
        )

        paragraph = next(
            (
                child
                for child in _find_children(target, "Paragraph")
                if child.get("attr", {}).get("Num") == paragraph_num
            ),
            None,
        )

        if paragraph is None:
            return caption, None

        target = paragraph

    if location.item:
        item_num = str(
            _location_number(location.item, "号")
        )

        item = next(
            (
                child
                for child in _find_children(target, "Item")
                if child.get("attr", {}).get("Num") == item_num
            ),
            None,
        )

        if item is None:
            return caption, None

        target = item

    provision_text = _text_of(target)

    return caption, provision_text