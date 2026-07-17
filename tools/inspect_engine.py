from pathlib import Path
import sys

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_compare_result
from lawchange_builder import build_law_changes
from lawtext_parser import (
    build_lawtext_index,
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

    index = build_lawtext_index(results)

    changes = build_law_changes(
        compare_result,
        index,
    )

    matched = sum(
        1
        for block in compare_result.blocks
        if block.object_id in index.article_lookup
    )

    article, paragraph, item, other = (
        count_compare_blocks(compare_result)
    )

    print()
    print("===== Summary =====")
    print()

    print(
        f"Compare blocks : {len(compare_result.blocks)}"
    )

    print("LawText index")
    print(len(index.articles))
    print(len(index.article_lookup))

    print(
        f"LawChanges     : {len(changes)}"
    )
    print(
        f"Matched IDs    : {matched}"
    )

    print()
    print("===== CompareBlock Types =====")
    print()

    print(f"Article   : {article}")
    print(f"Paragraph : {paragraph}")
    print(f"Item      : {item}")
    print(f"Other     : {other}")

    print()
    print("===== Unmatched CompareBlocks =====")

    from collections import Counter

    counter = Counter()

    for block in compare_result.blocks:

        if block.object_id in index.article_lookup:
            continue

        key = block.xpath.split("/")[3] if len(block.xpath.split("/")) > 3 else block.xpath
        counter[key] += 1

    for name, count in counter.most_common():
        print(f"{name:20} : {count}")

    print()
    print("===== Unmatched ObjectIds =====")

    for block in compare_result.blocks:

        if block.object_id in index.article_lookup:
            continue

        print(block.object_id)
        print(block.xpath)
        print()

    '''
    print()
    print("===== Article 5 =====")

    for article in results:
        if article.object_id == "#Mp-Ch_2-Se_2-Ss_1-At_5":
            print(article)
            break
    else:
        print("Not found")

    print(len(SEL_TEXT_LIST))
    print(SEL_TEXT_LIST[:20])

    print("#Mp-Ch_2-Se_2-Ss_1-At_5" in SEL_TEXT_LIST)
    '''

    print()
    print("===== Compare Articles =====")

    compare_articles = set()

    for block in compare_result.blocks:

        if "/Article[" not in block.xpath:
            continue

        compare_articles.add(block.object_id)

    for object_id in sorted(compare_articles):
        print(object_id)

    print()
    print("===== LawText Articles =====")

    lawtext_articles = {}

    for article in results:

        lawtext_articles[article.object_id] = article

    for object_id in sorted(lawtext_articles):

        title = lawtext_articles[object_id].title or ""

        print(f"{object_id:<40} {title}")

    print()
    print("===== Compare Only =====")

    for object_id in sorted(compare_articles - set(lawtext_articles)):
        print(object_id)

    print()
    print("===== LawText Only =====")

    for object_id in sorted(set(lawtext_articles) - compare_articles):

        title = lawtext_articles[object_id].title or ""

        print(f"{object_id:<40} {title}")


if __name__ == "__main__":
    main()