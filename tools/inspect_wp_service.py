"""Inspect WordPress publishing service."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage

from wordpress.service import sync_daily_post


def main() -> None:
    """Sync the latest daily post to WordPress."""

    statistics = storage.load_statistics()
    date = statistics["egov"]["last_update"]

    result = sync_daily_post(date)

    print("WordPress sync completed.")
    print(f"status:      {result.status}")
    print(f"action:      {result.action}")
    print(f"post_id:     {result.post_id}")
    print(f"post_status: {result.post_status}")
    print(f"link:        {result.link}")


if __name__ == "__main__":
    main()