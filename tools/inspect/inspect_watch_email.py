"""Preview watch email from local data; no WordPress/SMTP/API calls."""
import argparse
from datetime import datetime
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))
import storage
from models import WatchSetting
from watch import email, service


def update_date(value):
    try:
        if len(value) != 8 or datetime.strptime(value, "%Y%m%d").strftime("%Y%m%d") != value:
            raise ValueError()
    except (ValueError, TypeError):
        raise argparse.ArgumentTypeError("date must be an existing YYYYMMDD")
    return value


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--keyword", required=True, action="append")
    parser.add_argument("--storage", choices=("normal", "reprocess"), default="normal")
    parser.add_argument("--date", type=update_date, help="defaults to the selected saved statistics date")
    parser.add_argument("--output", type=Path, help="optional NEW HTML file outside the repository; parent must exist")
    args = parser.parse_args(argv)
    if any(not keyword.strip() for keyword in args.keyword):
        parser.error("keywords must not be empty")
    output = args.output.resolve() if args.output else None
    if output and (output.is_relative_to(ROOT) or output.exists() or not output.parent.is_dir()):
        parser.error("output must be a new file outside the repository with an existing parent")
    paths = storage.DEFAULT_STORAGE if args.storage == "normal" else storage.REPROCESS_STORAGE
    try:
        day = args.date or update_date(storage.load_statistics(paths).get("egov", {}).get("last_update"))
        laws = list(storage.load_laws(paths).values())
        summaries = list(storage.load_law_summaries(paths).values())
        watches = [WatchSetting(keyword.strip()) for keyword in args.keyword]
        matches = service.find_watched_laws(laws, watches)
        notifications = service.build_notifications(matches, summaries)
        print(f"preview_date: {day}; keyword_match_count: {len(matches)}; no messages sent")
        print(email.build_subject(notifications))
        print(email.build_body(notifications, watches, day))
        if output:
            # Exclusive create: no existing file is overwritten, including a race.
            with output.open("x", encoding="utf-8") as stream:
                stream.write(email.build_html(notifications, watches, day))
            print(f"HTML preview saved: {output}")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
