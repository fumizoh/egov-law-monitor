from models import SummaryUsage


def merge_usage(
    usages: list[SummaryUsage],
    *,
    model: str,
    response_id: str,
    elapsed_seconds: float,
) -> SummaryUsage:
    """Merge multiple usage records."""

    return SummaryUsage(
        model=model,
        prompt_tokens=sum(u.prompt_tokens for u in usages),
        output_tokens=sum(u.output_tokens for u in usages),
        thoughts_tokens=sum(u.thoughts_tokens for u in usages),
        total_tokens=sum(u.total_tokens for u in usages),
        elapsed_seconds=elapsed_seconds,
        response_id=response_id,
    )