""" monitor.py """

from sources import egov
import pipeline
from notification import service


def main():

    print("=== egov update ===")

    updates, date = egov.fetch()

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