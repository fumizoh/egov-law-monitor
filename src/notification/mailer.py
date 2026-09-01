import logging
import os
import smtplib

from email.message import EmailMessage
from email.headerregistry import Address


logger = logging.getLogger(__name__)


def _get_smtp_config() -> tuple[str, int, str, str, str, str] | None:
    """Get SMTP configuration from environment variables."""

    smtp_host = os.environ.get("SMTP_HOST")
    smtp_port = os.environ.get("SMTP_PORT")

    smtp_user = os.environ.get("SMTP_USER")
    smtp_password = os.environ.get("SMTP_PASSWORD")

    mail_from = os.environ.get("MAIL_FROM")
    mail_to = os.environ.get("MAIL_TO")

    if not all(
        [
            smtp_host,
            smtp_port,
            smtp_user,
            smtp_password,
            mail_from,
            mail_to,
        ]
    ):
        logger.warning(
            "SMTP configuration is incomplete. "
            "Skip sending email."
        )
        return None

    return (
        smtp_host,
        int(smtp_port),
        smtp_user,
        smtp_password,
        mail_from,
        mail_to,
    )


def send_email(subject, body):
    """
    メールを送信する。
    """

    config = _get_smtp_config()

    if config is None:
        return

    (
        smtp_host,
        smtp_port,
        smtp_user,
        smtp_password,
        mail_from,
        mail_to,
    ) = config

    message = EmailMessage()

    message["Subject"] = subject
    message["From"] = Address(
        display_name="e-Gov Law Monitor",
        addr_spec=mail_from,
    )
    message["To"] = mail_to

    message.set_content(body)

    try:
        with smtplib.SMTP_SSL(
            smtp_host,
            smtp_port,
        ) as smtp:
            smtp.login(
                smtp_user,
                smtp_password,
            )

            smtp.send_message(message)

        print("メール送信完了")

    except Exception:
        logger.exception("メール送信失敗")
        raise