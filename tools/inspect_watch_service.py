from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

import watch.service as watch_service
from storage import load_laws
from wordpress import client


def main() -> None:
    laws = list(load_laws().values())
    watches = client.get_watches()

    print("Watch:")
    pprint(watches)

    print()
    print("laws.json:")
    print(f"{len(laws)}件")

    print()
    print("Watch law_ids:")
    pprint([watch["law_id"] for watch in watches])

    print()
    print("laws law_ids:")
    pprint([law["law_id"] for law in laws])

    print()
    watched_laws = watch_service.find_watched_laws(laws)

    print(f"Watch対象: {len(watched_laws)}件")
    pprint(watched_laws)


if __name__ == "__main__":
    main()