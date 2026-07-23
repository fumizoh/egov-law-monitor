from pathlib import Path

import requests


LAW_DATA_ID = 637593

URL = (
    "https://laws.e-gov.go.jp/api/1/lawdata/"
    f"{LAW_DATA_ID}"
)

response = requests.get(URL, timeout=30)

print(response.status_code)
print(response.headers.get("Content-Type"))

Path("law.xml").write_bytes(response.content)

print("Saved : law.xml")