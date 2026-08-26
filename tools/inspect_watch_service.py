from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from watch.service import add_watch, get_watches, is_watched, remove_watch

add_watch("322AC0000000003")

print(get_watches())
print(is_watched("322AC0000000003"))

remove_watch("322AC0000000003")

print(get_watches())