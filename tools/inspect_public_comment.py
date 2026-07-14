from pathlib import Path
import sys

PROJECT_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = PROJECT_ROOT / "src"

sys.path.insert(0, str(SRC_DIR))

from sources.public_comment import fetch

updates, _ = fetch()

print(f"{len(updates)} items")

print()

print(updates[0])