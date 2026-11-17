"""Run e-Gov pipeline in automatically cleaned temporary storage; no posts/mail/AI."""
import argparse
from datetime import datetime
from pathlib import Path
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from sources import egov, egov_bulk
import pipeline
import storage


def update_date(value):
    try:
        if len(value) != 8 or datetime.strptime(value, "%Y%m%d").strftime("%Y%m%d") != value:
            raise ValueError()
    except (ValueError, TypeError):
        raise argparse.ArgumentTypeError("date must be an existing YYYYMMDD")
    return value


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--date", type=update_date, help="omit to query the latest e-Gov update")
    args = parser.parse_args(argv)
    try:
        with tempfile.TemporaryDirectory(prefix="egov-inspection-") as temporary:
            root = Path(temporary)
            paths = storage.StoragePaths(*(root / name for name in (
                "laws.json", "law_summaries.json", "statistics.json", "watch_status.json")))
            previous_downloads, previous_extracts = egov_bulk.DOWNLOAD_DIR, storage.EXTRACT_DIR
            try:
                # Limit both fetch artifacts and pipeline output to this invocation.
                egov_bulk.DOWNLOAD_DIR = root / "downloads"
                storage.EXTRACT_DIR = root / "extracted"
                events, day = egov.fetch(date=args.date)
                laws = pipeline.process_egov(events=events, date=day, storage_paths=paths)
                print(f"date: {day}; events: {len(events)}; laws: {len(laws)}")
                print("All generated files are temporary and will be removed.")
            finally:
                egov_bulk.DOWNLOAD_DIR, storage.EXTRACT_DIR = previous_downloads, previous_extracts
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
