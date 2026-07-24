from sources.egov import fetch as fetch_egov
from sources.public_comment import fetch as fetch_public_comment

from pipeline import process


def main():

    # DEBUG
    print("--fetch egov--")
    # DEBUG

    updates, date = fetch_egov()

    process(
        source="egov",
        updates=updates,
        date=date,
    )

    # DEBUG
    print("--fetch public comment--")
    # DEBUG

    public_updates, public_date = fetch_public_comment()

    process(
        source="public_comment",
        updates=public_updates,
        date=public_date,
    )


if __name__ == "__main__":
    main()