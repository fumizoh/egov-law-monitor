import csv
from pathlib import Path


def load_updates(csv_path: Path) -> list[dict]:
    """
    更新法令CSVを読み込む。
    """

    updates = []

    with open(csv_path, encoding="utf-8-sig") as f:

        reader = csv.DictReader(f)

        for row in reader:
            updates.append(row)

    return updates