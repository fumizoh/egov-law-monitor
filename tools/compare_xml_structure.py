from __future__ import annotations

import argparse
from collections import Counter
from pathlib import Path
import xml.etree.ElementTree as ET


def _normalize_text(text: str | None) -> str:
    if text is None:
        return ""
    return " ".join(text.split())


def _element_signature(
    element: ET.Element,
) -> tuple[str, tuple[tuple[str, str], ...]]:
    """Return tag and sorted attributes."""
    return (
        element.tag,
        tuple(sorted(element.attrib.items())),
    )


def _collect(
    element: ET.Element,
    path: str = "",
) -> list[tuple[str, tuple[str, tuple[tuple[str, str], ...]]]]:
    """Collect XML structure with paths."""

    if element.tag == "Law":
        current_path = "/Law"
    else:
        current_path = f"{path}/{element.tag}"

    result = [
        (
            current_path,
            _element_signature(element),
        )
    ]

    for child in element:
        result.extend(_collect(child, current_path))

    return result


def _collect_text(
    element: ET.Element,
    path: str = "",
) -> list[tuple[str, str]]:
    """Collect text nodes with element paths."""

    if element.tag == "Law":
        current_path = "/Law"
    else:
        current_path = f"{path}/{element.tag}"

    result = []

    text = _normalize_text(element.text)

    if text:
        result.append((current_path, text))

    for child in element:
        result.extend(_collect_text(child, current_path))

    return result


def compare(old_path: Path, new_path: Path) -> None:
    old_root = ET.parse(old_path).getroot()
    new_root = ET.parse(new_path).getroot()

    old_structure = _collect(old_root)
    new_structure = _collect(new_root)

    old_text = _collect_text(old_root)
    new_text = _collect_text(new_root)

    old_structure_counter = Counter(old_structure)
    new_structure_counter = Counter(new_structure)

    old_text_counter = Counter(old_text)
    new_text_counter = Counter(new_text)

    added_structure = new_structure_counter - old_structure_counter
    removed_structure = old_structure_counter - new_structure_counter

    added_text = new_text_counter - old_text_counter
    removed_text = old_text_counter - new_text_counter

    print()
    print("=" * 70)
    print("XML Structure Comparison")
    print("=" * 70)
    print(f"OLD: {old_path}")
    print(f"NEW: {new_path}")
    print()

    print("Element count")
    print("-" * 70)
    print(f"OLD: {len(old_structure)}")
    print(f"NEW: {len(new_structure)}")
    print()

    print("Structural differences")
    print("-" * 70)
    print(f"Added   : {sum(added_structure.values())}")
    print(f"Removed : {sum(removed_structure.values())}")
    print()

    if added_structure:
        print("=" * 70)
        print("ADDED ELEMENTS")
        print("=" * 70)

        for (path, signature), count in added_structure.items():
            print(f"\n[{count}x] {path}")
            print(f"tag        : {signature[0]}")
            print(f"attributes : {dict(signature[1])}")

    if removed_structure:
        print("=" * 70)
        print("REMOVED ELEMENTS")
        print("=" * 70)

        for (path, signature), count in removed_structure.items():
            print(f"\n[{count}x] {path}")
            print(f"tag        : {signature[0]}")
            print(f"attributes : {dict(signature[1])}")

    print()
    print("=" * 70)
    print("Text node differences")
    print("=" * 70)
    print(f"Added   : {sum(added_text.values())}")
    print(f"Removed : {sum(removed_text.values())}")

    if added_text:
        print()
        print("ADDED TEXT")

        for (path, text), count in added_text.items():
            print(f"\n[{count}x] {path}")
            print(text)

    if removed_text:
        print()
        print("REMOVED TEXT")

        for (path, text), count in removed_text.items():
            print(f"\n[{count}x] {path}")
            print(text)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compare the structure and attributes of two e-Gov XML files."
    )

    parser.add_argument("old", type=Path)
    parser.add_argument("new", type=Path)

    args = parser.parse_args()

    compare(args.old, args.new)


if __name__ == "__main__":
    main()