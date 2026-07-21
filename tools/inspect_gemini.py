from google import genai
from google.genai.types import HttpOptions

client = genai.Client(
    vertexai=True,
    project="project-9dc19b38-12b0-40dd-871",
    location="us-central1",
    http_options=HttpOptions(api_version="v1"),
)

response = client.models.generate_content(
    model="gemini-2.5-flash",
    contents="こんにちは",
)

print(response.text)