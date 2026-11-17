"""Real JSON persistence in temporary directories; no live data or APIs.

Run: python -B tests/law_summary_storage_test.py
"""
from dataclasses import asdict
import json
from pathlib import Path
import socket
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))
import storage
from models import LawSummary, LawSummaryInput, RevisionHistory, Summary, SummaryResponse, SummaryUsage


def summary(law_id="law-A", response=None):
    revision = RevisionHistory(123, "1", "amendment-1", "改正法", "令和八年法律第一号",
                               "2026-10-01", None, "一部施行", True)
    return LawSummary(LawSummaryInput(law_id, "日本語のテスト法", [revision]), response)


class SummaryStorageTests(unittest.TestCase):
    def setUp(self):
        guard = patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden"))
        guard.start()
        self.addCleanup(guard.stop)
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.directory = Path(temp.name)
        self.paths = storage.StoragePaths(*(self.directory / name for name in (
            "laws.json", "law_summaries.json", "statistics.json", "watch_status.json")))

    def test_save_and_load_none_response(self):
        original = summary()
        storage.upsert_law_summaries([original], "20261002", paths=self.paths)
        raw = json.loads(self.paths.law_summaries.read_text(encoding="utf-8"))
        self.assertEqual(set(raw), {"date", "summaries"})
        self.assertEqual(raw["date"], "20261002")
        self.assertEqual(raw["summaries"], [asdict(original)])
        self.assertIsNone(raw["summaries"][0]["response"])
        loaded = storage.load_law_summaries(paths=self.paths)
        self.assertEqual(set(loaded), {"law-A"})
        self.assertEqual(loaded["law-A"], original)
        self.assertIsNone(loaded["law-A"].response)
        self.assertEqual(storage.get_law_summaries_date(self.paths), "20261002")
        self.assertEqual(sorted(p.name for p in self.directory.iterdir()), ["law_summaries.json"])

    def test_update_same_law_preserves_other_law(self):
        other = summary("law-B")
        storage.upsert_law_summaries([summary(), other], "20261002", paths=self.paths)
        replacement = summary(response=SummaryResponse(
            Summary("更新タイトル", "更新本文"), SummaryUsage("offline-model", 10, 5, 2, 17, 0.25, "offline-id")))
        storage.upsert_law_summaries([replacement], "20261003", paths=self.paths)
        loaded = storage.load_law_summaries(paths=self.paths)
        self.assertEqual(set(loaded), {"law-A", "law-B"})
        self.assertEqual(loaded["law-A"], replacement)
        self.assertEqual(loaded["law-B"], other)
        self.assertEqual(storage.get_law_summaries_date(self.paths), "20261003")
        raw = json.loads(self.paths.law_summaries.read_text(encoding="utf-8"))
        self.assertEqual(len(raw["summaries"]), 2, "update replaces rather than appends the same law")

    def test_existing_json_format_with_response_remains_readable(self):
        # Literal legacy-format fixture, independent of the current serializer.
        fixture = {"date": "20261002", "summaries": [{
            "summary_input": {"law_id": "law-A", "law_name": "日本語のテスト法", "revisions": [{
                "law_data_id": 123, "sub_revision": "1", "amendment_id": "amendment-1",
                "amendment_name": "改正法", "amendment_num": "令和八年法律第一号",
                "enforcement_date": "2026-10-01", "scheduled_enforcement_date": None,
                "enforcement_comment": "一部施行", "is_current": True,
            }]},
            "response": {"summary": {"title": "旧要約", "body": "旧本文"}, "usage": {
                "model": "legacy-model", "prompt_tokens": 10, "output_tokens": 5,
                "thoughts_tokens": 2, "total_tokens": 17, "elapsed_seconds": 0.25,
                "response_id": "legacy-id",
            }},
        }]}
        self.paths.law_summaries.write_text(json.dumps(fixture, ensure_ascii=False), encoding="utf-8")
        loaded = storage.load_law_summaries(paths=self.paths)
        self.assertEqual(asdict(loaded["law-A"]), fixture["summaries"][0])
        self.assertEqual(loaded["law-A"].response.summary.title, "旧要約")
        self.assertEqual(loaded["law-A"].response.usage.model, "legacy-model")
        storage.save_law_summaries(list(loaded.values()), "20261002", paths=self.paths)
        self.assertEqual(json.loads(self.paths.law_summaries.read_text(encoding="utf-8")), fixture)


if __name__ == "__main__":
    unittest.main()
