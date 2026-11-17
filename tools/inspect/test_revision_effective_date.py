"""Read e-Gov revision history for explicit dates; no writes."""
import argparse
from datetime import date
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from sources.revision import get_revisions_for_effective_date


def iso_date(value):
    try:
        if date.fromisoformat(value).isoformat() != value:
            raise ValueError()
    except ValueError:
        raise argparse.ArgumentTypeError("date must be an existing YYYY-MM-DD")
    return value


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--law-id", required=True)
    parser.add_argument("--date", type=iso_date, action="append", required=True)
    args = parser.parse_args(argv)
    try:
        for effective_date in args.date:
            revisions = get_revisions_for_effective_date(args.law_id, effective_date)
            print(f"date: {effective_date}; count: {len(revisions)}")
            for revision in revisions:
                print(f"law_data_id={revision.law_data_id}, sub_revision={revision.sub_revision}, "
                      f"is_new_law={revision.is_new_law}, enforcement_date={revision.enforcement_date}, "
                      f"scheduled_enforcement_date={revision.scheduled_enforcement_date}")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
