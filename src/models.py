"""
Application data models.
"""

from dataclasses import dataclass

from typing import Any, TypedDict


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

    published_date: str
    effective_date: str
    effective_comment: str

    amend_name: str
    amend_no: str
    amend_published_date: str

    pending: bool


class Law(TypedDict):
    """Law."""

    law_id: str
    law_no: str
    law_name: str
    law_type: str
    url: str

    updates: list[Update]


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
class LawRevision:

    law_data_id: int

    revision: str

    sub_revision: str

    law_num: str

    enforcement_date: str | None

    scheduled_enforcement_date: str | None

    enforcement_comment: str | None


@dataclass(slots=True)
class CompareResult:

    law_id: str

    old: LawRevision

    new: LawRevision

    blocks: list[CompareBlock]


@dataclass(slots=True)
class Paragraph:

    num: str

    text: str


@dataclass(slots=True)
class LawTextResult:

    object_id: str

    kind: str

    title: str | None

    caption: str | None

    paragraphs: list[Paragraph]


@dataclass(slots=True)
class LawChange:

    object_id: str

    kind: str

    title: str | None

    caption: str | None

    change_type: str

    before: str | None

    after: str | None