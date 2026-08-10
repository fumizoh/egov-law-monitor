"""
Application data models.
"""

from __future__ import annotations

from dataclasses import dataclass

from datetime import datetime

from typing import Any, TypedDict

from enum import Enum

from pydantic import BaseModel


class Event(TypedDict):
    """Common event model."""

    source: str
    type: str
    title: str
    url: str
    date: str
    summary: str
    metadata: dict[str, Any]


class Update(TypedDict):
    """Law update."""

    law_data_id: int
    sub_revision: str

    published_date: str | None
    effective_date: str | None
    effective_comment: str | None

    amend_name: str
    amend_no: str | None
    amend_published_date: str | None

    pending: bool


@dataclass(slots=True)
class LawGroup:
    """Events grouped by law."""

    law_id: str
    law_no: str
    law_name: str
    law_type: str
    url: str

    events: list[Event]


@dataclass(slots=True)
class CompareBlock:
    """Normalized Compare API block."""

    change_type: str

    xpath: str

    object_id: str

    old_text: str | None

    new_text: str | None


@dataclass(slots=True)
class RevisionHistory:
    """Revision metadata from Revision API."""

    law_data_id: int

    sub_revision: str

    amendment_id: str | None
    amendment_name: str | None
    amendment_num: str | None

    enforcement_date: str | None
    scheduled_enforcement_date: str | None
    enforcement_comment: str | None

    is_current: bool

    @property
    def is_new_law(self) -> bool:
        """True if this revision is the initial enactment."""
        return self.amendment_id is None


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
class Location:
    """Display location of a law change."""

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
class SummaryUsage:
    model: str
    prompt_tokens: int
    output_tokens: int
    thoughts_tokens: int
    total_tokens: int
    elapsed_seconds: float
    response_id: str


@dataclass(slots=True)
class SummaryStatistics:
    """Aggregated AI summary statistics."""

    model: str

    count: int

    prompt_tokens: int
    output_tokens: int
    thoughts_tokens: int
    total_tokens: int

    elapsed_seconds: float

    estimated_cost_usd: float
    estimated_cost_jpy: float

    average_cost_jpy: float


@dataclass(slots=True)
class Summary:
    """AI-generated summary of a law."""

    title: str
    body: str


class Law(TypedDict):
    """Law."""

    law_id: str
    law_no: str
    law_name: str
    law_type: str
    url: str

    updates: list[Update]


@dataclass(slots=True)
class CompareResult:
    """Normalized Compare API result."""

    law_id: str

    old: LawRevision

    new: LawRevision

    blocks: list[CompareBlock]


@dataclass(slots=True)
class LawChange:
    """Normalized law change."""

    object_id: str

    location: Location

    change_type: str

    before: str | None

    after: str | None


@dataclass(slots=True)
class TableChange:
    """Detected change to a supplementary table."""

    name: str


@dataclass(slots=True)
class TocIndex:
    """Parsed TOC information."""

    sel_text_list: list[str]

    location_lookup: dict[str, Location]

    table_lookup: dict[str, str]


@dataclass(slots=True)
class LawSummaryInput:
    """Input for Law Summary Service."""

    law_id: str

    law_name: str

    revisions: list[RevisionHistory]


class SummarySchema(BaseModel):
    """Structured output schema for Gemini."""

    title: str
    body: str


@dataclass(slots=True)
class SummaryResponse:
    summary: Summary
    usage: SummaryUsage


@dataclass(slots=True)
class LawSummary:
    """Cached law summary."""

    summary_input: LawSummaryInput

    response: SummaryResponse


@dataclass(slots=True)
class AiSummaryLog:
    """AI summary generation log."""

    timestamp: str
    service: str
    target: str | None

    usage: SummaryUsage


@dataclass(slots=True)
class AiStatistics:
    """Aggregated AI statistics."""

    law_summary: SummaryStatistics