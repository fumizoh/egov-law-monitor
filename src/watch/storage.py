"""Watch data storage."""

from pathlib import Path

from config import WATCH_JSON
from models import Watch
import storage


def load_watches(
    path: Path = WATCH_JSON,
) -> list[Watch]:
    """Load watched laws."""

    if not path.exists():
        return []

    data = storage.load_json(path)

    return [
        Watch(**item)
        for item in data
    ]


def save_watches(
    watches: list[Watch],
    path: Path = WATCH_JSON,
) -> None:
    """Save watched laws."""

    storage.save_json(
        watches,
        path,
    )