import json
from pathlib import Path


def save_updates_json(updates: list[dict], output_path: Path) -> None:
    """
    更新法令一覧をJSONとして保存する。
    """

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(
            updates,
            f,
            ensure_ascii=False,
            indent=2,
        )