""" monitor.py """

import logging
import sys
from datetime import datetime, timedelta, timezone

from models import (
    ProcessingResult,
    WatchNotificationResult,
    WPResult,
)

from sources import egov
import pipeline
import storage
from notification import service
from wordpress import service as wordpress_service
import watch.service as watch_service
import watch.email as watch_email
import watch.mailer as watch_mailer


logging.basicConfig(
    level=logging.INFO,
    format="%(levelname)s: %(message)s",
)

logger = logging.getLogger(__name__)

JST = timezone(timedelta(hours=9))


def send_watch_notifications(
    laws,
    storage_paths,
    date,
) -> WatchNotificationResult:
    """Send watch notifications to users."""

    law_summaries = storage.load_law_summaries(storage_paths)
    watch_users = watch_service.get_watch_users()

    target_count = 0
    sent_count = 0
    failed_count = 0

    for user in watch_users:
        if not user.notifications:
            logger.info(
                "Watch notifications: user_id=%d, notifications=OFF",
                user.user_id,
            )
            continue

        try:
            notifications = watch_service.build_user_notifications(
                laws,
                list(law_summaries.values()),
                user,
            )

            if not notifications:
                continue

            target_count += 1

            logger.info(
                "Watch notifications: user_id=%d, count=%d",
                user.user_id,
                len(notifications),
            )

            subject = watch_email.build_subject(
                notifications,
            )

            body = watch_email.build_body(
                notifications,
                user.watches,
                date,
            )

            html_body = watch_email.build_html(
                notifications,
                user.watches,
                date,
            )

            watch_mailer.send_email(
                user.email,
                subject,
                body,
                html_body=html_body,
            )

            sent_count += 1

        except Exception:
            failed_count += 1

            logger.exception(
                "Watch notification error: user_id=%d",
                user.user_id,
            )

    return WatchNotificationResult(
        target_count=target_count,
        sent_count=sent_count,
        failed_count=failed_count,
    )


def main(date: str | None = None):
    logger.info("=== egov update ===")

    specified_date = date

    if specified_date is None:
        storage_paths = storage.DEFAULT_STORAGE
    else:
        storage_paths = storage.REPROCESS_STORAGE

    events, date = egov.fetch(date=specified_date)

    statistics = storage.load_statistics()
    last_update = statistics.get("egov", {}).get("last_update")

    if specified_date is None and last_update == date:
        logger.info("新しい法令更新なし: %s", date)

        today = datetime.now(JST).strftime("%Y%m%d")

        result = ProcessingResult(
            date=today,
            update_count=0,
            updated_law_count=0,
            laws=[],
            wp=None,
        )

    else:
        logger.info("=== pipeline ===")

        laws = pipeline.process_egov(
            events=events,
            date=date,
            storage_paths=storage_paths,
        )

        logger.info("=== record last checked ===")

        storage.save_watch_status(
            {
                "last_checked": datetime.now(JST).isoformat(),
            },
            paths=storage_paths,
        )

        logger.info("=== WordPress ===")

        try:
            wp_result = wordpress_service.sync_daily_post(
                date=date,
                storage_paths=storage_paths,
            )

            logger.info(
                "WordPress: %s (%s) post_id=%s status=%s",
                wp_result.status,
                wp_result.action,
                wp_result.post_id,
                wp_result.post_status,
            )

        except Exception as e:
            logger.exception("WordPress error")

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

        logger.info("=== watch notification ===")

        if specified_date is not None:
            logger.info(
                "日付指定による再処理のため、法令ウォッチ通知をスキップします。"
            )
        elif wp_result.status == "success":
            result.watch = send_watch_notifications(
                laws=laws,
                storage_paths=storage_paths,
                date=date,
            )
        else:
            logger.warning(
                "WordPress投稿に失敗したため、法令ウォッチ通知をスキップします。"
            )

    logger.info("=== notification ===")

    try:
        service.send_processing_notification(
            result=result,
        )
    except Exception:
        logger.exception("Processing notification error")


if __name__ == "__main__":
    date = sys.argv[1] if len(sys.argv) > 1 and sys.argv[1] else None
    main(date)