from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.revision_api import fetch_revisions

import comparison

from pprint import pprint


LAW_ID = "427AC0000000053"

LAW_NAME = "建築物のエネルギー消費性能の向上等に関する法律"

raw = fetch_revisions(LAW_ID)

print("=== fetch_revisiions ===")
print("LAW_ID:", LAW_ID)
print("LAW_NAME:", LAW_NAME)
print(len(raw["result"]["Amendment_History"]), "revisions")
pprint(raw)


revisions = comparison.parse_revision_history(
    raw["result"]["Amendment_History"]
)

for revision in revisions:
    print(revision)
    print(f"is_new_law = {revision.is_new_law}")