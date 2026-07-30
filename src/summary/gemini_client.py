from time import perf_counter
# from pprint import pprint

from google import genai
from google.genai.types import HttpOptions

from models import Summary, SummaryResponse, SummaryUsage

from config import PROJECT_ID, LOCATION, MODEL_NAME


def summarize(prompt: str) -> SummaryResponse:
    client = genai.Client(
        vertexai=True,
        project=PROJECT_ID,
        location=LOCATION,
        http_options=HttpOptions(api_version="v1"),
    )

    start = perf_counter()

    response = client.models.generate_content(
        model=MODEL_NAME,
        contents=prompt,
    )

    elapsed = perf_counter() - start

    # pprint(response)

    usage = response.usage_metadata

    return SummaryResponse(
        summary=Summary(
            title="",
            body=response.text.strip(),
        ),
        usage=SummaryUsage(
            model=response.model_version,
            prompt_tokens=usage.prompt_token_count or 0,
            output_tokens=usage.candidates_token_count or 0,
            thoughts_tokens=usage.thoughts_token_count or 0,
            total_tokens=usage.total_token_count or 0,
            elapsed_seconds=elapsed,
            response_id=response.response_id,
        ),
    )