"""Inspect new law prompt."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

from summary.builder import build_new_law_summary_input
from summary.prompt import build_new_law_prompt_document
from sources.revision_api import fetch_revisions
from comparison import parse_revision_history


LAW_ID = "508AC0000000028"


def main() -> None:

    raw = fetch_revisions(LAW_ID)

    revisions = parse_revision_history(
        raw["result"]["Amendment_History"]
    )

    revision = revisions[0]

    summary = build_new_law_summary_input(
        law_id=LAW_ID,
        revision=revision,
    )

    document = build_new_law_prompt_document(
        law_name="国家情報会議設置法",
        summary=summary,
    )

    print("=== PromptDocument ===")
    print()

    print(f"Title: {document.title}")
    print()

    print("=== System ===")
    print(document.system)
    print()

    print("=== Role ===")
    print(document.role)
    print()

    print("=== Task ===")
    print(document.task)
    print()

    print("=== Sections ===")
    print(f"Count: {len(document.sections)}")
    print()

    first = document.sections[0]

    print("First Section")
    print("-" * 40)
    print(f"Title: {first.title}")
    print()
    print(first.body)
    print()

    print("=== Last Section ===")

    last = document.sections[-1]

    print(f"Title: {last.title}")
    print()
    print(last.body)
    print()

    print("=== Raw PromptDocument ===")
    pprint(document)


if __name__ == "__main__":
    main()