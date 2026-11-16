"""Offline: real Gen AI SDK serialization/parsing, mocked HTTP; no ADC or APIs.

Run: python -B tests/gemini_client_test.py
"""
import hashlib
import json
from pathlib import Path
import socket
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, call, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / "src"))

from google import genai
from google.auth.credentials import AnonymousCredentials
from google.genai.errors import ClientError
from google.genai.types import HttpResponse

import config
from summary import gemini_client, generator
import main

REAL_CLIENT = genai.Client


def http_response(text='{"title":"改正の要点","body":"入力情報のみを要約。"}', usage=None):
    return HttpResponse(headers={}, body=json.dumps({
        "candidates": [{"content": {"role": "model", "parts": [{"text": text}]},
                        "finishReason": "STOP"}],
        "modelVersion": "gemini-3.8-flash",
        "responseId": "offline-response",
        "usageMetadata": usage if usage is not None else {
            "promptTokenCount": 120, "candidatesTokenCount": 40,
            "thoughtsTokenCount": 25, "totalTokenCount": 185,
        },
    }, ensure_ascii=False))


class GeminiClientTests(unittest.TestCase):
    def setUp(self):
        # Fail immediately if any code tries to open a real connection.
        self.start_patch(patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden")))
        self.request = Mock(return_value=http_response())
        self.client_options = []

        def client_factory(**kwargs):
            self.client_options.append(kwargs)
            client = REAL_CLIENT(**kwargs, credentials=AnonymousCredentials())
            self.addCleanup(client.close)
            self.start_patch(patch.object(client._api_client, "request", self.request))
            return client

        self.start_patch(patch.object(gemini_client.genai, "Client", side_effect=client_factory))
        self.sleep = self.start_patch(patch.object(gemini_client, "sleep"))
        self.start_patch(patch.object(gemini_client, "perf_counter", side_effect=[10.0, 11.25]))

    def start_patch(self, patcher):
        result = patcher.start()
        self.addCleanup(patcher.stop)
        return result

    def test_real_sdk_request_and_json_parsing(self):
        prompt = "入力に含まれる法令情報だけを日本語で要約してください。"
        result = gemini_client.summarize(prompt)
        self.assertEqual(config.MODEL_NAME, "gemini-3.8-flash")
        options = self.client_options[0]
        self.assertTrue(options["vertexai"])
        self.assertEqual(options["project"], config.PROJECT_ID)
        self.assertEqual(options["location"], "global")
        self.assertEqual(options["http_options"].api_version, "v1")
        method, path, payload, _ = self.request.call_args.args
        self.assertEqual(method, "post")
        # The API client adds the project/location prefix to this SDK-relative path.
        self.assertEqual(path, "publishers/google/models/gemini-3.8-flash:generateContent")
        self.assertEqual(payload["contents"], [{"role": "user", "parts": [{"text": prompt}]}])
        generation = payload["generationConfig"]
        self.assertEqual(set(generation), {"responseMimeType", "responseSchema"})
        self.assertEqual(generation["responseMimeType"], "application/json")
        schema = generation["responseSchema"]
        self.assertEqual(set(schema["required"]), {"title", "body"})
        self.assertEqual(set(schema["properties"]), {"title", "body"})
        self.assertEqual(result.summary.title, "改正の要点")
        self.assertEqual(result.summary.body, "入力情報のみを要約。")
        self.assertEqual(result.usage.model, "gemini-3.8-flash")
        self.assertEqual((result.usage.prompt_tokens, result.usage.output_tokens,
                          result.usage.thoughts_tokens, result.usage.total_tokens), (120, 40, 25, 185))
        self.assertEqual(result.usage.elapsed_seconds, 1.25)
        self.assertEqual(result.usage.response_id, "offline-response")
        self.sleep.assert_not_called()

    def test_missing_token_counts_default_to_zero(self):
        self.request.return_value = http_response(usage={})
        usage = gemini_client.summarize("prompt").usage
        self.assertEqual((usage.prompt_tokens, usage.output_tokens, usage.thoughts_tokens, usage.total_tokens), (0, 0, 0, 0))

    def test_invalid_json_or_schema_is_rejected(self):
        for text in ("not JSON", '{"title":"missing body"}', '{"title":{},"body":[]}'):
            with self.subTest(text=text):
                self.request.return_value = http_response(text)
                # Each subcase has its own elapsed timer pair.
                with patch.object(gemini_client, "perf_counter", side_effect=[0.0, 1.0]):
                    with self.assertRaisesRegex(RuntimeError, "no structured response"):
                        gemini_client.summarize("prompt")
        self.sleep.assert_not_called()

    def test_empty_or_blocked_response_is_rejected(self):
        self.request.return_value = HttpResponse(headers={}, body=json.dumps({
            "promptFeedback": {"blockReason": "SAFETY"},
        }))
        with self.assertRaisesRegex(RuntimeError, "no structured response"):
            gemini_client.summarize("prompt")

    def test_429_retries_then_succeeds(self):
        error = ClientError(429, {"error": {"message": "offline rate limit"}})
        self.request.side_effect = [error, error, http_response()]
        result = gemini_client.summarize("prompt")
        self.assertEqual(result.summary.title, "改正の要点")
        self.assertEqual(self.request.call_count, 3)
        self.assertEqual(self.sleep.call_args_list, [call(10), call(20)])

    def test_429_exhaustion_propagates(self):
        error = ClientError(429, {"error": {"message": "offline rate limit"}})
        self.request.side_effect = error
        with self.assertRaises(ClientError) as caught:
            gemini_client.summarize("prompt")
        self.assertIs(caught.exception, error)
        self.assertEqual(self.request.call_count, 3)
        self.assertEqual(self.sleep.call_args_list, [call(10), call(20)])

    def test_other_client_errors_are_not_retried(self):
        for code in (400, 401, 403, 404):
            with self.subTest(code=code):
                self.request.reset_mock()
                error = ClientError(code, {"error": {"message": "offline failure"}})
                self.request.side_effect = error
                with patch.object(gemini_client, "perf_counter", return_value=0.0):
                    with self.assertRaises(ClientError) as caught:
                        gemini_client.summarize("prompt")
                self.assertIs(caught.exception, error)
                self.request.assert_called_once()
        self.sleep.assert_not_called()

    def test_transport_error_propagates_without_retry(self):
        self.request.side_effect = OSError("offline connection failure")
        with self.assertRaisesRegex(OSError, "offline connection failure"):
            gemini_client.summarize("prompt")
        self.request.assert_called_once()
        self.sleep.assert_not_called()

    def test_existing_revision_cache_still_skips_generation(self):
        revisions = [SimpleNamespace(law_data_id=123, sub_revision="1")]
        # Legacy hash payload contains revision identity only, never model ID.
        legacy_hash = hashlib.sha256(b'[[123,"1"]]').hexdigest()
        self.assertEqual(generator._calculate_revision_hash(revisions), legacy_hash)
        with patch.object(main, "fetch_law_name", return_value="テスト法"), \
             patch.object(generator.builder, "build_law_summary_input_for_revision",
                          return_value=SimpleNamespace(revisions=revisions)), \
             patch.object(generator, "get_revision_metadata_for_revision", return_value=(None, None)), \
             patch.object(generator, "generate_for_revision", side_effect=AssertionError("Cache must not generate")):
            result = main.generate_summary(main.SummaryRequest(
                law_id="test-law", effective_date="2026-11-01", law_data_id=123,
                sub_revision="1", revision_hash=legacy_hash,
            ))
        self.assertTrue(result["cached"])
        self.assertEqual(result["revision_hash"], legacy_hash)
        self.assertNotIn("summary", result)
        self.assertEqual(self.client_options, [])
        self.request.assert_not_called()


if __name__ == "__main__":
    unittest.main()
