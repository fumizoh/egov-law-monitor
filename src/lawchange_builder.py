from models import (
    CompareBlock,
    LawChange,
    LawTextResult,
)


def build_law_change(
    compare: CompareBlock,
    index: dict[str, LawTextResult],
) -> LawChange | None:
    """Build one LawChange."""

    lawtext = index.get(compare.object_id)

    if lawtext is None:
        return None

    after = "\n".join(
        p.text
        for p in lawtext.paragraphs
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