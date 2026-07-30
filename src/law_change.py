from models import (
    CompareResult,
    LawChange,
    TocIndex,
)


def build_law_changes(
    compare_result: CompareResult,
    index: TocIndex,
) -> list[LawChange]:
    """Build law changes."""

    changes: list[LawChange] = []

    for block in compare_result.blocks:

        location = index.location_lookup.get(block.object_id)

        if location is None:
            continue

        changes.append(
            LawChange(
                object_id=block.object_id,
                location=location,
                change_type=block.change_type,
                before=block.old_text,
                after=block.new_text,
            )
        )

    return changes