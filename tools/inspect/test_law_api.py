"""Read a law name from e-Gov; no writes. See README.md."""
import argparse
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from sources.law_api import fetch_law_name


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--law-id", required=True)
    args = parser.parse_args(argv)
    try:
        print(f"law_id: {args.law_id}")
        print(f"law_name: {fetch_law_name(args.law_id)}")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
