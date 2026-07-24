from pathlib import Path
import sys
from pprint import pprint

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.compare_api import fetch_compare

from comparison import parse_compare_result
from lawchange_builder import build_law_changes
from lawtext_parser import parse_lawtext_results

from summary.builder import build_summary_input
from summary.prompt import build_prompt_document
from summary.prompt_renderer import render_prompt
from summary.ai_client import summarize


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
)

compare_result = parse_compare_result(compare_json)







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

prompt = render_prompt(document)

print("-- Prompt ------------------------")
print(prompt)

print()
print("-- AI Summary --------------------")

result = summarize(prompt)

if result.title:
    print(result.title)
    print()

print(result.summary)