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
    load_json,
)

from summary import create_statistics

from email_generator import (
    create_email_subject,
    create_email_body,
)

from config import KEYWORDS_JSON

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

    keywords = load_json(KEYWORDS_JSON)

    subject = create_email_subject(
        updates,
        date,
    )

    print(subject)
    print()

    body = create_email_body(
        updates,
        keywords,
        date,
    )

    print(body)

    print("JSON保存完了")


if __name__ == "__main__":
    main()