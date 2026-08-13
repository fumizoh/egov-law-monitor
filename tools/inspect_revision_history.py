from __future__ import annotations

import sys
from pathlib import Path

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from models import RevisionHistory

from sources.revision import get_revision_history

LAW_ID = "412M50000100015"


def print_revision_history(
    revisions: list[RevisionHistory],
) -> None:
    """Print revision history."""

    print()
    print("Revision History")
    print("=" * 70)

    for index, revision in enumerate(revisions):
        print(
            f"[{index}] "
            f"{revision.law_data_id} "
            f"{revision.sub_revision} "
            f"{revision.amendment_id} "
            f"{revision.is_current} "
            f"{revision.enforcement_date} "
            f"{revision.scheduled_enforcement_date} "
            f"{revision.amendment_num} "
            f"{revision.amendment_name}"
        )


def main() -> None:

    revisions = get_revision_history(LAW_ID)

    print_revision_history(revisions)


if __name__ == "__main__":
    main()