from sources.egov import fetch

from pipeline import process

def main():

    updates, date = fetch()

    process(
        updates,
        date,
    )


if __name__ == "__main__":
    main()