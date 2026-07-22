from pathlib import Path
import sys
import json
from pprint import pprint

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.compare_api import fetch_compare_ex

from sel_text_list import SEL_TEXT_LIST

LAW_ID = "406AC0000000113"

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

data = response.json()

print("=== Response ===")

'''
pprint(data)
'''

history = data["result"]["Amendment_History"]

# ------------------------------------------------------------------
# DEBUG START
# ------------------------------------------------------------------

print()
print("=== Compare Test ===")
print()

current = next(x for x in history if x["IsCurrentEnforcement"])
latest = history[0]

targets = [
    (
        "Current → Current",
        current,
        current,
    ),
    (
        "Current → Latest",
        current,
        latest,
    ),
]

for name, old_revision, new_revision in targets:

    print("=" * 60)
    print(name)
    print("=" * 60)

    print("Old")
    print(f"  LawDataId   : {old_revision['LawDataId']}")
    print(f"  SubRevision : {old_revision['SubRevision']}")

    print("New")
    print(f"  LawDataId   : {new_revision['LawDataId']}")
    print(f"  SubRevision : {new_revision['SubRevision']}")

    compare = fetch_compare_ex(
        old_law_data_id=old_revision["LawDataId"],
        old_sub_revision=old_revision["SubRevision"],
        new_law_data_id=new_revision["LawDataId"],
        new_sub_revision=new_revision["SubRevision"],
        sel_text_list=SEL_TEXT_LIST,
    )

    print()
    print("=" * 60)
    print("Response")
    print("=" * 60)

    pprint(compare["result"]["Compare_Data"]["OldLawInfo"])
    print()
    pprint(compare["result"]["Compare_Data"]["NewLawInfo"])

    '''
    pprint(compare)
    '''

    '''
    blocks = compare["result"]["CompareInfo"]["CompareBlock"]

    different = [
        block
        for block in blocks
        if block["CompareResult"] == "different"
    ]

    print(f"CompareBlock : {len(blocks)}")
    print(f"Differences  : {len(different)}")

    print()
    print("Changed blocks")
    print("-" * 40)

    for block in different[:30]:

        print(
            f'{block["Tag"]} '
            f'{block.get("Num", "")}'
        )

    print()
    '''

# ------------------------------------------------------------------
# DEBUG END
# ------------------------------------------------------------------
'''
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
'''