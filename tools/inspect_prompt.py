from pathlib import Path
import sys
from pprint import pprint

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_compare_result
from lawchange_builder import build_law_changes
from lawtext_parser import parse_lawtext_results
from summary_builder import build_summary_input
from prompt_builder import build_prompt_document
from prompt_renderer import render_prompt

from sources.compare_api import fetch_compare
from sources.lawtext import fetch_law_text

from sel_text_list import SEL_TEXT_LIST

LAW_ID = "406AC0000000113"

LAW_NAME = "主要食糧の需給及び価格の安定に関する法律"

REVISION_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

payload = {
    "law_id": LAW_ID,
}

headers = {
    "Content-Type": "application/json",
    "User-Agent": "eGov Law Monitor",
}

response = requests.post(
    REVISION_URL,
    json=payload,
    headers=headers,
    timeout=30,
)

response.raise_for_status()

history = response.json()["result"]["Amendment_History"]

# 最新の改正（現行法の一つ前）を選択
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
    occasion="new",
    sel_text_list=SEL_TEXT_LIST,
)

index = parse_lawtext_results(
    lawtext_json["result"]["searchResult_array"]
)

changes = build_law_changes(
    compare_result,
    index,
)

summary = build_summary_input(
    law_name=LAW_NAME,
    law_num=compare_result.new.law_num,
    changes=changes,
)

print()
print("--summary--")
print(summary)

document = build_prompt_document(summary)

print()
print("--document--")
print(document)

document = build_prompt_document(summary)

prompt = render_prompt(document)

print()
print("--prompt--")
print(prompt)