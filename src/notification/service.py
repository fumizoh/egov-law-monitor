from config import KEYWORDS_JSON
from notification.generator import (
    create_email_body,
    create_email_subject,
)
from notification.mailer import send_email
from storage import load_json


def send_update_notification(
    updates,
    date,
) -> None:
    """Send update notification."""

    if not updates:
        print("更新なしのためメール送信をスキップ")
        return

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

    try:
        send_email(subject, body)
        print("メール送信完了")

    except KeyError as e:
        print(
            f"環境変数 {e.args[0]} が設定されていないため、"
            "メール送信をスキップ"
        )