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

    rows = load_updates(csv_path)

    updates = []

    for row in rows:

        updates.append(
            {
                "source": "egov",
                "type": "law_update",
                "title": row["法令名"],
                "url": row["本文URL"],
                "date": date,
                "summary": "",
                "metadata": {
                    "law_id": row["法令ID"],
                    "law_type": row["法令種別"],
                    "law_number": row["法令番号"],
                    "published_date": row["公布日"],
                    "effective_date": row["施行日"],
                    "effective_comment": row["施行日備考"],
                    "amend_name": row["改正法令名"],
                    "amend_number": row["改正法令番号"],
                    "amend_published_date": row["改正法令公布日"],
                    "future": row["未施行"] == "○",
                },
            }
        )

    return updates, date