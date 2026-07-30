from detector import detect_new_updates

from law_group import group_by_law

import law_builder

from storage import (
    save_source_data,
    save_laws,
    save_statistics,
    load_json,
    load_laws,
    save_ai_statistics,
)

from summary.logger import (
    reset_summary_logs,
    load_summary_logs,
)

from statistics import(
    create_statistics,
    create_ai_statistics,
)

from config import (
    KEYWORDS_JSON,
    NOTIFY_SOURCES,
)

from notification.generator import (
    create_email_subject,
    create_email_body,
)

from notification.mailer import send_email


def process(
    source,
    updates,
    date,
):
    """
    Process updates from one source.
    """

    all_updates = updates

    if source == "egov":
        new_updates = detect_new_updates(source, all_updates)
    else:
        new_updates = all_updates

    save_source_data(source, all_updates)

    # Law View を公開データとして保存
    if source == "egov":

        # AI Summary ログ消去
        reset_summary_logs()

        law_groups = group_by_law(all_updates)

        previous_laws = load_laws()

        laws = law_builder.build_laws(
            law_groups,
            previous_laws=previous_laws,
        )

        # DEBUG
        print("Total:", len(laws), "laws")
        # DEBUG

        save_laws(laws)

        # AI Summary ログ集計
        logs = load_summary_logs()

        ai_statistics = create_ai_statistics(logs)

        save_ai_statistics(ai_statistics)

    # 統計情報を作成・保存
    statistics = create_statistics(
        source=source,
        updates=updates,
        latest_date=date,
    )

    save_statistics(
        source=source,
        statistics=statistics,
    )

    print(f"{source}: データ保存・統計更新完了")

    # メール通知対象以外はここで終了
    if source not in NOTIFY_SOURCES:
        return

    # 更新がなければメールを送信しない
    if not updates:
        print("更新なしのためメール送信をスキップ")
        return

    # メール本文を生成
    keywords = load_json(KEYWORDS_JSON)

    subject = create_email_subject(
        updates,
        date,
    )

    body = create_email_body(
        updates,
        keywords,
        date,
    )

    # メール送信
    try:
        send_email(
            subject,
            body,
        )

        print("メール送信完了")

    except KeyError as e:
        print(
            f"環境変数 {e.args[0]} が設定されていないため、"
            "メール送信をスキップ"
        )