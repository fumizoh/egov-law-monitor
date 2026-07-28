from dataclasses import dataclass

from enum import Enum, auto


@dataclass(slots=True, frozen=True)
class SummaryRevisionKey:
    """Revision key used to determine whether a summary must be regenerated."""

    law_data_id: int
    sub_revision: str


class SummaryAction(Enum):
    """Action to take for summary generation."""

    GENERATE = auto()
    REUSE = auto()


class SummaryReason(Enum):
    """Reason for the summary decision."""

    NEW_LAW = auto()
    NEW_REVISION = auto()
    SUMMARY_MISSING = auto()
    PROMPT_CHANGED = auto()
    UNCHANGED = auto()


@dataclass(slots=True)
class SummaryDecision:
    """Decision whether a summary should be generated."""

    action: SummaryAction
    reason: SummaryReason
