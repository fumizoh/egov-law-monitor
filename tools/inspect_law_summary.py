"""Generate an AI summary for a single law."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from pprint import pprint

from sources import egov
from law_group import group_by_law
from law_builder import sort_law_groups
from summary import builder
from summary import generator


def main() -> None:

    updates, date = egov.fetch()

    law_groups = group_by_law(updates)

    law_groups = sort_law_groups(law_groups)

    law_group = law_groups[0]

    print(law_group.law_id)
    print(law_group.law_name)

    summary_input = builder.build_law_summary_input(law_group)

    pprint(summary_input)

    result = generator._generate_law_summary(summary_input)

    print("=== Title ===")
    print(result.summary.title)

    print("\n=== Body ===")
    print(result.summary.body)

    print("\n=== Usage ===")
    pprint(result.usage)


if __name__ == "__main__":
    main()