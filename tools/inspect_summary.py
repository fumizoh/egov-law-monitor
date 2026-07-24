from pathlib import Path
import sys
from pprint import pprint

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.compare_api import fetch_compare
from sources.toc_api import fetch_law_toc

from models import Law

from comparison import parse_compare_result
from lawchange_builder import build_law_changes
from toc_parser import parse_toc

from summary.generator import generate_summary


LAW_ID = "423AC0000000081"

LAW_NAME = "あああああ"

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

toc_json = fetch_law_toc(
    law_data_id=compare_result.new.law_data_id,
    sub_revision=compare_result.new.sub_revision,
)

index = parse_toc(
    toc_json["result"]["Toc_Data"]["TocBody"]
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

print(summary.body)