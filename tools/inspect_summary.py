from pathlib import Path
import sys

import requests

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_revision_history

from summary.generator import generate_summary, generate_law_summary


LAW_ID = "415AC0000000057"

LAW_NAME = "個人情報の保護に関する法律"

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

summary = generate_law_summary(
    law_name=LAW_NAME,
    revisions=revisions,
)

print()
print("=== AI Summary ===")
print()

if summary.title:
    print(summary.title)
    print()

print(summary.body)
