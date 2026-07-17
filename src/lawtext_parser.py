from models import (
    Item,
    LawTextIndex,
    LawTextResult,
    Paragraph,
    Location,
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


def parse_suppl_provision(
    item: dict,
) -> list[LawTextResult]:
    """Parse supplementary provision."""

    articles = item["Content"].get("Article", [])

    return [
        parse_article(
            article,
            object_id=article["-ObjectId"],
            kind="EnactSupplProvision",
        )
        for article in articles
    ]


def build_article_lookup(
    articles: dict[str, LawTextResult],
) -> dict[str, str]:
    """Build ObjectId to article ObjectId lookup."""

    lookup: dict[str, str] = {}

    for article in articles.values():

        lookup[article.object_id] = article.object_id

        for paragraph in article.paragraphs:

            lookup[paragraph.object_id] = article.object_id

            for item in paragraph.items:

                lookup[item.object_id] = article.object_id

    return lookup


def build_location_lookup(
    articles: dict[str, LawTextResult],
) -> dict[str, Location]:
    """Build ObjectId to display location lookup."""

    lookup: dict[str, Location] = {}

    for article in articles.values():

        lookup[article.object_id] = Location(
            article_object_id=article.object_id,
            article=article.title,
        )

        for paragraph in article.paragraphs:

            lookup[paragraph.object_id] = Location(
                article_object_id=article.object_id,
                article=article.title,
                paragraph=paragraph.num,
            )

            for item in paragraph.items:

                lookup[item.object_id] = Location(
                    article_object_id=article.object_id,
                    article=article.title,
                    paragraph=paragraph.num,
                    item=item.num,
                )

    return lookup


def parse_lawtext_results(
    contents: list[dict],
) -> LawTextIndex:
    """Parse LawText API response."""

    articles: dict[str, LawTextResult] = {}

    for content in contents:

        match content["Type"]:

            case "Article":
                result = parse_law_text_result(content)
                articles[result.object_id] = result

            case "EnactSupplProvision":
                for supplementary in parse_suppl_provision(content):
                    articles[supplementary.object_id] = supplementary

    article_lookup = build_article_lookup(articles)
    location_lookup = build_location_lookup(articles)

    return LawTextIndex(
        articles=articles,
        article_lookup=article_lookup,
        location_lookup=location_lookup,
    )