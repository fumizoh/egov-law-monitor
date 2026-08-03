"""Inspect Daily Summary input."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint
from collections import Counter


from sources.egov import fetch
from law_group import group_by_law
from summary.law_service import build_daily_summary_input
from summary.generator import generate_law_summary


def main():

    events, _ = fetch()

    law_groups = group_by_law(events)

    print(len(law_groups), "LawGrouups")

    total_events = sum(
        len(group.events)
        for group in law_groups
    )

    print(total_events, "Events")

    counter = Counter(
        len(group.events)
        for group in law_groups
    )

    print(counter)

    law_group = law_groups[0]

    # for law_group in law_groups:

    print(
        law_group.law_name,
        len(law_group.events),
    )

    summary_input = build_daily_summary_input(law_group)

    response = generate_law_summary(summary_input)

    print(response.summary.title)
    print()
    print(response.summary.body)

    '''
    if len(law_group.events) > 1:

        print(f"Law : {law_group.law_name}")
        print(f"Events : {len(law_group.events)}")

        summary_input = build_daily_summary_input(law_group)

        print()
        print("Summary Input")
        print("----------------------------")

        print(summary_input.law_name)
        print()

        print(f"Revision count : {len(summary_input.revisions)}")

        print()

        for revision in summary_input.revisions:

            pprint(
                {
                    "amendment_num": revision.amendment_num,
                    "amendment_name": revision.amendment_name,
                    "enforcement_date": revision.enforcement_date,
                    "is_new_law": revision.is_new_law,
                }
            )
    '''


if __name__ == "__main__":
    main()