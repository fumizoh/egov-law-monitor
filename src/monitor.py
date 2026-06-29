"""
e-Gov Law Monitor
メインプログラム
"""

from pprint import pprint
from api import get_law_list


def main():

    print("法令一覧を取得しています...")

    laws = get_law_list()

    print(f"取得件数：{laws['total_count']}")

    print()

    print("\n先頭データ")

    pprint(laws["laws"][0])


if __name__ == "__main__":
    main()