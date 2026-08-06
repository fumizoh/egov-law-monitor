from notification.generator import (
    create_email_body,
    create_email_subject,
)
from notification.mailer import send_email


def send_update_notification(
    laws,
    update_count,
    date,
) -> None:
    """Send update notification."""

    if not laws:
        print("更新なしのためメール送信をスキップ")
        return

    subject = create_email_subject(
        update_count,
        date,
    )

    body = create_email_body(
        laws,
        update_count,
        date,
    )

    try:
        send_email(subject, body)

    except KeyError as e:
        print("環境変数が設定されていないため、メール送信をスキップ")