from egov_bulk import (
    get_latest_update_date,
    download_update_xml,
)

def main():

    date = get_latest_update_date()

    zip_path = download_update_xml(date)

    print(zip_path.resolve())

if __name__ == "__main__":
    main()