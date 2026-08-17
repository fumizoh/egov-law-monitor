""" monitor.py """

from datetime import datetime, timedelta, timezone

JST = timezone(timedelta(hours=9))

from models import ProcessingResult, WPResult
from sources import egov
import pipeline
import storage
from notification import service
from wordpress import service as wordpress_service


def main():
    print("=== egov update ===")

    events, date = egov.fetch()

    statistics = storage.load_statistics()
    last_update = statistics.get("egov", {}).get("last_update")

    if last_update == date:
        print(f"新しい法令更新なし: {date}")

        today = datetime.now(JST).strftime("%Y%m%d")

        result = ProcessingResult(
            date=today,
            update_count=0,
            updated_law_count=0,
            laws=[],
            wp=None,
        )

        print("=== notification ===")

        service.send_processing_notification(
            result=result,
        )

        return

    print("=== pipeline ===")

    laws = pipeline.process_egov(
        events=events,
        date=date,
    )

    print("=== WordPress ===")

    try:
        wp_result = wordpress_service.sync_daily_post(
            date=date,
        )

        print(
            f"WordPress: {wp_result.status} "
            f"({wp_result.action}) "
            f"post_id={wp_result.post_id} "
            f"status={wp_result.post_status}"
        )

    except Exception as e:
        print(f"WordPress error: {e}")

        wp_result = WPResult(
            status="error",
            error=str(e),
        )

    result = ProcessingResult(
        date=date,
        update_count=len(events),
        updated_law_count=len(laws),
        laws=laws,
        wp=wp_result,
    )

    print("=== notification ===")

    service.send_processing_notification(
        result=result,
    )


if __name__ == "__main__":
    main()