""" statistics.py """

from config import LAW_TYPE_ORDER


def _create_egov_statistics(
    updates,
    latest_date,
):
    """
    Create statistics for e-Gov.
    """

    source_counts = {}
    law_type_counts = {}

    for update in updates:

        src = update["source"]

        source_counts[src] = (
            source_counts.get(src, 0) + 1
        )

        law_type = update["metadata"]["law_type"]

        law_type_counts[law_type] = (
            law_type_counts.get(law_type, 0) + 1
        )

    ordered_law_type_counts = {
        law_type: law_type_counts[law_type]
        for law_type in LAW_TYPE_ORDER
        if law_type in law_type_counts
    }

    return {
        "last_update": latest_date,
        "update_count": len(updates),
        "source": source_counts,
        "law_type": ordered_law_type_counts,
    }


def _create_public_comment_statistics(
    updates,
    latest_date,
):
    """
    Create statistics for Public Comment.
    """

    source_counts = {}
    category_counts = {}

    for update in updates:

        src = update["source"]

        source_counts[src] = (
            source_counts.get(src, 0) + 1
        )

        category = update["metadata"]["category"]

        category_counts[category] = (
            category_counts.get(category, 0) + 1
        )

    return {
        "last_update": latest_date,
        "update_count": len(updates),
        "source": source_counts,
        "category": category_counts,
    }


def create_source_statistics(
    source,
    updates,
    latest_date,
):
    """
    Create statistics for one source.
    """

    if source == "egov":

        return _create_egov_statistics(
            updates=updates,
            latest_date=latest_date,
        )

    if source == "public_comment":

        return _create_public_comment_statistics(
            updates=updates,
            latest_date=latest_date,
        )

    raise ValueError(
        f"Unknown source: {source}"
    )