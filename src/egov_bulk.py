"""
e-Gov一括ダウンロード
"""

import requests

from bs4 import BeautifulSoup

from config import BULK_PAGE_URL


def get_latest_update_date():

    response = requests.get(BULK_PAGE_URL, timeout=30)

    response.raise_for_status()

    soup = BeautifulSoup(response.text, "html.parser")

    day_list = soup.find(id="dayListFile")

    if day_list is None:
        raise RuntimeError("更新法令一覧が見つかりません。")

    first_link = day_list.find("a")

    if first_link is None:
        raise RuntimeError("更新法令リンクが見つかりません。")

    href = first_link["href"]

    date = href.split("update_date=")[1].split("&")[0]

    return date