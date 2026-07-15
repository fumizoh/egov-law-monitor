"""
Application data models.
"""

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