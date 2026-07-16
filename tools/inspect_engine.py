from pathlib import Path
import sys
from pprint import pprint

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_compare_result
from sources.compare_api import fetch_compare
from sources.lawtext import fetch_law_text

from sel_text_list import SEL_TEXT_LIST

from lawtext_parser import (
    parse_law_text_result,
    build_lawtext_index,
)

from lawchange_builder import build_law_change

LAW_ID = "406AC0000000113"

REVISION_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}

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

compare_result = parse_compare_result(
    compare_json
)

print(compare_result.new)

lawtext_json = fetch_law_text(
    law_id=compare_result.law_id,
    law_data_id=compare_result.new.law_data_id,
    sub_revision=compare_result.new.sub_revision,
    occasion=compare_result.new.scheduled_enforcement_date,
    sel_text_list=SEL_TEXT_LIST,
)

print()
print(type(lawtext_json))

print(lawtext_json.keys())

results = [
    parse_law_text_result(item)
    for item in lawtext_json["result"]["searchResult_array"]
    if item["Type"] == "Article"
]

index = build_lawtext_index(results)

print()
print(len(index))

print()
print(index["#Mp-Ch_1-At_1"])

compare_block = next(
    block
    for block in compare_result.blocks
    if block.object_id == "#Mp-Ch_1-At_1"
)

lawtext = index.get(compare_block.object_id)

print()
print(compare_block)

print()
print(lawtext)

change = build_law_change(
    compare_block,
    lawtext,
)

print()
print(change)