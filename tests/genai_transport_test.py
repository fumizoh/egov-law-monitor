"""SDK 3 HTTP transport compatibility, with no sockets or real credentials."""
import importlib.util
import json
from pathlib import Path
import socket
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))
from google import genai
from google.auth.credentials import Credentials
from google.genai.errors import ClientError, ServerError
from summary import gemini_client

HAS_HTTPX2 = importlib.util.find_spec("httpx2") is not None
if HAS_HTTPX2:
    import httpx2

REAL_CLIENT = genai.Client


class OfflineCredentials(Credentials):
    def __init__(self):
        super().__init__()
        self.token = "offline-test-token"

    def refresh(self, request):
        raise AssertionError("Credential refresh forbidden")


@unittest.skipUnless(HAS_HTTPX2, "SDK 3 default transport required")
class GenaiTransportTests(unittest.TestCase):
    def setUp(self):
        self.requests = []
        self.statuses = []
        self.failure = None
        self.clients = []
        self.patch(patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden")))
        self.patch(patch.object(socket, "create_connection", side_effect=AssertionError("Network forbidden")))
        self.pause = self.patch(patch.object(gemini_client, "sleep"))
        self.patch(patch("tenacity.nap.time.sleep"))

        def handler(request):
            self.requests.append(request)
            if self.failure:
                raise self.failure
            status = self.statuses.pop(0) if self.statuses else 200
            body = {"error": {"code": status, "message": "offline failure", "status": "UNAVAILABLE"}}
            if status == 200:
                body = {
                    "candidates": [{"content": {"role": "model", "parts": [{"text": '{"title":"title","body":"body"}'}]}}],
                    "modelVersion": "gemini-3.8-flash",
                    "responseId": "offline",
                    "usageMetadata": {"promptTokenCount": 2, "candidatesTokenCount": 3, "thoughtsTokenCount": 4, "totalTokenCount": 9},
                }
            return httpx2.Response(status, json=body, request=request)

        def factory(**kwargs):
            # Preserve the application's API options; replace only network transport/auth.
            kwargs["http_options"].client_args = {"transport": httpx2.MockTransport(handler)}
            client = REAL_CLIENT(**kwargs, credentials=OfflineCredentials())
            self.clients.append(client)
            self.addCleanup(client.close)
            return client

        self.patch(patch.object(gemini_client.genai, "Client", side_effect=factory))

    def patch(self, patcher):
        value = patcher.start()
        self.addCleanup(patcher.stop)
        return value

    def test_global_url_schema_parsing_and_usage_through_real_transport(self):
        with patch.dict("os.environ", {"GOOGLE_GENAI_API_VERSION": "v1beta"}):
            result = gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 1)
        request = self.requests[0]
        self.assertEqual(str(request.url), "https://aiplatform.googleapis.com/v1/projects/" + gemini_client.PROJECT_ID + "/locations/global/publishers/google/models/gemini-3.8-flash:generateContent")
        self.assertEqual(request.method, "POST")
        body = json.loads(request.content)
        self.assertEqual(body["generationConfig"]["responseMimeType"], "application/json")
        self.assertEqual(set(body["generationConfig"]["responseSchema"]["required"]), {"title", "body"})
        self.assertEqual((result.summary.title, result.summary.body), ("title", "body"))
        self.assertEqual((result.usage.prompt_tokens, result.usage.output_tokens, result.usage.thoughts_tokens, result.usage.total_tokens), (2, 3, 4, 9))
        self.assertEqual(result.usage.response_id, "offline")
        self.assertIsNone(self.clients[0]._api_client._http_options.timeout)

    def test_429_uses_application_retry_when_sdk_retry_not_configured(self):
        self.statuses = [429, 200]
        gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 2)
        self.assertEqual([c.args[0] for c in self.pause.call_args_list], [10])

    def test_exhausted_429_has_three_total_attempts(self):
        self.statuses = [429] * 3
        with self.assertRaises(ClientError):
            gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 3)
        self.assertEqual([c.args[0] for c in self.pause.call_args_list], [10, 20])

    def test_503_propagates_without_retry(self):
        self.statuses = [503]
        with self.assertRaises(ServerError):
            gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 1)
        self.pause.assert_not_called()

    def test_400_is_not_retried(self):
        self.statuses = [400]
        with self.assertRaises(ClientError):
            gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 1)
        self.pause.assert_not_called()

    def test_httpx2_timeout_propagates(self):
        self.failure = httpx2.ReadTimeout("offline timeout")
        with self.assertRaises(httpx2.ReadTimeout):
            gemini_client.summarize("fixed input")
        self.assertEqual(len(self.requests), 1)
        self.pause.assert_not_called()


if __name__ == "__main__":
    unittest.main()
