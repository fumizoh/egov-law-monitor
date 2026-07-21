from models import (
    CompareBlock,
    CompareResult,
    LawChange,
    LawTextIndex,
    LawTextResult,
    Location,
)


def build_law_change(
    compare: CompareBlock,
    location: Location,
    lawtext: LawTextResult,
) -> LawChange:
    """Build one law change."""

    return LawChange(
        object_id=compare.object_id,
        location=location,
        change_type=compare.change_type,
        before=compare.old_text,
        after=compare.new_text,
        article_text=lawtext,
    )


def build_law_changes(
    compare_result: CompareResult,
    index: LawTextIndex,
) -> list[LawChange]:
    """Build law changes."""

    changes = []

    for block in compare_result.blocks:

        location = index.location_lookup.get(
            block.object_id
        )

        if location is None:
            continue

        lawtext = index.articles[
            location.article_object_id
        ]

        changes.append(
            build_law_change(
                block,
                location,
                lawtext,
            )
        )

    return changes
