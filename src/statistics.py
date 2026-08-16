""" statistics.py """

from config import LAW_TYPE_ORDER


def _create_egov_statistics(
    events,
    laws,
    latest_date,
):
    """
    Create statistics for e-Gov.
    """

    source_counts = {}
    law_type_counts = {}
    law_counts = {}

    for event in events:

        src = event["source"]

        source_counts[src] = (
            source_counts.get(src, 0) + 1
        )

        law_type = event["metadata"]["law_type"]

        law_type_counts[law_type] = (
            law_type_counts.get(law_type, 0) + 1
        )

    for law in laws:
        law_type = law["law_type"]

        law_counts[law_type] = (
            law_counts.get(law_type, 0) + 1
        )

    ordered_law_type_counts = {
        law_type: law_type_counts[law_type]
        for law_type in LAW_TYPE_ORDER
        if law_type in law_type_counts
    }

    ordered_law_counts = {
        law_type: law_counts[law_type]
        for law_type in LAW_TYPE_ORDER
        if law_type in law_counts
    }

    return {
        "last_update": latest_date,
        "update_count": len(events),
        "updated_law_count": len(laws),
        "source": source_counts,
        "law_type": ordered_law_type_counts,
        "law_count": ordered_law_counts,
    }


def create_source_statistics(
    source,
    events,
    laws,
    latest_date,
):
    """
    Create statistics for one source.
    """

    if source == "egov":

        return _create_egov_statistics(
            events=events,
            laws=laws,
            latest_date=latest_date,
        )

    raise ValueError(
        f"Unknown source: {source}"
    )