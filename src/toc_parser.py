from __future__ import annotations

from typing import Any

from models import TocIndex


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
    """Build selTextList for Compare API."""

    object_ids = ["LawTitle"]

    _walk(toc_body, object_ids)

    return object_ids


def _walk(
    node,
    sel_text_list,
    location_lookup,
) -> None:
    if isinstance(node, dict):

        object_id = node.get("-ObjectId")

        if object_id and _is_target(object_id):
            sel_text_list.append(object_id.lstrip("#"))

        for value in node.values():
            _walk(
                value,
                sel_text_list,
                location_lookup,
            )

    elif isinstance(node, list):

        for item in node:
            _walk(
                item,
                sel_text_list,
                location_lookup,
            )


def _is_target(object_id: str) -> bool:
    """Return True if ObjectId should be sent to Compare API."""

    object_id = object_id.lstrip("#")

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