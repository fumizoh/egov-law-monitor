"""Inspect LawText API."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from sources.lawtext_api import fetch_law_text

from lawtext_parser import parse_law_text


LAW_ID = "508AC0000000028"
LAW_DATA_ID = 636660
SUB_REVISION = "1"


def main() -> None:
    raw = fetch_law_text(
        law_id=LAW_ID,
        law_data_id=LAW_DATA_ID,
        sub_revision=SUB_REVISION,
    )

    result = raw["result"]

    print(f"Success: {result['success']}")

    items = result["searchResult_array"]

    print(f"Items: {len(items)}")
    print()

    for item in items:
        print(
            f'{item["Type"]}: '
            f'{item["ObjectId"]}'
        )

    first = items[0]["Content"]

    print()
    print("=== First Article ===")

    for key, value in first.items():
        print(f"{key}: {type(value).__name__}")

    paragraph = first["Paragraph"][0]

    print()
    print("=== Paragraph ===")

    for key, value in paragraph.items():
        print(f"{key}: {type(value).__name__}")

    sentence = paragraph["ParagraphSentence"]

    print()
    print(type(sentence))
    print(sentence)

    second = items[1]["Content"]

    print()
    print("=== Second Article ===")
    print(f"Paragraph count: {len(second['Paragraph'])}")

    for i, paragraph in enumerate(second["Paragraph"], start=1):
        print(
            i,
            paragraph.get("ParagraphNum"),
        )

    articles = parse_law_text(raw)

    print(f"Articles: {len(articles)}")
    print()

    first = articles[0]

    print(first.article)
    print()
    print(first.text)


if __name__ == "__main__":
    main()