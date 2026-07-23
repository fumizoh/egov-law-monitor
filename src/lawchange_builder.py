from models import (
    CompareBlock,
    CompareResult,
    LawChange,
    Location,
    TocIndex,
)


def build_law_change(
    compare: CompareBlock,
    location: Location,
) -> LawChange:
    """Build one law change."""

    return LawChange(
        object_id=compare.object_id.lstrip("#"),
        location=location,
        change_type=compare.change_type,
        before=compare.old_text,
        after=compare.new_text,
    )


def build_law_changes(
    compare_result: CompareResult,
    index: TocIndex,
) -> list[LawChange]:
    """Build law changes."""

    changes = []

    for block in compare_result.blocks:

        object_id = block.object_id.lstrip("#")

        location = index.location_lookup.get(object_id)

        if location is None:
            continue

        changes.append(
            build_law_change(
                block,
                location,
            )
        )

    return changes
