from pathlib import Path
import sys

PROJECT_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = PROJECT_ROOT / "src"

sys.path.insert(0, str(SRC_DIR))

from sources.egov import fetch

updates, date = fetch()

print(f"Update date: {date}")
print(f"Items: {len(updates)}")
print()

print(updates[0])