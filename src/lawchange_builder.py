from models import (
    CompareBlock,
    LawChange,
    LawTextResult,
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