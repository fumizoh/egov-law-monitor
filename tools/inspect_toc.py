from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.toc_api import fetch_law_toc

from toc_parser import build_sel_text_list

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

object_ids = build_sel_text_list(toc_body)

print(f"Count: {len(object_ids)}")

print()
print("First 30")
for object_id in object_ids[:30]:
    print(object_id)

print()
print("Last 30")
for object_id in object_ids[-30:]:
    print(object_id)

print(type(object_ids))
print(len(object_ids))
print(object_ids[-10:])

for object_id in object_ids:
    if "508AC0000000055" in object_id:
        print(repr(object_id))

'''
walk(toc_body)

print("=== TocBody ===")

for key, value in toc_body.items():
    if isinstance(value, dict):
        print(f"{key:20} dict ({len(value)} keys)")
    elif isinstance(value, list):
        print(f"{key:20} list ({len(value)} items)")
    else:
        print(f"{key:20} {type(value).__name__}")

print()

for key, value in toc_body.items():
    if isinstance(value, dict):
        print(f"[{key}]")
        print("  ObjectId:", value.get("-ObjectId"))
'''