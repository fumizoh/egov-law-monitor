from sources.egov import fetch as fetch_egov
from pipeline import process

def main():

    updates, date = fetch_egov()

    process(
        "egov",
        updates,
        date,
    )

if __name__ == "__main__":
    main()