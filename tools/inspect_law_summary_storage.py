import json
import tempfile

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage


def main() -> None:
    date = "20260820"

    with tempfile.TemporaryDirectory() as temp_dir:
        path = Path(temp_dir) / "law_summaries.json"

        # REPROCESS_STORAGEの既存JSONをテスト用にコピー
        with open(
            storage.REPROCESS_STORAGE.law_summaries,
            "r",
            encoding="utf-8",
        ) as f:
            original_data = json.load(f)

        with open(path, "w", encoding="utf-8") as f:
            json.dump(
                original_data,
                f,
                ensure_ascii=False,
                indent=2,
            )

        test_paths = storage.StoragePaths(
            laws=Path(temp_dir) / "laws.json",
            law_summaries=path,
            statistics=Path(temp_dir) / "statistics.json",
        )

        print("=== 1. 旧形式の読み込み ===")

        original_summaries = storage.load_law_summaries(
            test_paths
        )
        cached_date = storage.get_law_summaries_date(
            test_paths
        )

        print(f"サマリ件数: {len(original_summaries)}")
        print(f"キャッシュ日付: {cached_date}")

        print()
        print("=== 2. 指定日のキャッシュを準備 ===")

        storage.prepare_law_summaries(
            date=date,
            paths=test_paths,
        )

        summaries = storage.load_law_summaries(test_paths)
        cached_date = storage.get_law_summaries_date(test_paths)

        print(f"キャッシュ日付: {cached_date}")
        print(f"サマリ件数: {len(summaries)}")

        print()
        print("=== 3. 12件を保存 ===")

        storage.save_law_summaries(
            list(original_summaries.values()),
            date=date,
            paths=test_paths,
        )

        summaries = storage.load_law_summaries(test_paths)
        cached_date = storage.get_law_summaries_date(test_paths)

        print(f"キャッシュ日付: {cached_date}")
        print(f"サマリ件数: {len(summaries)}")

        print()
        print("=== 4. 同じ日付でprepare ===")

        storage.prepare_law_summaries(
            date=date,
            paths=test_paths,
        )

        summaries = storage.load_law_summaries(test_paths)
        cached_date = storage.get_law_summaries_date(test_paths)

        print(f"キャッシュ日付: {cached_date}")
        print(f"サマリ件数: {len(summaries)}")

        print()
        print("=== 5. JSON形式 ===")

        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)

        print(f"トップレベル型: {type(data).__name__}")
        print(f"date: {data.get('date')}")
        print(f"summaries: {len(data.get('summaries', []))}件")

        print()
        print("=== テスト完了 ===")


if __name__ == "__main__":
    main()