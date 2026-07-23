from __future__ import annotations

from typing import Any

from models import Location, TocIndex


def parse_toc(toc_body: dict) -> TocIndex:
    """Parse TOC."""

    sel_text_list: list[str] = ["LawTitle"]
    location_lookup: dict[str, Location] = {}

    _walk(
        toc_body,
        sel_text_list,
        location_lookup,
    )

    return TocIndex(
        sel_text_list=sel_text_list,
        location_lookup=location_lookup,
    )


def build_sel_text_list(toc_body: dict) -> list[str]:
    return parse_toc(toc_body).sel_text_list


def _walk(
    node: Any,
    sel_text_list: list[str],
    location_lookup: dict[str, Location],
    article: str | None = None,
    paragraph: str | None = None,
    item: str | None = None,
) -> None:

    if isinstance(node, dict):

        object_id = node.get("-ObjectId")
        label = node.get("-Label")
        xpath = node.get("-Xpath", "")

        # 親の状態を引き継ぐ
        next_article = article
        next_paragraph = paragraph
        next_item = item

        if "/Item[" in xpath:
            next_item = label

        elif "/Paragraph[" in xpath:
            next_paragraph = label
            next_item = None

        elif "/Article[" in xpath:
            next_article = label
            next_paragraph = None
            next_item = None

        if object_id:

            object_id = object_id.lstrip("#")

            if next_article:
                location_lookup[object_id] = Location(
                    article=next_article,
                    paragraph=next_paragraph,
                    item=next_item,
                )

            if _is_target(object_id):
                sel_text_list.append(object_id)

        for value in node.values():
            _walk(
                value,
                sel_text_list,
                location_lookup,
                next_article,
                next_paragraph,
                next_item,
            )

    elif isinstance(node, list):

        for child in node:
            _walk(
                child,
                sel_text_list,
                location_lookup,
                article,
                paragraph,
                item,
            )


def _is_target(object_id: str) -> bool:
    """Return True if ObjectId should be sent to Compare API."""

    # Compare APIへ送らないコンテナノード
    if object_id in {
        "MainProvision",
        "AmendSupplProvision",
    }:
        return False

    # Paragraph / Item は除外
    if "-Pr_" in object_id:
        return False

    if "-It_" in object_id:
        return False

    return True