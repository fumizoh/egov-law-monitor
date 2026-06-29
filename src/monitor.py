"""
e-Gov Law Monitor
メインプログラム
"""

from pprint import pprint
from api import get_law_list


def main():

    laws = get_law_list()

    print(f"取得件数：{len(laws)}")

    print()

    print("先頭データ")

    print(laws[0])


if __name__ == "__main__":
    main()