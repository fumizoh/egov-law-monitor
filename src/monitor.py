""" monitor.py """

from sources import egov
import pipeline
from sources import public_comment
from notification import service


def main():

    print("=== egov update ===")

    updates, date = egov.fetch()

    laws = pipeline.process_egov(
        updates=updates,
        date=date,
    )

    """
    print("=== public comment ===")

    public_updates, public_date = public_comment.fetch()

    pipeline.process_public_comment(
        updates=public_updates,
        date=public_date,
    )
    """

    print("=== notification ===")

    service.send_update_notification(
        laws=laws,
        update_count=len(updates),
        date=date,
    )


if __name__ == "__main__":
    main()