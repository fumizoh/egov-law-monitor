from google import genai
from google.genai.types import HttpOptions

from models import SummaryResult

from config import LOCATION, MODEL_NAME, PROJECT_ID


def summarize(prompt: str) -> SummaryResult:
    client = genai.Client(
        vertexai=True,
        project=PROJECT_ID,
        location=LOCATION,
        http_options=HttpOptions(api_version="v1"),
    )

    response = client.models.generate_content(
        model=MODEL_NAME,
        contents=prompt,
    )

    return SummaryResult(
        title="",
        summary=response.text.strip(),
    )