import os
import smtplib

from email.message import EmailMessage


def send_email(subject, body):
    """
    メールを送信する。
    """

    smtp_host = os.environ["SMTP_HOST"]
    smtp_port = int(os.environ["SMTP_PORT"])

    smtp_user = os.environ["SMTP_USER"]
    smtp_password = os.environ["SMTP_PASSWORD"]

    mail_from = os.environ["MAIL_FROM"]
    mail_to = os.environ["MAIL_TO"]

    message = EmailMessage()

    message["Subject"] = subject
    message["From"] = mail_from
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

    except Exception as e:

        print(f"メール送信失敗: {e}")
        raise