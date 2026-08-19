"""Generate an AI summary for selected law revisions."""

from __future__ import annotations

from pathlib import Path
import argparse
import sys
from pprint import pprint

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from models import LawGroup, LawSummaryInput, RevisionHistory
from sources import egov
from law_group import group_by_law
from summary import generator
from sources.revision import get_revision_history


def _parse_revision_spec(spec: str) -> tuple[int, str | None]:
    """Parse LAW_DATA_ID[:SUB_REVISION]."""
    parts = spec.split(":", 1)

    try:
        law_data_id = int(parts[0])
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            f"Invalid revision: {spec}"
        ) from exc

    sub_revision = parts[1] if len(parts) == 2 else None
    return law_data_id, sub_revision


def _find_revisions(
    revisions: list[RevisionHistory],
    specs: list[str],
) -> list[RevisionHistory]:
    """Find revisions specified by command-line arguments."""
    selected: list[RevisionHistory] = []

    for spec in specs:
        law_data_id, sub_revision = _parse_revision_spec(spec)

        matches = [
            revision
            for revision in revisions
            if revision.law_data_id == law_data_id
            and (
                sub_revision is None
                or revision.sub_revision == sub_revision
            )
        ]

        if not matches:
            raise ValueError(
                f"Revision not found: {spec}"
            )

        if sub_revision is None and len(matches) > 1:
            available = ", ".join(
                revision.sub_revision for revision in matches
            )
            raise ValueError(
                f"{spec} matches multiple sub_revisions: "
                f"{available}. Specify {spec}:<sub_revision>."
            )

        selected.append(matches[0])

    return selected


def _find_law_name(law_id: str) -> str:
    """Find law name from current e-Gov updates."""
    updates, _ = egov.fetch()

    for law_group in group_by_law(updates):
        if law_group.law_id == law_id:
            return law_group.law_name

    raise ValueError(f"Law not found: {law_id}")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Generate an AI summary for selected law revisions."
    )
    parser.add_argument(
        "law_id",
        help="e-Gov law ID",
    )
    parser.add_argument(
        "revisions",
        nargs="+",
        metavar="LAW_DATA_ID[:SUB_REVISION]",
        help="Revision(s) to summarize",
    )
    parser.add_argument(
        "--law-name",
        help="Law name. If omitted, it is obtained from e-Gov updates.",
    )
    args = parser.parse_args()

    revisions = get_revision_history(args.law_id)
    selected_revisions = _find_revisions(
        revisions,
        args.revisions,
    )

    law_name = args.law_name or _find_law_name(args.law_id)

    summary_input = LawSummaryInput(
        law_id=args.law_id,
        law_name=law_name,
        revisions=selected_revisions,
    )

    print("=== Law ===")
    print(f"law_id: {summary_input.law_id}")
    print(f"law_name: {summary_input.law_name}")

    print("\n=== Revisions ===")
    for revision in selected_revisions:
        print(
            f"- law_data_id={revision.law_data_id}, "
            f"sub_revision={revision.sub_revision}, "
            f"amendment={revision.amendment_name}, "
            f"amendment_num={revision.amendment_num}, "
            f"enforcement_date={revision.enforcement_date}, "
            f"scheduled={revision.scheduled_enforcement_date}"
        )

    print("\n=== Generating ===")
    result = generator._generate_law_summary(summary_input)

    if result is None:
        print("Summary generation failed.")
        return

    print("\n=== Title ===")
    print(result.summary.title)

    print("\n=== Body ===")
    print(result.summary.body)

    print("\n=== Usage ===")
    pprint(result.usage)


if __name__ == "__main__":
    main()
