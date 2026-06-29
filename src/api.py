"""
e-Gov APIとの通信を担当するモジュール
"""

import requests


BASE_URL = "https://laws.e-gov.go.jp/api/2/laws"


def get_law_list():
    
    response = requests.get(BASE_URL)
    response.raise_for_status()

    data = response.json()

    laws = []

    for item in data["laws"]:

        law = {
            "id": item["law_info"]["law_id"],
            "title": item["current_revision_info"]["law_title"],
            "updated": item["current_revision_info"]["updated"],
            "type": item["law_info"]["law_type"],
        }

        laws.append(law)

    return laws