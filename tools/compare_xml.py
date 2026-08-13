from __future__ import annotations

import argparse
from collections import OrderedDict
from pathlib import Path
import xml.etree.ElementTree as ET


TEXT_TAGS = {
    "ArticleCaption",
    "ArticleTitle",
    "ParagraphNum",
    "Sentence",
    "ItemTitle",
    "Subitem1Title",
    "Subitem2Title",
    "Subitem3Title",
    "Subitem4Title",
    "Subitem5Title",
    "TableTitle",
    "TableStruct",
}


def _text(element: ET.Element) -> str:
    """Get normalized text from an XML element."""
    return "".join(element.itertext()).strip()


def _label(element: ET.Element) -> str:
    """Build a human-readable label for an XML element."""
    tag = element.tag

    if tag == "Article":
        return f"第{element.get('Num')}条"

    if tag == "Paragraph":
        return f"第{element.get('Num')}項"

    if tag == "Item":
        return f"第{element.get('Num')}号"

    if tag.startswith("Subitem"):
        return f"{tag} {element.get('Num')}"

    if tag == "ArticleCaption":
        return "条文見出し"

    if tag == "ArticleTitle":
        return "条文番号"

    if tag == "Sentence":
        return f"Sentence {element.get('Num')}"

    return tag


def _location(stack: list[str]) -> str:
    """Build location string."""
    return " / ".join(stack)


def _collect(
    element: ET.Element,
    stack: list[str] | None = None,
    result: OrderedDict | None = None,
) -> OrderedDict:
    """Collect text-bearing XML elements by structural location."""

    if stack is None:
        stack = []

    if result is None:
        result = OrderedDict()

    tag = element.tag

    # Structural location
    if tag == "Article":
        current_stack = stack + [f"第{element.get('Num')}条"]

    elif tag == "Paragraph":
        current_stack = stack + [f"{element.get('Num')}項"]

    elif tag == "Item":
        current_stack = stack + [f"{element.get('Num')}号"]

    elif tag.startswith("Subitem"):
        current_stack = stack + [
            f"{tag}({element.get('Num')})"
        ]

    else:
        current_stack = stack

    if tag in TEXT_TAGS:
        text = _text(element)

        if text:
            key = (_location(current_stack), tag)

            # Sentenceなど同一locationに複数存在する場合に備える
            if key in result:
                index = 2
                while (key[0], tag, index) in result:
                    index += 1
                key = (key[0], tag, index)

            result[key] = text

    for child in element:
        _collect(child, current_stack, result)

    return result


def _load(path: Path) -> OrderedDict:
    """Load XML and collect comparable text."""
    root = ET.parse(path).getroot()
    return _collect(root)


def compare(old_path: Path, new_path: Path) -> None:
    old = _load(old_path)
    new = _load(new_path)

    old_keys = set(old)
    new_keys = set(new)

    added = new_keys - old_keys
    removed = old_keys - new_keys
    common = old_keys & new_keys

    changed = [
        key
        for key in common
        if old[key] != new[key]
    ]

    print()
    print("=" * 70)
    print("XML Comparison")
    print("=" * 70)
    print(f"OLD: {old_path}")
    print(f"NEW: {new_path}")
    print()

    print(f"Added   : {len(added)}")
    print(f"Removed : {len(removed)}")
    print(f"Changed : {len(changed)}")
    print()

    if not added and not removed and not changed:
        print("No textual differences found.")
        return

    if added:
        print("=" * 70)
        print("ADDED")
        print("=" * 70)

        for key in sorted(added):
            print(f"\n[{key[0]}]")
            print(new[key])

    if removed:
        print("=" * 70)
        print("REMOVED")
        print("=" * 70)

        for key in sorted(removed):
            print(f"\n[{key[0]}]")
            print(old[key])

    if changed:
        print("=" * 70)
        print("CHANGED")
        print("=" * 70)

        for key in sorted(changed):
            print(f"\n[{key[0]}]")
            print()
            print("<OLD>")
            print(old[key])
            print()
            print("<NEW>")
            print(new[key])


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compare two e-Gov law XML files."
    )

    parser.add_argument("old", type=Path)
    parser.add_argument("new", type=Path)

    args = parser.parse_args()

    compare(args.old, args.new)


if __name__ == "__main__":
    main()