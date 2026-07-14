def create_statistics(
    source,
    updates,
    latest_date,
):
    """
    Create statistics for one source.
    """

    if source == "egov":

        return create_egov_statistics(
            updates=updates,
            latest_date=latest_date,
        )

    if source == "public_comment":

        return create_public_comment_statistics(
            updates=updates,
            latest_date=latest_date,
        )

    raise ValueError(
        f"Unknown source: {source}"
    )


def create_egov_statistics(
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

    return {
        "last_update": latest_date,
        "update_count": len(updates),
        "source": source_counts,
        "law_type": law_type_counts,
    }


def create_public_comment_statistics(
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