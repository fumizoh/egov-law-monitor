from __future__ import annotations

from typing import Any


def build_sel_text_list(toc_body: dict) -> list[str]:
    """Build selTextList for Compare API."""

    object_ids = ["LawTitle"]

    _walk(toc_body, object_ids)

    return object_ids


def _walk(node: Any, object_ids: list[str]) -> None:
    if isinstance(node, dict):

        object_id = node.get("-ObjectId")

        if object_id and _is_target(object_id):
            object_ids.append(object_id.lstrip("#"))

        for value in node.values():
            _walk(value, object_ids)

    elif isinstance(node, list):

        for item in node:
            _walk(item, object_ids)


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