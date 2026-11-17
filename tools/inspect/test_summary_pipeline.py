"""Manual e-Gov diagnostics; network only when main() is explicitly invoked.

python tools/inspect/test_summary_pipeline.py --law-data-id 637185 --sub-revision 1 [--details]
Exit: 0 success, 1 failure, 2 invalid arguments, 3 Compare data unavailable,
4 missing/null/empty blocks (no downstream comparison to diagnose).
"""
import argparse
import json
from pathlib import Path
import re
import sys
import traceback

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))

import comparison
import law_change
import table_change
import toc_parser
from sources import compare_api, toc_api


def positive_id(value):
    if not re.fullmatch(r"[0-9]+", value) or int(value) < 1:
        raise argparse.ArgumentTypeError("law-data-id must be a positive integer")
    return int(value)


def revision_id(value):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", value):
        raise argparse.ArgumentTypeError("sub-revision must be a nonempty revision identifier")
    return value


def describe_blocks(raw, details):
    data = raw["result"]["Compare_Data"]
    if not isinstance(data, dict):
        raise ValueError("Compare_Data must be an object")
    info = data["CompareInfo"]
    if not isinstance(info, dict):
        raise ValueError("CompareInfo must be an object")
    if details:
        print("Compare_Data keys:", sorted(data))
        print("CompareInfo keys:", sorted(info))
    if "CompareBlock" not in info:
        print("CompareBlock: MISSING (item not present)")
        return False
    blocks = info["CompareBlock"]
    if blocks is None:
        print("CompareBlock: NULL")
        return False
    if not isinstance(blocks, list):
        raise ValueError("CompareBlock must be an array")
    if not blocks:
        print("CompareBlock: EMPTY (count: 0)")
        return False
    print(f"CompareBlock count: {len(blocks)}")
    if details:
        for number, block in enumerate(blocks[:5], 1):
            print(f"--- block {number} ---")
            print(json.dumps(block, ensure_ascii=False, indent=2))
        if len(blocks) > 5:
            print(f"... {len(blocks) - 5} more blocks (not displayed)")
    return True


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--law-data-id", type=positive_id, default=637185)
    parser.add_argument("--sub-revision", type=revision_id, default="1")
    parser.add_argument("--details", action="store_true", help="show structure and up to five raw blocks")
    args = parser.parse_args(argv)
    print(f"Summary pipeline inspection: law_data_id={args.law_data_id}, sub_revision={args.sub_revision}")
    stage = "[1] Compare API"
    try:
        print(stage)
        # fetch_compare() normally fetches this TOC internally and discards it.
        # Use its existing request helper so both API responses can be reused here.
        toc_json = toc_api.fetch_law_toc(args.law_data_id, args.sub_revision)
        toc_body = toc_json["result"]["Toc_Data"]["TocBody"]
        selections = toc_parser.build_sel_text_list(toc_body)
        raw = compare_api._request_compare(args.law_data_id, args.sub_revision, selections)
        print("OK")

        stage = "[2] Parse compare result"
        print(stage)
        if not describe_blocks(raw, args.details):
            print("No comparison blocks; downstream steps not executed.")
            return 4
        result = comparison.parse_compare_result(raw)
        if result is None:
            raise ValueError("compare_result is None despite nonempty blocks")
        if str(result.new.law_data_id) != str(args.law_data_id) or str(result.new.sub_revision) != args.sub_revision:
            raise ValueError("Compare response revision does not match the requested TOC")
        print(f"OK: new law_data_id={result.new.law_data_id}, sub_revision={result.new.sub_revision}")

        stage = "[3] Fetch TOC"
        print(stage)
        print("OK (reused TOC; no additional request)")

        stage = "[4] Parse TOC"
        print(stage)
        index = toc_parser.parse_toc(toc_body)
        print(f"OK: index type: {type(index).__name__}")

        stage = "[5] Build law changes"
        print(stage)
        changes = law_change.build_law_changes(result, index)
        print(f"OK: changes: {len(changes)}")

        stage = "[6] Build table changes"
        print(stage)
        table_changes = table_change.build_table_changes(result, index)
        print(f"OK: table_changes: {len(table_changes)}")
    except compare_api.CompareDataNotFoundError as exc:
        print(f"{stage}: CompareDataNotFoundError: {exc}", file=sys.stderr)
        return 3
    except Exception as exc:
        print(f"{stage}: FAILED: {type(exc).__name__}: {exc}", file=sys.stderr)
        if args.details:
            traceback.print_exc()
        return 1
    print("All inspection steps completed successfully.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
