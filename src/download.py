"""
更新法令データのダウンロード
"""

import requests

from config import (
    BULK_DOWNLOAD_URL,
    UPDATE_DATE,
    ONLY_XML,
)


def download_update():

    params = {
        "file_section": 3,
        "update_date": UPDATE_DATE,
        "only_xml_flag": str(ONLY_XML).lower(),
    }

    response = requests.get(
        BULK_DOWNLOAD_URL,
        params=params,
        timeout=30,
    )

    response.raise_for_status()

    return response