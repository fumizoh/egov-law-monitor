from egov_bulk import (
    get_latest_update_date,
    download_update_xml,
)

from storage import extract_zip


def main():

    date = get_latest_update_date()

    zip_path = download_update_xml(date)

    extract_dir = extract_zip(zip_path)

    print(extract_dir.resolve())


if __name__ == "__main__":
    main()