"""Resend law watch notifications for a specific date."""

from pathlib import Path
import sys

sys.path.insert(
    0,
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage
from monitor import send_watch_notifications


def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python tools/resend_watch_notification.py YYYYMMDD")
        sys.exit(1)

    date = sys.argv[1]

    if len(date) != 8 or not date.isdigit():
        print("日付はYYYYMMDD形式で指定してください。")
        sys.exit(1)

    storage_paths = storage.find_storage_for_date(date)

    if storage_paths is None:
        print(f"指定した日付のデータが見つかりません: {date}")
        sys.exit(1)

    send_watch_notifications(
        laws=storage.load_laws(storage_paths),
        storage_paths=storage_paths,
        date=date,
    )

    print("=== Watch notification resend ===")
    print(f"date: {date}")
    print("status: success")


if __name__ == "__main__":
    main()