from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import xml.etree.ElementTree as ET

import comparison
import law_change
import toc_parser

from models import RevisionHistory

from sources import compare_api
from sources import toc_api
from sources import egov_xml

from sources.revision import get_revision_history

EXTRACTED_DIR = Path("data/extracted")


def print_revision_history(
    revisions: list[RevisionHistory],
) -> None:
    """Print revision history."""

    print()
    print("Revision History")
    print("=" * 70)

    for index, revision in enumerate(revisions):
        print(
            f"[{index}] "
            f"{revision.enforcement_date} "
            f"{revision.amendment_num} "
            f"{revision.amendment_name}"
        )


def main() -> None:
    if len(sys.argv) < 2:
        print(
            "Usage: "
            "python tools/inspect_xml_context_from_change.py "
            "<law_id> [revision_index]"
        )
        return

    law_id = sys.argv[1]

    revisions = get_revision_history(law_id)

    print_revision_history(revisions)

    revision_index = (
        int(sys.argv[2])
        if len(sys.argv) >= 3
        else 0
    )

    revision = revisions[revision_index]

    print()
    print("=" * 70)
    print("Selected Revision")
    print("=" * 70)
    print(f"Law ID: {law_id}")
    print(f"Index: {revision_index}")
    print(f"Amendment: {revision.amendment_name}")
    print(f"Number: {revision.amendment_num}")
    print(f"Enforcement date: {revision.enforcement_date}")
    print(f"Law data ID: {revision.law_data_id}")
    print(f"Sub revision: {revision.sub_revision}")

    if revision.is_new_law:
        print("This revision is a new law.")
        return

    print()
    print("Fetching Compare API...")

    compare_json = compare_api.fetch_compare(
        new_law_data_id=revision.law_data_id,
        new_sub_revision=revision.sub_revision,
    )

    if compare_json is None:
        print("Compare API returned no data.")
        return

    compare_result = comparison.parse_compare_result(
        compare_json
    )

    if compare_result is None:
        print("Compare result is empty.")
        return

    toc_json = toc_api.fetch_law_toc(
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
    )

    index = toc_parser.parse_toc(
        toc_json["result"]["Toc_Data"]["TocBody"]
    )

    changes = law_change.build_law_changes(
        compare_result,
        index,
    )

    print(f"Law changes: {len(changes)}")

    xml_path = egov_xml.find_xml(
        law_id,
        revision,
    )

    print(f"XML: {xml_path}")

    print()
    print("=" * 70)
    print("Law Changes + XML Context")
    print("=" * 70)

    for number, change in enumerate(changes, start=1):
        if change.change_type != "added":
            continue

        # 条レベルだけを対象にする
        if change.location.paragraph or change.location.item:
            continue

        caption, provision_text = egov_xml.get_provision_context(
            xml_path,
            change.location,
        )

        print()
        print("-" * 70)
        print(f"Change {number}")
        print(f"Object ID: {change.object_id}")
        print(f"Location: {change.location.label}")
        print(f"Change type: {change.change_type}")

        print()
        print("Before:")
        print(change.before or "(none)")

        print()
        print("After:")
        print(change.after or "(none)")

        print()
        print("Article Caption:")
        print(caption or "(none)")

        print()
        print("Provision Text:")
        print(provision_text or "(none)")


if __name__ == "__main__":
    main()