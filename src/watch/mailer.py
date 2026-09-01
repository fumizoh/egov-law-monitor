import logging
import os
import smtplib

from email.message import EmailMessage


logger = logging.getLogger(__name__)


def _get_smtp_config() -> tuple[str, int, str, str, str] | None:
    """Get SMTP configuration from environment variables."""

    smtp_host = os.environ.get("SMTP_HOST")
    smtp_port = os.environ.get("SMTP_PORT")

    smtp_user = os.environ.get("SMTP_USER")
    smtp_password = os.environ.get("SMTP_PASSWORD")

    mail_from = os.environ.get("MAIL_FROM")

    if not all(
        [
            smtp_host,
            smtp_port,
            smtp_user,
            smtp_password,
            mail_from,
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
    )


def send_email(
    to: str,
    subject: str,
    body: str,
    html_body: str | None = None,
) -> None:
    """Send watch notification email."""

    config = _get_smtp_config()

    if config is None:
        return

    (
        smtp_host,
        smtp_port,
        smtp_user,
        smtp_password,
        mail_from,
    ) = config

    message = EmailMessage()

    message["Subject"] = subject
    message["From"] = mail_from
    message["To"] = to

    message.set_content(body)

    if html_body is not None:
        message.add_alternative(
            html_body,
            subtype="html",
        )

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