from pathlib import Path
import sys

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_compare_result
from lawchange_builder import build_law_changes
from lawtext_parser import (
    parse_lawtext_results,
)
from sel_text_list import SEL_TEXT_LIST
from sources.compare_api import fetch_compare
from sources.lawtext import fetch_law_text

LAW_ID = "406AC0000000113"

REVISION_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}


def count_compare_blocks(compare_result):
    """Count CompareBlock types."""

    article = 0
    paragraph = 0
    item = 0
    other = 0

    for block in compare_result.blocks:

        xpath = block.xpath

        if "/Item[" in xpath:
            item += 1
        elif "/Paragraph[" in xpath:
            paragraph += 1
        elif "/Article[" in xpath:
            article += 1
        else:
            other += 1

    return article, paragraph, item, other


def main():

    payload = {
        "law_id": LAW_ID,
    }

    response = requests.post(
        REVISION_URL,
        json=payload,
        headers=HEADERS,
        timeout=30,
    )

    response.raise_for_status()

    revision = response.json()

    history = revision["result"]["Amendment_History"]

    selected = history[1]

    compare_json = fetch_compare(
        new_law_data_id=selected["LawDataId"],
        new_sub_revision=selected["SubRevision"],
        sel_text_list=SEL_TEXT_LIST,
    )

    compare_result = parse_compare_result(compare_json)

    lawtext_json = fetch_law_text(
        law_id=compare_result.law_id,
        law_data_id=compare_result.new.law_data_id,
        sub_revision=compare_result.new.sub_revision,
        occasion=compare_result.new.scheduled_enforcement_date,
        sel_text_list=SEL_TEXT_LIST,
    )

    search_result_array = (
        lawtext_json["result"]["searchResult_array"]
    )

    results = parse_lawtext_results(
        search_result_array
    )

    index = parse_lawtext_results(search_result_array)

    print()
    print("===== LawText =====")
    print()

    print(f"Articles        : {len(index.articles)}")
    print(f"Article lookup  : {len(index.article_lookup)}")
    print(f"Location lookup : {len(index.location_lookup)}")

    print()
    print("===== Location Sample =====")
    print()

    for object_id in [
        "#Mp-Ch_3-At_41",
        "#Mp-Ch_3-At_41-Pr_2",
        "#Mp-Ch_3-At_41-Pr_2-It_1",
    ]:
        location = index.location_lookup.get(object_id)

        print(object_id)

        if location:
            print(location)
            print(location.label)
        else:
            print("Not found")

        print()

if __name__ == "__main__":
    main()