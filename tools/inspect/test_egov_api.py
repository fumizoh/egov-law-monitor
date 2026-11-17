"""Read e-Gov API v2 revisions/text and inspect provision extraction; no writes."""
import argparse
from datetime import date
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from models import Location, RevisionHistory
from sources.egov_api import fetch_law_data, find_revision_id, get_provision_text


def iso_date(value):
    try:
        if date.fromisoformat(value).isoformat() != value:
            raise ValueError()
    except ValueError:
        raise argparse.ArgumentTypeError("effective-date must be an existing YYYY-MM-DD")
    return value


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--law-id", required=True)
    parser.add_argument("--effective-date", type=iso_date, required=True)
    parser.add_argument("--amendment-id", help="omit for an initial enactment")
    parser.add_argument("--article")
    parser.add_argument("--paragraph")
    parser.add_argument("--item")
    args = parser.parse_args(argv)
    if (args.paragraph or args.item) and not args.article:
        parser.error("--paragraph/--item require --article")
    locations = [Location(args.article, args.paragraph, args.item)] if args.article else [
        Location("第1条"), Location("第1条", "第二項"), Location("第1条", "第一項", "第三号")]
    revision = RevisionHistory(0, "", args.amendment_id, None, None, args.effective_date, None, None, True)
    try:
        revision_id = find_revision_id(args.law_id, revision)
        data = fetch_law_data(revision_id)
        print(f"revision_id: {revision_id}; top-level keys: {sorted(data)}")
        for location in locations:
            caption, text = get_provision_text(data, location)
            print(f"[{location.label}] caption: {caption}")
            print(f"provision_text: {text}")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
