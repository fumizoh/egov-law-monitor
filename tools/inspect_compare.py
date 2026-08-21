"""Inspect Compare API response."""

from pathlib import Path
import sys
import json

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.compare_api import fetch_compare

LAW_DATA_ID = 637185
SUB_REVISION = 1


def main() -> None:

    compare_json = fetch_compare(
        new_law_data_id=LAW_DATA_ID,
        new_sub_revision=SUB_REVISION,
    )

    print(json.dumps(
        compare_json,
        indent=2,
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()