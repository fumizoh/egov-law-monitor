from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.toc_api import fetch_law_toc

from toc_parser import parse_toc

from typing import Any


def walk(node: Any) -> None:
    if isinstance(node, dict):

        object_id = node.get("-ObjectId")
        if object_id:
            print(object_id)

        for value in node.values():
            walk(value)

    elif isinstance(node, list):
        for item in node:
            walk(item)


data = fetch_law_toc(
    law_data_id=637590,
    sub_revision="1",
)

toc_body = data["result"]["Toc_Data"]["TocBody"]

index = parse_toc(toc_body)

print(f"Count: {len(index.sel_text_list)}")

print()
print("First 30")
for object_id in index.sel_text_list[:30]:
    print(object_id)

print()
print("Last 30")
for object_id in index.sel_text_list[-30:]:
    print(object_id)

print(type(index))
print(len(index.sel_text_list))
print(index.sel_text_list[-10:])

for object_id in index.sel_text_list:
    if "508AC0000000055" in object_id:
        print(repr(object_id))