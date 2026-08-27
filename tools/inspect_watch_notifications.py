from pprint import pprint

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources import egov
import law_builder
import law_group
import storage
import watch.service as watch_service


def main() -> None:
    # 2026-08-20 のe-Gov更新を取得
    events, date = egov.fetch(date="20260820")

    print(f"更新日: {date}")
    print(f"更新イベント: {len(events)}件")

    # process_egov() と同じ流れでLawを構築
    law_groups = law_group.group_by_law(events)
    law_groups = law_builder.sort_law_groups(law_groups)

    laws = law_builder.build_laws(law_groups)

    print(f"今回更新された法令: {len(laws)}件")

    # 8月20日の再処理で生成したAIサマリを読み込む
    law_summaries = list(
        storage.load_law_summaries(
            paths=storage.REPROCESS_STORAGE,
        ).values()
    )

    print(f"保存済みAIサマリ: {len(law_summaries)}件")

    # WatchNotificationを構築
    notifications = watch_service.build_notifications(
        laws=laws,
        law_summaries=law_summaries,
    )

    print(f"Watch通知: {len(notifications)}件")

    for notification in notifications:
        print()
        print("=" * 60)

        law = notification.law
        summary = notification.summary

        print(f"法令名: {law['law_name']}")
        print(f"law_id: {law['law_id']}")
        print(f"法令番号: {law['law_no']}")
        print(f"法令種別: {law['law_type']}")

        print()

        if summary is None:
            print("AIサマリ: なし")
        else:
            print("AIサマリ:")
            print(f"タイトル: {summary.title}")
            print("本文:")
            print(summary.body)

        print()
        print("更新情報:")
        pprint(law["updates"])


if __name__ == "__main__":
    main()