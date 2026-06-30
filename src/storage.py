import json
import zipfile
from pathlib import Path

from config import (
    EXTRACT_DIR,
    DOCS_DATA,
)

UPDATES_JSON = DOCS_DATA / "updates.json"
STATISTICS_JSON = DOCS_DATA / "statistics.json"
APP_JSON = DOCS_DATA / "app.json"

def extract_zip(zip_path: Path) -> Path:
    """
    ZIPファイルを展開する。
    戻り値は展開先フォルダ。
    """

    output_dir = EXTRACT_DIR / zip_path.stem

    output_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    with zipfile.ZipFile(zip_path, "r") as zip_file:
        zip_file.extractall(output_dir)

    return output_dir


def find_update_csv(extract_dir: Path) -> Path:
    """
    展開フォルダから更新一覧CSVを探す。
    """

    csv_files = list(extract_dir.glob("*.csv"))

    if not csv_files:
        raise FileNotFoundError("更新一覧CSVが見つかりません。")

    return csv_files[0]


def save_json(data, output_path: Path):
    """
    JSONファイルとして保存する。
    """

    with open(output_path, "w", encoding="utf-8") as f:

        json.dump(
            data,
            f,
            ensure_ascii=False,
            indent=2
        )


def save_statistics(statistics):
    """
    statistics.json を保存する。
    """

    save_json(
        statistics,
        STATISTICS_JSON
    )