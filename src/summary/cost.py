from models import SummaryUsage

from config import (
    GEMINI_INPUT_PRICE_USD_PER_MILLION,
    GEMINI_OUTPUT_PRICE_USD_PER_MILLION,
    USD_TO_JPY_RATE,
)


def calculate_cost(
    usage: SummaryUsage,
) -> tuple[float, float]:
    """
    Calculate estimated Gemini API cost.

    Returns:
        tuple[float, float]:
            (estimated_cost_usd, estimated_cost_jpy)
    """

    input_cost_usd = (
        usage.prompt_tokens
        / 1_000_000
        * GEMINI_INPUT_PRICE_USD_PER_MILLION
    )

    output_cost_usd = (
        (
            usage.output_tokens
            + usage.thoughts_tokens
        )
        / 1_000_000
        * GEMINI_OUTPUT_PRICE_USD_PER_MILLION
    )

    estimated_cost_usd = (
        input_cost_usd
        + output_cost_usd
    )

    estimated_cost_jpy = (
        estimated_cost_usd
        * USD_TO_JPY_RATE
    )

    return (
        estimated_cost_usd,
        estimated_cost_jpy,
    )