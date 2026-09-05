"""Repost a daily update to WordPress."""

from pathlib import Path
import sys

sys.path.insert(
    0,
    str(Path(__file__).resolve().parents[1] / "src")
)

from wordpress import storage
from wordpress.service import sync_daily_post


def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python tools/repost_wordpress.py YYYYMMDD")
        sys.exit(1)

    date = sys.argv[1]

    if len(date) != 8 or not date.isdigit():
        print("日付はYYYYMMDD形式で指定してください。")
        sys.exit(1)

    storage_paths = storage.find_storage_for_date(date)

    if storage_paths is None:
        print(f"指定した日付のデータが見つかりません: {date}")
        sys.exit(1)

    result = sync_daily_post(
        date,
        storage_paths=storage_paths,
    )

    print("=== WordPress repost ===")
    print(f"date: {date}")
    print(f"status: {result.status}")
    print(f"action: {result.action}")
    print(f"post_id: {result.post_id}")
    print(f"link: {result.link}")


if __name__ == "__main__":
    main()