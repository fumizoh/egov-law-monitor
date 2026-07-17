from models import (
    Item,
    LawTextIndex,
    LawTextResult,
    Paragraph,
)


def collect_sentences(node: dict) -> list[dict]:
    """Collect Sentence nodes."""

    if "Sentence" in node:
        return node["Sentence"]

    if "Column" in node:
        sentences = []

        for column in node["Column"]:
            sentences.extend(column["Sentence"])

        return sentences

    raise ValueError(
        f"Unsupported sentence structure: {list(node.keys())}"
    )


def parse_sentence_text(sentence_list: list[dict]) -> str:
    """Convert Sentence list to plain text."""

    return "".join(
        child["#text"]
        for sentence in sentence_list
        for child in sentence["#childs"]
        if "#text" in child
    )


def parse_item(raw: dict) -> Item:
    """Parse item."""

    sentence_list = collect_sentences(raw["ItemSentence"])

    text = parse_sentence_text(sentence_list)

    return Item(
        object_id=raw["-ObjectId"],
        num=raw["ItemTitle"],
        text=text,
    )


def parse_paragraph(raw: dict) -> Paragraph:
    """Parse paragraph."""

    sentence_list = collect_sentences(raw["ParagraphSentence"])

    text = parse_sentence_text(sentence_list)

    items = [
        parse_item(item)
        for item in raw.get("Item", [])
    ]

    return Paragraph(
        object_id=raw["-ObjectId"],
        num=raw["ParagraphNum"],
        text=text,
        items=items,
    )


def parse_article(
    content: dict,
    *,
    object_id: str,
    kind: str,
) -> LawTextResult:
    """Parse one article."""

    paragraphs = [
        parse_paragraph(p)
        for p in content.get("Paragraph", [])
    ]

    return LawTextResult(
        object_id=object_id,
        kind=kind,
        title=content.get("ArticleTitle"),
        caption=content.get("ArticleCaption"),
        paragraphs=paragraphs,
    )


def parse_law_text_result(raw: dict) -> LawTextResult:
    """Parse one LawText API result."""

    return parse_article(
        raw["Content"],
        object_id=raw["ObjectId"],
        kind=raw["Type"],
    )


def build_lawtext_index(
    results: list[LawTextResult],
) -> LawTextIndex:
    """Build ObjectId index."""

    articles = {}
    article_lookup = {}

    for article in results:

        articles[article.object_id] = article

        article_lookup[article.object_id] = article.object_id

        for paragraph in article.paragraphs:
            article_lookup[
                paragraph.object_id
            ] = article.object_id

        for item in paragraph.items:
            article_lookup[
                item.object_id
            ] = article.object_id

    return LawTextIndex(
        articles=articles,
        article_lookup=article_lookup,
    )


def parse_suppl_provision(item: dict) -> list[LawTextResult]:
    """Parse supplementary provision."""

    articles = item["Content"].get("Article", [])

    return [
        parse_law_text_result(article)
        for article in articles
    ]


def parse_lawtext_results(
    search_result_array: list[dict],
) -> list[LawTextResult]:
    """Parse LawText search results."""

    results: list[LawTextResult] = []

    articles: dict[str, LawTextResult] = {}

    article_lookup: dict[str, str] = {}

    for item in search_result_array:

        item_type = item["Type"]

        if item_type == "Article":

            result = parse_law_text_result(item)

            results.append(result)

            articles[result.object_id] = result
            article_lookup[result.object_id] = result.object_id

        elif item_type in (
            "EnactSupplProvision",
            "AmendSupplProvision",
        ):

            for article in item["Content"].get("Article", []):

                result = parse_article(
                    article,
                    object_id=article["-ObjectId"],
                    kind="Article",
                )

                results.append(result)

                articles[result.object_id] = result
                article_lookup[result.object_id] = result.object_id

    return results