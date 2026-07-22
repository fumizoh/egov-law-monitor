"""
Build LawChange objects from LawGroup.
"""
from pathlib import Path
import sys
sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.compare_api import fetch_compare

from models import LawGroup, LawChange

from pprint import pprint


def build_law_changes(
    group: LawGroup,
) -> list[LawChange]:
    """
    Build LawChange list from a LawGroup.

    Compare API integration will be added later.
    """

    changes: list[LawChange] = []

    for event in group.events:

        # ------------------------------------------------------------------
        # DEBUG START
        # ------------------------------------------------------------------

        print(event["metadata"])

        # ------------------------------------------------------------------
        # DEBUG END
        # ------------------------------------------------------------------

        print(event["title"])

        compare = fetch_compare(
            new_law_data_id=event["new_law_data_id"],
            new_sub_revision=event["new_sub_revision"],
            sel_text_list=[],
        )

        print(compare.keys())

    return changes