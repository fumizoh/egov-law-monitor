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
from summary_generator import generate_summary
from models import Law

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

summary = generate_summary(
    law_name=LAW_NAME,
    law_no=compare_result.new.law_num,
    changes=changes,
)

print()
print("=== AI Summary ===")
print()

if summary.title:
    print(summary.title)
    print()

print(summary.summary)