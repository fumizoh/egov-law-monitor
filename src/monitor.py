from egov_bulk import get_latest_update_date


def main():

    date = get_latest_update_date()

    print(f"最新更新日：{date}")


if __name__ == "__main__":
    main()