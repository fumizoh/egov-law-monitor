"""Inspect e-Gov law search."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.law_search_api import search_laws


def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python tools/inspect_law_search.py <search text>")
        raise SystemExit(1)

    search_text = sys.argv[1]

    results = search_laws(search_text)

    print(f"検索結果: {len(results)}件")
    print()

    for result in results:
        print(result.law_name)
        print(f"  {result.law_no}")
        print(f"  {result.law_type}")
        print(f"  {result.law_id}")
        print()


if __name__ == "__main__":
    main()