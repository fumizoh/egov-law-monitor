"""
更新イベント検出
"""

from pathlib import Path
import json

from config import DOCS_DATA


def detect_new_updates(
    source: str,
    updates: list[dict],
) -> list[dict]:
    """
    前回保存したデータと比較し、
    新しい更新イベントだけ返す。
    """

    previous = _load_previous(source)

    old_keys = {_event_key(source, update) for update in previous}

    return [
        update
        for update in updates
        if _event_key(source, update) not in old_keys
    ]


def _load_previous(source: str) -> list[dict]:
    """
    前回保存データを読み込む。
    """

    path = DOCS_DATA / f"{source}_updates.json"

    if not path.exists():
        return []

    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def _event_key(source: str, update: dict) -> tuple:
    """
    更新イベントを一意に識別するキーを返す。
    """

    try:
        builder = EVENT_KEY_BUILDERS[source]
    except KeyError as exc:
        raise ValueError(f"Unknown source: {source}") from exc

    return builder(update)


def _egov_event_key(update: dict) -> tuple:
    """e-Gov 更新イベントのキー"""

    metadata = update["metadata"]

    return (
        metadata["law_id"],
        metadata["published_date"],
    )


def _public_comment_event_key(update: dict) -> tuple:
    """パブリックコメント更新イベントのキー"""

    return (
        update["url"],
    )


EVENT_KEY_BUILDERS = {
    "egov": _egov_event_key,
    "public_comment": _public_comment_event_key,
}