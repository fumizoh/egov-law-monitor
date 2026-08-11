""" monitor.py """

from datetime import datetime, timedelta, timezone

JST = timezone(timedelta(hours=9))

from sources import egov
import pipeline
import storage
from notification import service


def main():

    print("=== egov update ===")

    updates, date = egov.fetch()

    statistics = storage.load_statistics()
    last_update = statistics.get("egov", {}).get("last_update")

    if last_update == date:
        print(f"新しい法令更新なし: {date}")

        today = datetime.now(JST).strftime("%Y%m%d")

        print("=== notification ===")

        service.send_update_notification(
            laws=[],
            update_count=0,
            date=today,
        )

        return

    laws = pipeline.process_egov(
        updates=updates,
        date=date,
    )

    print("=== notification ===")

    service.send_update_notification(
        laws=laws,
        update_count=len(updates),
        date=date,
    )


if __name__ == "__main__":
    main()