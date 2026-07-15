import json
import zipfile
from pathlib import Path

from config import (
    EXTRACT_DIR,
    DOCS_DATA,
)

UPDATE_FILES = {
    "egov": DOCS_DATA / "egov_updates.json",
    "public_comment": DOCS_DATA / "public_comments.json",
}
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


def load_json(input_path: Path):
    """
    JSONファイルを読み込む。
    """

    with open(input_path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_statistics(
    source,
    statistics,
):
    """
    情報源ごとの統計を statistics.json に保存する。
    """

    try:

        data = load_json(
            STATISTICS_JSON
        )

    except FileNotFoundError:

        data = {}

    data[source] = statistics

    save_json(
        data,
        STATISTICS_JSON,
    )


def save_source_data(source, data):
    """
    Save source data as JSON.
    """

    save_json(
        data,
        UPDATE_FILES[source],
    )


# Compatibility wrapper
def save_updates(source, updates):
    save_source_data(
        source,
        updates,
    )