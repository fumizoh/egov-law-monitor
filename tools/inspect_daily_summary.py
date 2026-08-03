"""Inspect Daily Summary input."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint
from collections import Counter
from datetime import date


from sources.egov import fetch
from law_group import group_by_law
from summary.law_service import build_daily_summary_input
from summary.generator import generate_law_summary
from summary.daily_service import generate_daily_summary_response


def main():

    events, date = fetch()

    law_groups = group_by_law(events)

    responses: list[SummaryResponse] = []

    for law_group in law_groups:

        summary_input = build_daily_summary_input(
            law_group,
        )

        response = generate_law_summary(
            summary_input,
        )

        responses.append(response)

    daily = generate_daily_summary_response(
        date,
        responses,
    )

    print(daily.summary.title)
    print()
    print(daily.summary.body)

    print()
    print(daily.usage)


if __name__ == "__main__":
    main()