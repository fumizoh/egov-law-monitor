"""
e-Gov APIとの通信を担当するモジュール
"""

import requests


BASE_URL = "https://laws.e-gov.go.jp/api/2/laws"


def get_law_list():
    """
    法令一覧を取得する

    Returns:
        dict: APIが返したJSON
    """

    response = requests.get(BASE_URL)

    response.raise_for_status()

    return response.json()