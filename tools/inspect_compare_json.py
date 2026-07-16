import json
from pathlib import Path

from pprint import pprint

from collections import Counter

import sys

sys.path.append(str(Path(__file__).resolve().parents[1] / "src"))

from comparison import (
    strip_html,
    normalize_compare_block,
    parse_law_revision,
    parse_compare_result,
)

from comparison import strip_html

from comparison import normalize_compare_block


compare = json.loads(
    Path("compare.json").read_text(encoding="utf-8")
)

print(type(compare).__name__)
print(compare.keys())

result = compare["result"]

print(type(result).__name__)
print(result.keys())

compare_data = result["Compare_Data"]

print(type(compare_data).__name__)
print(compare_data.keys())

compare_info = compare_data["CompareInfo"]

print(type(compare_info).__name__)

if isinstance(compare_info, dict):
    print(compare_info.keys())
elif isinstance(compare_info, list):
    print(f"list ({len(compare_info)})")

compare_blocks = compare_info["CompareBlock"]

print(type(compare_blocks).__name__)
print(f"Count: {len(compare_blocks)}")

print()
print(type(compare_blocks[0]).__name__)
print(compare_blocks[0].keys())

print()
print("=== First CompareBlock ===")
pprint(compare_blocks[0])

article = next(
    block
    for block in compare_blocks
    if "/Article" in (
        block["NewLawBlock"].get("-Xpath", "")
        or block["OldLawBlock"].get("-Xpath", "")
    )
)

print()
print("=== First Article ===")

pprint(article)

counter = Counter(
    block["-differential"]
    for block in compare_blocks
)

print()
print(counter)

different = [
    block
    for block in compare_blocks
    if block["-differential"] == "different"
]

print(f"Different: {len(different)}")

pprint(different[0])

print()
print(strip_html(
    different[0]["OldLawBlock"]["#text"]
))

block = normalize_compare_block(
    different[0]
)

print()
print(block)

new = parse_law_revision(
    compare_data["NewLawInfo"]
)

print()
print(new)

result = parse_compare_result(compare)

print()
print(result.law_id)

print(result.old)

print(result.new)

print(len(result.blocks))

print(result.blocks[0])