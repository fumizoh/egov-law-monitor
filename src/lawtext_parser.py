from models import (
    LawTextResult,
    Paragraph,
)


def parse_paragraph(raw: dict) -> Paragraph:
    """Parse paragraph."""

    sentence_list = raw["ParagraphSentence"]["Sentence"]

    text = "".join(
        child["#text"]
        for sentence in sentence_list
        for child in sentence["#childs"]
        if "#text" in child
    )

    return Paragraph(
        num=raw["ParagraphNum"],
        text=text,
    )


def parse_law_text_result(raw: dict) -> LawTextResult:
    """Parse one LawText API result."""

    content = raw["Content"]

    paragraphs = [
        parse_paragraph(p)
        for p in content.get("Paragraph", [])
    ]

    return LawTextResult(
        object_id=raw["ObjectId"],
        kind=raw["Type"],
        title=content.get("ArticleTitle"),
        caption=content.get("ArticleCaption"),
        paragraphs=paragraphs,
    )


def build_lawtext_index(
    results: list[LawTextResult],
) -> dict[str, LawTextResult]:
    """Build ObjectId index."""

    return {
        result.object_id: result
        for result in results
    }