from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

from wordpress.client import get_watches


def main() -> None:
    watches = get_watches()
    pprint(watches)


if __name__ == "__main__":
    main()