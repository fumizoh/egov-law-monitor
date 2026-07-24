from dataclasses import dataclass

from models import Location

@dataclass(slots=True)
class SummaryChange:
    """Input for AI summary."""

    location: Location

    change_type: str

    before: str | None
    after: str | None


@dataclass(slots=True)
class SummaryArticle:
    """AI summary input for one article."""

    article: str

    changes: list[SummaryChange]


@dataclass(slots=True)
class SummaryInput:
    """AI summary request."""

    law_name: str

    law_num: str

    articles: list[SummaryArticle]


@dataclass(slots=True)
class PromptSection:
    """One section of a prompt."""

    title: str
    body: str


@dataclass(slots=True)
class PromptDocument:
    """Complete prompt document for an LLM."""

    title: str
    system: str
    role: str
    task: str
    sections: list[PromptSection]