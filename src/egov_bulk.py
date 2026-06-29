"""
e-Gov一括ダウンロード
"""

import requests

from bs4 import BeautifulSoup

from config import (
    BULK_PAGE_URL,
    BULK_DOWNLOAD_URL,
    DOWNLOAD_DIR,
)

from pathlib import Path


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


def create_download_url(date: str) -> str:

    return (
        f"{BULK_DOWNLOAD_URL}"
        f"?file_section=3"
        f"&update_date={date}"
        f"&only_xml_flag=true"
    )


def download_update_xml(date: str) -> Path:

    DOWNLOAD_DIR.mkdir(
        parents=True,
        exist_ok=True
    )

    url = create_download_url(date)

    response = requests.get(url, timeout=30)
    response.raise_for_status()

    zip_path = DOWNLOAD_DIR / f"{date}.zip"

    with open(zip_path, "wb") as f:
        f.write(response.content)

    return zip_path