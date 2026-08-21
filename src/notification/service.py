import logging

from models import ProcessingResult

from notification.generator import (
    create_email_body,
    create_email_subject,
)

from notification.mailer import send_email

logger = logging.getLogger(__name__)


def send_processing_notification(
    result: ProcessingResult,
) -> None:
    """Send processing result notification."""

    subject = create_email_subject(result)
    body = create_email_body(result)

    try:
        send_email(subject, body)

    except KeyError:
        logger.info(
            "環境変数が設定されていないため、メール送信をスキップ"
        )