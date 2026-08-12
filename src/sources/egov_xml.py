from __future__ import annotations

import re
import xml.etree.ElementTree as ET

from pathlib import Path

from models import (
    Location,
    RevisionHistory,
)

EXTRACTED_DIR = Path("data/extracted")


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


def kanji_number_to_int(text: str) -> int:
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


def location_number(label: str, suffix: str) -> int:
    """Extract a number from a Location label."""

    pattern = rf"^第(.+?){suffix}"
    match = re.match(pattern, label)

    if match is None:
        raise ValueError(
            f"Cannot parse location: {label}"
        )

    return kanji_number_to_int(match.group(1))


def location_article_num(label: str) -> str:
    """Convert an article Location label to XML Article Num."""

    if not label.startswith("第"):
        raise ValueError(
            f"Cannot parse article location: {label}"
        )

    text = label[1:]

    # 条文番号の部分だけを取り出す
    text = text.split("（", 1)[0]

    # 「条」を除去して条番号部分を取得
    if "条" not in text:
        raise ValueError(
            f"Cannot parse article location: {label}"
        )

    text = text.replace("条", "", 1)

    parts = text.split("の")

    return "_".join(
        str(kanji_number_to_int(part))
        for part in parts
    )


def find_xml(
    law_id: str,
    revision: RevisionHistory,
) -> Path:
    """Find the XML corresponding to a revision."""

    if not revision.amendment_id:
        raise ValueError(
            "Cannot find amendment XML for a new law."
        )

    xml_date = (
        revision.enforcement_date
        or revision.scheduled_enforcement_date
    )

    if not xml_date:
        raise ValueError(
            "Revision has neither enforcement date "
            "nor scheduled enforcement date."
        )

    basename = (
        f"{law_id}_"
        f"{xml_date.replace('-', '')}_"
        f"{revision.amendment_id}"
    )

    matches = list(
        EXTRACTED_DIR.glob(
            f"*/{basename}/{basename}.xml"
        )
    )

    if not matches:
        raise FileNotFoundError(
            f"XML not found: {basename}"
        )

    # 同じXMLが複数の更新日に取得されている場合は、
    # 最新のダウンロードディレクトリを使用する。
    matches.sort(
        key=lambda path: path.parent.parent.name,
        reverse=True,
    )

    return matches[0]


def text_of(element: ET.Element | None) -> str | None:
    """Return normalized element text."""

    if element is None:
        return None

    text = " ".join(
        part.strip()
        for part in element.itertext()
        if part.strip()
    )

    return text or None


def find_article(
    root: ET.Element,
    label: str,
) -> ET.Element | None:
    """Find Article by Location label."""

    num = location_article_num(label)

    for article in root.iter("Article"):
        if article.get("Num") == num:
            return article

    return None


def find_paragraph(
    article: ET.Element,
    label: str,
) -> ET.Element | None:
    """Find Paragraph by Location label."""

    if not label:
        return None

    num = location_number(label, "項")

    for paragraph in article.findall("Paragraph"):
        if paragraph.get("Num") == str(num):
            return paragraph

    return None


def find_item(
    paragraph: ET.Element,
    label: str,
) -> ET.Element | None:
    """Find Item by Location label."""

    if not label:
        return None

    num = location_number(label, "号")

    for item in paragraph.findall("Item"):
        if item.get("Num") == str(num):
            return item

    return None


def get_provision_context(
    xml_path: Path,
    location: Location,
) -> tuple[str | None, str | None]:
    """Find XML context for a Location."""

    tree = ET.parse(xml_path)
    root = tree.getroot()

    article = find_article(
        root,
        location.article,
    )

    if article is None:
        return None, None

    caption = text_of(
        article.find("ArticleCaption")
    )

    target = article

    if location.paragraph:
        paragraph = find_paragraph(
            article,
            location.paragraph,
        )

        if paragraph is None:
            return caption, None

        target = paragraph

    if location.item:
        item = find_item(
            target,
            location.item,
        )

        if item is None:
            return caption, None

        target = item

    provision_text = text_of(target)

    return caption, provision_text