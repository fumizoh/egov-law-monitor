from storage import (
    save_source_data,
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

from law_model import build_laws


def process(
    source,
    updates,
    date,
):
    """
    Process updates from one source.
    """

    # CSV行データから内部データモデルを構築
    laws = build_laws(updates)

    # 更新情報を保存
    save_source_data(
        source=source,
        data=laws,
    )

    # 統計情報を作成・保存
    statistics = create_statistics(
        source=source,
        updates=laws,
        latest_date=date,
    )

    save_statistics(
        source=source,
        statistics=statistics,
    )

    print(f"{source}: 保存・統計更新完了")

    # メール通知対象以外はここで終了
    if source not in NOTIFY_SOURCES:
        return

    # 更新がなければメールを送信しない
    if not laws:
        print("更新なしのためメール送信をスキップ")
        return

    # メール本文を生成
    keywords = load_json(KEYWORDS_JSON)

    subject = create_email_subject(
        laws,
        date,
    )

    body = create_email_body(
        laws,
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