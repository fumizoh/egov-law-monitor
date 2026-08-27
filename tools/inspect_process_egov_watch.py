from pathlib import Path
import sys

sys.path.insert(
    0,
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

import storage
from pipeline import process_egov
from sources import egov


def main() -> None:
    date = "20260820"

    # 8月20日のe-Gov更新イベント
    events, date = egov.fetch(date=date)

    print(f"更新日: {date}")
    print(f"更新イベント: {len(events)}件")

    # process_egov() を実行
    laws = process_egov(
        events,
        date,
        storage_paths=storage.REPROCESS_STORAGE,
    )

    print(f"今回更新された法令: {len(laws)}件")

    # 3点セットを確認
    saved_laws = storage.load_laws(
        storage.REPROCESS_STORAGE
    )

    summaries = storage.load_law_summaries(
        storage.REPROCESS_STORAGE
    )

    summary_date = storage.get_law_summaries_date(
        storage.REPROCESS_STORAGE
    )

    statistics = storage.load_statistics(
        storage.REPROCESS_STORAGE
    )

    print()
    print("=== 保存結果 ===")
    print(f"laws.json: {len(saved_laws)}件")
    print(f"law_summaries.json: {len(summaries)}件")
    print(f"law_summaries date: {summary_date}")

    print()
    print("statistics.json:")
    pprint(statistics.get("egov"))

    print()
    print("=== law_summaries.json の法令 ===")

    for summary in summaries.values():
        print(
            summary.summary_input.law_id,
            summary.summary_input.law_name,
        )


if __name__ == "__main__":
    main()