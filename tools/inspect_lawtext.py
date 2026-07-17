from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.lawtext import fetch_law_text

from pprint import pprint

from lawtext_parser import (
    parse_paragraph,
    parse_law_text_result,
    build_lawtext_index,
)


data = fetch_law_text(
    law_id="406AC0000000113",
    law_data_id=637593,
    sub_revision="1",
    occasion="2027/07/14",
    sel_text_list=[
        "#TOC",
        "#Mp-Ch_1",
        "#Mp-Ch_1-At_1",
    ],
)

pprint(data)

article = data["result"]["searchResult_array"][2]

paragraph = parse_paragraph(
    article["Content"]["Paragraph"][0]
)

print()
print(paragraph)

article = data["result"]["searchResult_array"][2]

result = parse_law_text_result(article)

print()
print(result)

results = [
    result,
]

'''
index = build_lawtext_index(results)

print()
print(index)

print()
print(index["#Mp-Ch_1-At_1"])
'''

print("--additional inspection--")

for result in results:

    if result.object_id is None:
        continue

    if "-Sp" in result.object_id:

        print(result.object_id)
        print(result.title)
        print(result.caption)
        print()