from summary.input import NewLawArticle


def parse_law_text(raw: dict) -> list[NewLawArticle]:
    """Parse LawText API response."""

    articles: list[NewLawArticle] = []

    for item in raw["result"]["searchResult_array"]:
        if item["Type"] != "Article":
            continue

        articles.append(_parse_article(item["Content"]))

    return articles


def _parse_article(content: dict) -> NewLawArticle:
    """Parse an article."""

    paragraphs = [
        _parse_paragraph(paragraph)
        for paragraph in content["Paragraph"]
    ]

    body = "\n".join(
        paragraph
        for paragraph in paragraphs
        if paragraph
    )

    title = content.get("ArticleCaption", "").strip()

    if title:
        text = f"{title}\n{body}"
    else:
        text = body

    return NewLawArticle(
        article=content["ArticleTitle"],
        text=text,
    )


def _parse_paragraph(paragraph: dict) -> str:
    """Parse a paragraph."""

    sentence = paragraph["ParagraphSentence"]["Sentence"]

    if isinstance(sentence, dict):
        sentence = [sentence]

    return "".join(
        _parse_sentence(item)
        for item in sentence
    )


def _parse_sentence(sentence: dict) -> str:
    """Parse a sentence."""

    texts: list[str] = []

    children = sentence.get("#childs", [])

    if isinstance(children, dict):
        children = [children]

    for child in children:
        text = child.get("#text")
        if text:
            texts.append(text)

    return "".join(texts)