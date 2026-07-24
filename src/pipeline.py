from detector import detect_new_updates

from law_group import group_by_law

from law_change import build_law_changes

from build_laws import build_laws

from summary.generator import generate_summaries

from storage import (
    save_source_data,
    save_laws,
    save_statistics,
    load_json,
)

from statistics import create_statistics

from email_generator import (
    create_email_subject,
    create_email_body,
)

from config import (
    KEYWORDS_JSON,
    NOTIFY_SOURCES,
)

from mailer import send_email


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

        # DEBUG
        print("--detect new updates--")
        # DEBUG

        new_updates = detect_new_updates(source, all_updates)
    else:
        new_updates = all_updates

    # DEBUG
    print("--save source data--")
    # DEBUG

    save_source_data(source, all_updates)

    # Law View を公開データとして保存
    if source == "egov":

        # DEBUG
        print("--group by law--")
        # DEBUG

        law_groups = group_by_law(all_updates)

        # DEBUG
        print("--build laws--")
        # DEBUG

        laws = build_laws(law_groups)

        # DEBUG
        print("--generate summaries--")
        # DEBUG

        generate_summaries(laws)

        # DEBUG
        print("--save laws--")
        # DEBUG

        save_laws(laws)

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