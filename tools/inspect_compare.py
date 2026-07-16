from pathlib import Path
import json
from pprint import pprint

import requests

from sel_text_list import SEL_TEXT_LIST

LAW_ID = "406AC0000000113"

REVISION_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)

COMPARE_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawCompareData.json"
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

data = response.json()

print("=== Response ===")
pprint(data)

history = data["result"]["Amendment_History"]

selected = history[1]

compare_payload = {
    "new_law_data_id": selected["LawDataId"],
    "new_subRevision": selected["SubRevision"],
    "selTextList": SEL_TEXT_LIST,
}

response = requests.post(
    COMPARE_URL,
    json=compare_payload,
    headers=headers,
    timeout=30,
)

print()
print("=== Compare API ===")
print(response.status_code)
print(response.headers.get("Content-Type"))
print()
print(response.text[:500])

print(f"Found {len(history)} revisions")

print()

for i, item in enumerate(history):

    if item["IsCurrentEnforcement"]:
        status = "Current"
    elif item["ScheduledEnforcementDate"]:
        status = "Future"
    else:
        status = "Past"

    print(f"[{i}] {status}")
    print(f"  LawDataId   : {item['LawDataId']}")
    print(f"  SubRevision : {item['SubRevision']}")

    date = (
        item["EnforcementDate"]
        or item["ScheduledEnforcementDate"]
    )

    print(f"  Date        : {date}")
    print()


compare = response.json()

Path("compare.json").write_text(
    json.dumps(
        compare,
        ensure_ascii=False,
        indent=2,
    ),
    encoding="utf-8",
)

print()
print("Saved : compare.json")