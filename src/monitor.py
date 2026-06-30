from egov_bulk import (
    get_latest_update_date,
    download_update_xml,
)

from update_parser import load_updates

from storage import (
    extract_zip,
    find_update_csv,
    save_updates,
    save_statistics,
)

from summary import create_statistics

def main():

    date = get_latest_update_date()

    zip_path = download_update_xml(date)

    extract_dir = extract_zip(zip_path)

    print(extract_dir.resolve())

    csv_path = find_update_csv(extract_dir)

    updates = load_updates(csv_path)

    save_updates(updates)

    statistics = create_statistics(
        updates,
        date,
    )

    save_statistics(statistics)

    print("JSON保存完了")


if __name__ == "__main__":
    main()