"""Inspect new law prompt."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

from sources.revision_api import fetch_revisions
from comparison import parse_revision_history
from summary.builder import build_new_law_summary_input
from summary.prompt import build_new_law_prompt_document
from summary.generator import generate_new_law_summary


LAW_ID = "508AC0000000028"


def main() -> None:

    raw = fetch_revisions(LAW_ID)

    revisions = parse_revision_history(
        raw["result"]["Amendment_History"]
    )

    revision = revisions[0]

    response = generate_new_law_summary(
        law_id=LAW_ID,
        law_name="国家情報会議設置法",
        revision=revision,
    )

    print("LAW_ID", LAW_ID)
    print("=== New Law Summary ===")
    print(response.summary.title)
    print(response.summary.body)


if __name__ == "__main__":
    main()