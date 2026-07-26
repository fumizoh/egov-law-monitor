from pathlib import Path
import sys

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_revision_history

from summary.generator import generate_summary, generate_law_summary

'''
from sources.compare_api import fetch_compare
from comparison import parse_compare_result
from sources.toc_api import fetch_law_toc
from toc_parser import parse_toc
from lawchange_builder import build_law_changes
from summary.builder import build_summary_input
from summary.prompt import build_prompt_document
from summary.prompt_renderer import render_prompt
from summary.gemini_client import summarize
'''

LAW_ID = "322AC0000000003"

LAW_NAME = "皇室典範"

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

revisions = parse_revision_history(history)

print("law_id:", LAW_ID)
print("law_name:", LAW_NAME)
print(len(revisions), "revisions")

revision = revisions[0]

print(type(revision))
print(revision)

summary = generate_law_summary(
    law_name=LAW_NAME,
    revisions=[revision],
)

'''
# 最新の改正を選択
selected = revisions[0]

print("selected revision:")
print(selected)

# print("--fetch compare--")
compare_json = fetch_compare(
    new_law_data_id=selected["LawDataId"],
    new_sub_revision=selected["SubRevision"],
)

# print("--parse compare--")
compare_result = parse_compare_result(compare_json)

# print(compare_result)

# print("--fetch law toc--")
toc_json = fetch_law_toc(
    law_data_id=compare_result.new.law_data_id,
    sub_revision=compare_result.new.sub_revision,
)

# print("--parse toc--")
index = parse_toc(
    toc_json["result"]["Toc_Data"]["TocBody"]
)

# print("--build law changes--")
changes = build_law_changes(
    compare_result,
    index,
)

print(len(changes), "changes from previous")

# print("--build summary input--")
summary_input = build_summary_input(
    law_name=LAW_NAME,
    law_num=compare_result.new.law_num,
    changes=changes,
)

print("generating summary...")
prompt_document = build_prompt_document(summary_input)
prompt = render_prompt(prompt_document)

summary = summarize(prompt)
'''

print()
print("=== AI Summary ===")
print()

if summary.title:
    print(summary.title)
    print()

print(summary.body)
