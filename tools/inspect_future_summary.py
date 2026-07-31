from pathlib import Path
import sys

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_revision_history

from summary.generator import generate_future_summary


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

response = generate_future_summary(
    law_name=LAW_NAME,
    revisions=revisions,
)

print()
print("=== Future Summary ===")
print()

if response.summary.title:
    print(response.summary.title)
    print()

print(response.summary.body)
