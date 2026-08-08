from __future__ import annotations

from dataclasses import dataclass

from models import (
    Location,
    Summary,
)

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
class AmendmentSummaryInput:
    """AI summary input for one amendment event."""

    amendment_name: str
    amendment_num: str

    enforcement_date: str | None
    scheduled_enforcement_date: str | None
    enforcement_comment: str | None
    is_effective: bool

    articles: list[SummaryArticle]


@dataclass(slots=True)
class NewLawArticle:
    """AI summary input for one article."""

    article: str
    text: str


@dataclass(slots=True)
class NewLawSummaryInput:
    """AI summary input for one new law."""

    enforcement_date: str | None
    scheduled_enforcement_date: str | None
    enforcement_comment: str | None

    articles: list[NewLawArticle]


@dataclass(slots=True)
class SummaryInput:
    """AI summary request."""

    law_name: str

    amendments: list[AmendmentSummaryInput]


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