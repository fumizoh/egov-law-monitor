"""Watch service."""

from models import Watch

from watch import storage


def get_watches() -> list[Watch]:
    """Get all watched laws."""

    return storage.load_watches()


def add_watch(law_id: str) -> None:
    """Add a law to the watch list."""

    watches = storage.load_watches()

    if any(watch.law_id == law_id for watch in watches):
        return

    watches.append(
        Watch(law_id=law_id)
    )

    storage.save_watches(watches)


def remove_watch(law_id: str) -> None:
    """Remove a law from the watch list."""

    watches = storage.load_watches()

    watches = [
        watch
        for watch in watches
        if watch.law_id != law_id
    ]

    storage.save_watches(watches)


def is_watched(law_id: str) -> bool:
    """Return True if the law is being watched."""

    return any(
        watch.law_id == law_id
        for watch in storage.load_watches()
    )