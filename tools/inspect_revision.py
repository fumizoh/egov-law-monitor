from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.revision_api import fetch_revisions

from pprint import pprint


LAW_ID = "423AC0000000125"

raw = fetch_revisions(LAW_ID)

print()
print("=== Law ===")
print(len(raw))
print(raw)
