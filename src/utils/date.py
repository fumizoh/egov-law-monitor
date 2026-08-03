import re
from datetime import date

ERA_BASE_YEAR = {
    "令和": 2018,
    "平成": 1988,
    "昭和": 1925,
    "大正": 1911,
    "明治": 1867,
}

KANJI_DIGITS = {
    "〇": 0,
    "一": 1,
    "二": 2,
    "三": 3,
    "四": 4,
    "五": 5,
    "六": 6,
    "七": 7,
    "八": 8,
    "九": 9,
}


def _parse_kanji_number(text: str) -> int:
    """Parse simple Japanese numerals."""

    if text == "元":
        return 1

    if text == "十":
        return 10

    if "十" in text:
        left, _, right = text.partition("十")

        tens = 1 if left == "" else KANJI_DIGITS[left]
        ones = 0 if right == "" else KANJI_DIGITS[right]

        return tens * 10 + ones

    return KANJI_DIGITS[text]


def normalize_date(value: str | None) -> str | None:
    """Convert e-Gov Japanese era date to ISO format."""

    if not value:
        return None

    match = re.fullmatch(
        r"(令和|平成|昭和|大正|明治)(元|[一二三四五六七八九十]+)年"
        r"([一二三四五六七八九十]+)月"
        r"([一二三四五六七八九十]+)日",
        value,
    )

    if match is None:
        raise ValueError(
            f"Unsupported date format: {value}"
        )

    era, year, month, day = match.groups()

    western_year = (
        ERA_BASE_YEAR[era]
        + _parse_kanji_number(year)
    )

    d = date(
        western_year,
        _parse_kanji_number(month),
        _parse_kanji_number(day),
    )

    return d.isoformat()