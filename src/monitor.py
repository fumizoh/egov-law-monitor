from sources.egov import fetch

from storage import (
    save_updates,
    save_statistics,
    load_json,
)

from summary import create_statistics

from email_generator import (
    create_email_subject,
    create_email_body,
)

from config import KEYWORDS_JSON

from mailer import send_email

def main():

    updates, date = fetch()

    save_updates(updates)

    statistics = create_statistics(
        updates,
        date,
    )

    save_statistics(statistics)

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

    if updates:

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

    else:

        print("更新なしのためメール送信をスキップ")

    print("JSON保存完了")


if __name__ == "__main__":
    main()