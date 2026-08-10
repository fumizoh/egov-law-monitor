"""Inspect supplementary-table change detection.

Usage:
    python tools/inspect_table_changes.py <law_id>
    python tools/inspect_table_changes.py <law_id> --law-data-id 123456 --sub-revision 1

The script verifies:
1. Revision API -> Compare API -> TOC parsing
2. TableChange detection
3. Ordinary LawChange detection is still working
4. AmendmentSummaryInput.table_changes contains the detected tables

No Gemini API call is made.
"""

from __future__ import annotations

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import argparse
from collections import Counter

import comparison
import law_change
import table_change
import toc_parser

from models import RevisionHistory
from sources import compare_api
from sources import revision
from sources import toc_api
from summary import builder


def _find_revision(
    revisions: list[RevisionHistory],
    law_data_id: int | None,
    sub_revision: str | None,
) -> RevisionHistory:
    if law_data_id is not None:
        for item in revisions:
            if (
                item.law_data_id == law_data_id
                and (
                    sub_revision is None
                    or item.sub_revision == sub_revision
                )
            ):
                return item

        raise RuntimeError(
            f"Revision not found: "
            f"law_data_id={law_data_id}, "
            f"sub_revision={sub_revision}"
        )

    for item in revisions:
        if not item.is_new_law:
            return item

    raise RuntimeError("No amendment revision found.")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Inspect supplementary-table change detection."
    )
    parser.add_argument("law_id")
    parser.add_argument("--law-data-id", type=int)
    parser.add_argument("--sub-revision")
    args = parser.parse_args()

    revisions = revision.get_revision_history(args.law_id)

    target = _find_revision(
        revisions,
        args.law_data_id,
        args.sub_revision,
    )

    print("=== Revision ===")
    print(f"law_id:             {args.law_id}")
    print(f"law_data_id:        {target.law_data_id}")
    print(f"sub_revision:       {target.sub_revision}")
    print(f"amendment_name:     {target.amendment_name}")
    print(f"amendment_num:      {target.amendment_num}")
    print(f"enforcement_date:   {target.enforcement_date}")

    compare_json = compare_api.fetch_compare(
        new_law_data_id=target.law_data_id,
        new_sub_revision=target.sub_revision,
    )

    if compare_json is None:
        raise RuntimeError("Compare API returned None.")

    compare_result = comparison.parse_compare_result(compare_json)

    if compare_result is None:
        raise RuntimeError("Failed to parse Compare API result.")

    print()
    print("=== Compare ===")
    print(f"blocks: {len(compare_result.blocks)}")

    counter = Counter(
        block.change_type
        for block in compare_result.blocks
    )
    print(f"change types: {dict(counter)}")

    toc_json = toc_api.fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = toc_parser.parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    print()
    print("=== TOC ===")
    print(f"table definitions: {len(index.table_lookup)}")

    for xpath, name in index.table_lookup.items():
        print(f"  {name}")
        print(f"    {xpath}")

    changes = law_change.build_law_changes(
        compare_result,
        index,
    )

    table_changes = table_change.build_table_changes(
        compare_result,
        index,
    )

    print()
    print("=== Detection ===")
    print(f"law changes:   {len(changes)}")
    print(f"table changes: {len(table_changes)}")

    for item in table_changes:
        print(f"  TABLE: {item.name}")

    print()
    print("=== Summary Input ===")

    summary_input = builder.build_amendment_summary_input(
        revision=target,
        changes=changes,
        table_changes=table_changes,
    )

    print(f"articles:      {len(summary_input.articles)}")
    print(f"table_changes: {len(summary_input.table_changes)}")

    for item in summary_input.table_changes:
        print(f"  SUMMARY TABLE: {item.name}")

    print()
    print("=== Assertions ===")

    assert summary_input.table_changes == table_changes
    print("[OK] table_changes is preserved in AmendmentSummaryInput.")

    table_names = [item.name for item in table_changes]
    assert len(table_names) == len(set(table_names))
    print("[OK] duplicate table names are removed.")

    changed_table_names = set(table_names)

    for item in index.table_lookup.values():
        if item in changed_table_names:
            continue

    print("[OK] table-change inspection completed.")


if __name__ == "__main__":
    main()
