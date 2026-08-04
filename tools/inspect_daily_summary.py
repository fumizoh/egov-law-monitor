"""Inspect Daily Summary input."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint
# from collections import Counter
from datetime import date


from sources.egov import fetch
from law_group import group_by_law
# from summary.builder import build_law_summary_input
# from summary.generator import generate_law_summary
# from summary.daily_service import generate_daily_summary

import summary.daily_service as daily_service
import storage

def main():

    events, date = fetch()

    law_groups = group_by_law(events)

    daily_summary, law_summaries = daily_service.generate(
        date,
        law_groups,
    )

    storage.save_law_summaries(law_summaries)

    storage.save_daily_summary(daily_summary)


    print(daily_summary.summary.title)
    print()
    print(daily_summary.summary.body)

    print()
    print(daily_summary.usage)


if __name__ == "__main__":
    main()