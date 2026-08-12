from __future__ import annotations

import sys
from pathlib import Path
import xml.etree.ElementTree as ET


EXTRACTED_DIR = Path("data/extracted")


def find_xml(
    law_id: str,
    date: str,
    amendment_id: str,
) -> Path:
    basename = (
        f"{law_id}_{date.replace('-', '')}_{amendment_id}"
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

    if len(matches) > 1:
        raise RuntimeError(
            f"Multiple XML files found: {matches}"
        )

    return matches[0]


def _text(element: ET.Element | None) -> str | None:
    if element is None:
        return None

    text = "".join(element.itertext()).strip()

    return text or None


def find_article(
    root: ET.Element,
    article: str,
) -> ET.Element | None:
    article_num = article.removeprefix("第").removesuffix("条")

    for element in root.iter("Article"):
        if element.get("Num") == article_num:
            return element

    return None


def find_paragraph(
    article: ET.Element,
    paragraph: str,
) -> ET.Element | None:
    if not paragraph:
        return None

    paragraph_num = (
        paragraph
        .removeprefix("第")
        .removesuffix("項")
    )

    for element in article.findall("Paragraph"):
        if element.get("Num") == paragraph_num:
            return element

    return None


def find_item(
    paragraph: ET.Element,
    item: str,
) -> ET.Element | None:
    if not item:
        return None

    item_num = (
        item
        .removeprefix("第")
        .removesuffix("号")
    )

    for element in paragraph.findall("Item"):
        if element.get("Num") == item_num:
            return element

    return None


def get_provision_text(
    element: ET.Element,
) -> str:
    return " ".join(
        text.strip()
        for text in element.itertext()
        if text.strip()
    )


def main() -> None:
    if len(sys.argv) < 6:
        print(
            "Usage: "
            "python tools/inspect_xml_context.py "
            "<law_id> <date> <amendment_id> "
            "<article> <paragraph> [item]"
        )
        return

    law_id = sys.argv[1]
    date = sys.argv[2]
    amendment_id = sys.argv[3]
    article = sys.argv[4]
    paragraph = sys.argv[5]
    item = sys.argv[6] if len(sys.argv) >= 7 else None

    xml_path = find_xml(
        law_id,
        date,
        amendment_id,
    )

    print(f"XML: {xml_path}")

    tree = ET.parse(xml_path)
    root = tree.getroot()

    law_title = root.find(".//LawTitle")
    print(f"Law: {_text(law_title)}")

    article_element = find_article(
        root,
        article,
    )

    if article_element is None:
        print(f"Article not found: {article}")
        return

    print()
    print(f"Article: {article}")
    print(
        f"Caption: "
        f"{_text(article_element.find('ArticleCaption'))}"
    )

    target = article_element

    if paragraph:
        paragraph_element = find_paragraph(
            article_element,
            paragraph,
        )

        if paragraph_element is None:
            print(
                f"Paragraph not found: {paragraph}"
            )
            return

        target = paragraph_element

    if item:
        item_element = find_item(
            target,
            item,
        )

        if item_element is None:
            print(f"Item not found: {item}")
            return

        target = item_element

    print()
    print("=== Provision Text ===")
    print(get_provision_text(target))


if __name__ == "__main__":
    main()