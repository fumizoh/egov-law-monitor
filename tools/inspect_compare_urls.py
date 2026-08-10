"""Verify e-Gov amendment-specific comparison URLs.

Usage:
    python tools/inspect_compare_urls.py 413M60000100001

The script combines:
- Revision API: amendment_id
- data/laws.json: amend_published_date / amend_no

and checks the candidate URL with HTTP.
"""

from __future__ import annotations

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import argparse
import json
from pathlib import Path

import requests

from sources.revision import get_revision_history


BASE_URL = "https://laws.e-gov.go.jp/law"


def load_updates(law_id: str) -> list[dict]:
    path = Path("docs/data/laws.json")

    with path.open(encoding="utf-8") as f:
        laws = json.load(f)

    for law in laws:
        if law["law_id"] == law_id:
            return law["updates"]

    raise RuntimeError(
        f"Law not found in data/laws.json: {law_id}"
    )


def build_compare_url(
    law_id: str,
    amend_published_date: str,
    amendment_id: str,
) -> str:
    date = amend_published_date.replace("-", "")

    return (
        f"{BASE_URL}/{law_id}/"
        f"{date}_{amendment_id}"
        f"?occasion_date={date}&tab=compare"
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Verify amendment-specific e-Gov comparison URLs."
    )
    parser.add_argument("law_id")
    args = parser.parse_args()

    updates = load_updates(args.law_id)
    revisions = get_revision_history(args.law_id)

    revision_by_amend_no = {
        revision.amendment_num: revision
        for revision in revisions
        if revision.amendment_id
    }

    print(f"Law ID: {args.law_id}")
    print()

    for update in updates:
        amend_no = update["amend_no"]
        revision = revision_by_amend_no.get(amend_no)

        print("=" * 70)
        print(f"改正法令: {update['amend_name']}")
        print(f"改正法令番号: {amend_no}")
        print(f"改正法令公布日: {update['amend_published_date']}")

        if revision is None:
            print("[WARN] Revision API に対応する改正が見つかりません。")
            continue

        print(f"amendment_id: {revision.amendment_id}")

        url = build_compare_url(
            law_id=args.law_id,
            amend_published_date=update["amend_published_date"],
            amendment_id=revision.amendment_id,
        )

        print(f"候補URL: {url}")

        try:
            response = requests.get(
                url,
                timeout=20,
                allow_redirects=False,
            )
            print(f"HTTP status: {response.status_code}")

            location = response.headers.get("Location")
            if location:
                print(f"Location: {location}")

        except requests.RequestException as exc:
            print(f"[WARN] URL確認失敗: {exc}")


if __name__ == "__main__":
    main()