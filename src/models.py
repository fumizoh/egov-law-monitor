"""
Application data models.
"""

from dataclasses import dataclass

from typing import Any, TypedDict

from enum import Enum


class Event(TypedDict):
    """Common event model."""

    source: str
    type: str
    title: str
    url: str
    date: str
    summary: str
    metadata: dict[str, Any]


@dataclass(slots=True)
class LawGroup:
    """Events grouped by law."""

    law_id: str
    law_no: str
    law_name: str
    law_type: str
    url: str

    events: list[Event]


class Update(TypedDict):
    """Law update."""

    published_date: str
    effective_date: str
    effective_comment: str

    amend_name: str
    amend_no: str
    amend_published_date: str

    pending: bool


@dataclass(slots=True)
class Summary:
    """AI-generated summary of a law."""

    title: str
    summary: str


class Law(TypedDict):
    """Law."""

    law_id: str
    law_no: str
    law_name: str
    law_type: str
    url: str

    updates: list[Update]

    summary: Summary | None


@dataclass(slots=True)
class CompareBlock:
    """Normalized Compare API block."""

    change_type: str

    xpath: str

    object_id: str

    toc_object_id: str

    old_text: str | None

    new_text: str | None


@dataclass(slots=True)
class RevisionHistory:
    """Revision history from Revision API."""

    law_data_id: int

    sub_revision: str

    amendment_id: str

    amendment_num: str

    enforcement_date: str | None

    scheduled_enforcement_date: str |None

    enforcement_comment: str | None

    is_current: bool


@dataclass(slots=True)
class LawRevision:
    """Revision metadata."""

    law_data_id: int

    revision: str

    sub_revision: str

    law_num: str

    enforcement_date: str | None

    scheduled_enforcement_date: str | None

    enforcement_comment: str | None


@dataclass(slots=True)
class CompareResult:
    """Normalized Compare API result."""

    law_id: str

    old: LawRevision

    new: LawRevision

    blocks: list[CompareBlock]


@dataclass(slots=True)
class Item:
    """Law text item."""

    object_id: str

    num: str

    text: str


@dataclass(slots=True)
class Paragraph:
    """Law text paragraph."""

    object_id: str

    num: str

    text: str

    items: list[Item]


@dataclass(slots=True)
class LawTextResult:
    """One article (or supplementary provision article)."""

    object_id: str

    kind: str

    title: str | None

    caption: str | None

    paragraphs: list[Paragraph]


@dataclass(slots=True)
class Location:
    """Display location of a law change."""

    article_object_id: str

    article: str

    paragraph: str | None = None

    item: str | None = None

    @property
    def label(self) -> str:
        """Human-readable label."""

        parts = [self.article]

        if self.paragraph:
            parts.append(self.paragraph)

        if self.item:
            parts.append(self.item)

        return " ".join(parts)


@dataclass(slots=True)
class LawTextIndex:
    """Lookup indexes for parsed law text."""

    articles: dict[str, LawTextResult]

    article_lookup: dict[str, str]

    location_lookup: dict[str, Location]


@dataclass(slots=True)
class TocIndex:
    """Parsed TOC information."""

    sel_text_list: list[str]

    location_lookup: dict[str, Location]


@dataclass(slots=True)
class LawChange:
    """Normalized law change."""

    object_id: str

    location: Location

    change_type: str

    before: str | None

    after: str | None

    article_text: LawTextResult


class ChangeType(str, Enum):
    ADDED = "added"
    DELETED = "deleted"
    MODIFIED = "modified"
    SAME = "same"


@dataclass(slots=True)
class SummaryChange:
    """Input for AI summary."""

    article: str
    paragraph: str | None

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
