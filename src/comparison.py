import re

from models import (
    CompareBlock,
    CompareResult,
    LawRevision,
    RevisionHistory,
)

from config import (
    CHANGE_ADDED,
    CHANGE_MODIFIED,
    CHANGE_REMOVED,
    CHANGE_SAME,
)

TAG_RE = re.compile(r"<[^>]+>")


def _parse_revision_history_item(
    raw: dict,
) -> RevisionHistory:

    return RevisionHistory(
        law_data_id=raw["LawDataId"],
        sub_revision=raw["SubRevision"],

        amendment_id=raw["AmendmentId"],
        amendment_name=raw["AmendmentLawTitle"],
        amendment_num=raw["AmendmentNum"],

        enforcement_date=raw["EnforcementDate"],
        scheduled_enforcement_date=raw["ScheduledEnforcementDate"],
        enforcement_comment=raw["EnforcementComment"],

        is_current=raw["IsCurrentEnforcement"],
    )


def parse_revision_history(
    history: list[dict],
) -> list[RevisionHistory]:

    return [
        _parse_revision_history_item(item)
        for item in history
    ]


def _strip_html(text: str | None) -> str | None:
    """Remove HTML tags."""

    if not text:
        return None

    text = TAG_RE.sub("", text).strip()

    return text or None


def _detect_change_type(
    old_text: str | None,
    new_text: str | None,
) -> str:
    """Detect change type."""

    if old_text and new_text:

        if old_text == new_text:
            return CHANGE_SAME

        return CHANGE_MODIFIED

    if old_text:
        return CHANGE_REMOVED

    if new_text:
        return CHANGE_ADDED

    return CHANGE_SAME


def _parse_compare_block(raw: dict) -> CompareBlock:
    """Parse one CompareBlock."""

    old = raw["OldLawBlock"]
    new = raw["NewLawBlock"]

    old_text = _strip_html(old.get("#text"))
    new_text = _strip_html(new.get("#text"))

    return CompareBlock(
        change_type=_detect_change_type(
            old_text,
            new_text,
        ),
        xpath=(
            new.get("-Xpath")
            or old.get("-Xpath")
            or ""
        ),
        object_id=(
            (
                new.get("-ObjectId")
                or old.get("-ObjectId")
                or ""
            ).lstrip("#")
        ),
        old_text=old_text,
        new_text=new_text,
    )


def _parse_law_revision(raw: dict) -> LawRevision:
    """Parse law revision."""

    return LawRevision(
        law_data_id=raw["-LawDataId"],
        revision=raw["-Revision"],
        sub_revision=raw["-SubRevision"],
        law_num=raw["LawNum"],
        enforcement_date=raw["-EnforcementDate"],
        scheduled_enforcement_date=raw["-ScheduledEnforcementDate"],
        enforcement_comment=raw["-EnforcementComment"],
    )


def parse_compare_result(
    raw: dict
) -> CompareResult | None:
    """Parse Compare API response."""

    compare_data = raw["result"]["Compare_Data"]

    blocks = compare_data["CompareInfo"].get("CompareBlock")

    if not blocks:
        return None

    blocks = [
        _parse_compare_block(block)
        for block in blocks
    ]

    return CompareResult(
        law_id=compare_data["LawId"],
        old=_parse_law_revision(compare_data["OldLawInfo"]),
        new=_parse_law_revision(compare_data["NewLawInfo"]),
        blocks=blocks,
    )