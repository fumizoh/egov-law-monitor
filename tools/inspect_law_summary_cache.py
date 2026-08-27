from pprint import pprint

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage


def main() -> None:
    paths = storage.REPROCESS_STORAGE
    date = "20260820"

    print(f"Storage: {paths.law_summaries}")

    cached_date = storage.get_law_summaries_date(paths)

    print(f"現在のキャッシュ日付: {cached_date}")

    summaries = storage.load_law_summaries(paths)

    print(f"現在のサマリ件数: {len(summaries)}")

    print()
    print(f"{date} のキャッシュを準備します。")

    storage.prepare_law_summaries(
        date=date,
        paths=paths,
    )

    cached_date = storage.get_law_summaries_date(paths)
    summaries = storage.load_law_summaries(paths)

    print(f"準備後の日付: {cached_date}")
    print(f"準備後のサマリ件数: {len(summaries)}")

    print()
    print("同じ日付でもう一度準備します。")

    storage.prepare_law_summaries(
        date=date,
        paths=paths,
    )

    cached_date = storage.get_law_summaries_date(paths)
    summaries = storage.load_law_summaries(paths)

    print(f"再準備後の日付: {cached_date}")
    print(f"再準備後のサマリ件数: {len(summaries)}")


if __name__ == "__main__":
    main()