from models import (
    AiStatistics,
    SummaryAction,
    SummaryLog,
)


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


def create_ai_statistics(
    logs: list[SummaryLog],
) -> AiStatistics:

    generated = 0
    reused = 0

    prompt_tokens = 0
    output_tokens = 0
    thoughts_tokens = 0
    total_tokens = 0

    elapsed_seconds = 0.0

    for log in logs:
        if log.action is SummaryAction.GENERATE:
            generated += 1
        elif log.action is SummaryAction.REUSE:
            reused += 1

        if log.usage is None:
            continue

        prompt_tokens += log.usage.prompt_tokens
        output_tokens += log.usage.output_tokens
        thoughts_tokens += log.usage.thoughts_tokens
        total_tokens += log.usage.total_tokens
        elapsed_seconds += log.usage.elapsed_seconds

    return AiStatistics(
        generated=generated,
        reused=reused,
        prompt_tokens=prompt_tokens,
        output_tokens=output_tokens,
        thoughts_tokens=thoughts_tokens,
        total_tokens=total_tokens,
        elapsed_seconds=round(elapsed_seconds, 2),
    )