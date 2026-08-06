from sources.egov import fetch as fetch_egov
from sources.public_comment import fetch as fetch_public_comment

from pipeline import (
    process_egov,
    process_public_comment,
)

import notification.service as notification_service

def main():

    print("=== egov update ===")

    updates, date = fetch_egov()

    laws = process_egov(
        updates=updates,
        date=date,
    )

    print("=== public comment ===")

    public_updates, public_date = fetch_public_comment()

    process_public_comment(
        updates=public_updates,
        date=public_date,
    )

    print("=== notification ===")

    notification_service.send_update_notification(
        laws=laws,
        update_count=len(updates),
        date=date,
    )


if __name__ == "__main__":
    main()