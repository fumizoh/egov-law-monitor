from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.egov import fetch
from sources.revision_api import fetch_revisions
from sources.compare_api import fetch_compare
from sources.toc_api import fetch_law_toc
from comparison import parse_revision_history, parse_compare_result
from revision import find_revision
from toc_parser import parse_toc
from lawchange_builder import build_law_changes

updates, date = fetch()

update = updates[0]

print(update)

revisions = parse_revision_history(
    fetch_revisions(
        update["metadata"]["law_id"]
    )
)

revision = find_revision(
    update["metadata"]["amend_number"],
    revisions,
)


print(update["metadata"]["amend_name"])
print(update["metadata"]["amend_number"])

print()

print(revision)


raw = fetch_compare(
    revision.law_data_id,
    revision.sub_revision,
)

compare = parse_compare_result(raw)

print()

print(compare.old)
print(compare.new)
print(f"Blocks: {len(compare.blocks)}")
print(compare.blocks[0])

toc = fetch_law_toc(
    revision.law_data_id,
    revision.sub_revision,
)

index = parse_toc(toc)

changes = build_law_changes(compare, index)

print()

print(f"Changes: {len(changes)}")
print(changes[0])

print()

missing = 0

for block in compare.blocks:
    location = index.location_lookup.get(block.object_id)

    if location is None:
        missing += 1
        print(block.object_id)
        continue
