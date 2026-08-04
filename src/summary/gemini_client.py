from time import perf_counter, sleep
# from pprint import pprint

from google import genai
from google.genai.types import HttpOptions
from google.genai.errors import ClientError

from models import (
    Summary,
    SummaryResponse,
    SummaryUsage,
)

from config import (
    PROJECT_ID,
    LOCATION,
    MODEL_NAME,
)


def summarize(prompt: str) -> SummaryResponse:
    client = genai.Client(
        vertexai=True,
        project=PROJECT_ID,
        location=LOCATION,
        http_options=HttpOptions(api_version="v1"),
    )

    start = perf_counter()

    for attempt in range(3):

        try:

            response = client.models.generate_content(
                model=MODEL_NAME,
                contents=prompt,
            )

            break

        except ClientError as e:

            if e.code != 429 or attempt == 2:
                raise

            wait = 10 * (2**attempt)

            print(
                f"[Retry {attempt + 1}/3] "
                f"Gemini rate limit reached. "
                f"Retry in {wait} seconds..."
            )

            sleep(wait)

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