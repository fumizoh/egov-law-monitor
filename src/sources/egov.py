"""
e-Gov law update source.
"""

from egov_bulk import (
    get_latest_update_date,
    download_update_xml,
)

from storage import (
    extract_zip,
    find_update_csv,
)

from update_parser import load_updates


def fetch():
    """Fetch updates from e-Gov."""

    date = get_latest_update_date()

    zip_path = download_update_xml(date)

    extract_dir = extract_zip(zip_path)

    print(extract_dir.resolve())

    csv_path = find_update_csv(extract_dir)

    updates = load_updates(csv_path)

    return updates, date