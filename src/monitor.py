from egov_bulk import (
    get_latest_update_date,
    download_update_xml,
)

from storage import (
    extract_zip,
    find_update_csv,
)

from update_parser import load_updates

from report import save_updates_json
from config import JSON_PATH

def main():

    date = get_latest_update_date()

    zip_path = download_update_xml(date)

    extract_dir = extract_zip(zip_path)

    print(extract_dir.resolve())

    csv_path = find_update_csv(extract_dir)

    updates = load_updates(csv_path)

    print(f"更新件数：{len(updates)}")
    print()
    print("先頭データ")
    print(updates[0])

    save_updates_json(updates, JSON_PATH)

    print(f"JSON保存: {JSON_PATH.resolve()}")


if __name__ == "__main__":
    main()