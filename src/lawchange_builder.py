from models import (
    CompareBlock,
    LawChange,
    LawTextResult,
    CompareResult,
)


def build_law_change(
    compare: CompareBlock,
    lawtext: LawTextResult,
) -> LawChange:
    """Build one law change."""

    after = "\n".join(
        paragraph.text
        for paragraph in lawtext.paragraphs
    )

    return LawChange(
        object_id=compare.object_id,
        kind=lawtext.kind,
        title=lawtext.title,
        caption=lawtext.caption,
        change_type=compare.change_type,
        before=compare.old_text,
        after=after,
    )


def build_law_changes(
    compare_result: CompareResult,
    index: dict[str, LawTextResult],
) -> list[LawChange]:
    """Build law changes."""

    changes = []

    for block in compare_result.blocks:

        article_id = index.article_lookup.get(
            block.object_id
        )

        if article_id is None:
            continue

        lawtext = index.articles[article_id]

        changes.append(
            build_law_change(
                block,
                lawtext,
            )
        )

    return changes