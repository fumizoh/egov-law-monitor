"""Offline diagnostics: mock HTTP only, use real response parsers/builders.

Run: python -B tests/compare_inspection_test.py
"""
from contextlib import redirect_stderr, redirect_stdout
from copy import deepcopy
import importlib.util
from io import StringIO
from pathlib import Path
import runpy
import socket
import sys
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools/inspect/test_summary_pipeline.py"


def revision(number):
    return {"-LawDataId": number, "-Revision": "1", "-SubRevision": "1",
            "LawNum": "テスト法令", "-EnforcementDate": "2026-10-01",
            "-ScheduledEnforcementDate": None, "-EnforcementComment": None}


def responses():
    toc = {"result": {"Toc_Data": {"TocBody": {"children": [
        {"-ObjectId": "#Article1", "-Label": "第一条", "-Xpath": "/Law/MainProvision/Article[1]"},
        {"-ObjectId": "#Table1", "-Label": "別表一", "-Xpath": "/Law/AppdxTable[1]"},
    ]}}}}
    blocks = []
    for i in range(6):
        table = i == 5
        node = {"-ObjectId": "#Table1" if table else "#Article1",
                "-Xpath": "/Law/AppdxTable[1]/Table[1]" if table else "/Law/MainProvision/Article[1]"}
        blocks.append({"OldLawBlock": dict(node, **{"#text": "旧本文"}),
                       "NewLawBlock": dict(node, **{"#text": f"RAW_BLOCK_{i}"})})
    raw = {"result": {"success": True, "Compare_Data": {
        "LawId": "test-law", "OldLawInfo": revision(10), "NewLawInfo": revision(123),
        "CompareInfo": {"CompareBlock": blocks},
    }}}
    return toc, raw


class CompareInspectionTests(unittest.TestCase):
    def setUp(self):
        guard = patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden"))
        guard.start()
        self.addCleanup(guard.stop)
        spec = importlib.util.spec_from_file_location("offline_compare_inspection", SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.toc, self.raw = responses()
        self.urls = []

        def post(url, **kwargs):
            self.urls.append(url)
            if url == self.module.toc_api.TOC_URL:
                self.assertEqual(kwargs["json"], {"law_data_id": 123, "subRevision": "1"})
                return Mock(**{"json.return_value": deepcopy(self.toc)})
            if url == self.module.compare_api.COMPARE_URL:
                self.assertEqual(kwargs["json"]["new_law_data_id"], 123)
                self.assertEqual(kwargs["json"]["new_subRevision"], "1")
                self.assertIn("LawTitle", kwargs["json"]["selTextList"])
                return Mock(**{"json.return_value": deepcopy(self.raw)})
            raise AssertionError("Unexpected endpoint")

        transport = patch.object(self.module.compare_api.requests, "post", side_effect=post)
        self.post = transport.start()
        self.addCleanup(transport.stop)

    def invoke(self, details=False):
        out, err = StringIO(), StringIO()
        args = ["--law-data-id", "123", "--sub-revision", "1"]
        if details:
            args.append("--details")
        with redirect_stdout(out), redirect_stderr(err):
            code = self.module.main(args)
        return code, out.getvalue(), err.getvalue()

    def assert_no_duplicate_requests(self):
        self.assertEqual(self.urls, [self.module.toc_api.TOC_URL, self.module.compare_api.COMPARE_URL])

    def test_normal_real_pipeline_no_raw_details(self):
        code, out, err = self.invoke()
        self.assertEqual(code, 0)
        self.assertEqual(err, "")
        for stage in range(1, 7):
            self.assertIn(f"[{stage}]", out)
        self.assertIn("changes: 5", out)
        self.assertIn("table_changes: 1", out)
        self.assertIn("CompareBlock count: 6", out)
        self.assertNotIn("RAW_BLOCK_", out)
        self.assertNotIn("CompareInfo keys:", out)
        self.assert_no_duplicate_requests()

    def test_details_use_same_response_and_show_at_most_five_blocks(self):
        code, out, err = self.invoke(details=True)
        self.assertEqual(code, 0)
        self.assertEqual(err, "")
        self.assertIn("Compare_Data keys:", out)
        self.assertIn("CompareInfo keys:", out)
        self.assertEqual(out.count("--- block "), 5)
        self.assertIn("RAW_BLOCK_4", out)
        self.assertNotIn("RAW_BLOCK_5", out)
        self.assertIn("1 more blocks", out)
        self.assert_no_duplicate_requests()

    def test_compare_data_not_found_has_distinct_exit(self):
        self.raw = {"result": {"success": False, "errorMessage": "指定の新旧比較情報が存在しません。"}}
        code, out, err = self.invoke()
        self.assertEqual(code, 3)
        self.assertIn("CompareDataNotFoundError", err)
        self.assertNotIn("[2]", out)
        self.assert_no_duplicate_requests()

    def test_other_compare_error_has_general_failure_exit(self):
        self.raw = {"result": {"success": False, "errorMessage": "offline upstream failure"}}
        code, out, err = self.invoke()
        self.assertEqual(code, 1)
        self.assertIn("RuntimeError", err)
        self.assertNotIn("CompareDataNotFoundError", err)
        self.assertNotIn("[2]", out)
        self.assert_no_duplicate_requests()

    def test_missing_blocks_are_distinct_from_empty(self):
        del self.raw["result"]["Compare_Data"]["CompareInfo"]["CompareBlock"]
        code, out, err = self.invoke(details=True)
        self.assertEqual(code, 4)
        self.assertIn("MISSING", out)
        self.assertNotIn("EMPTY", out)
        self.assertNotIn("[3]", out)
        self.assertEqual(err, "")
        self.assert_no_duplicate_requests()

    def test_empty_blocks_are_distinct_from_missing(self):
        self.raw["result"]["Compare_Data"]["CompareInfo"]["CompareBlock"] = []
        code, out, err = self.invoke()
        self.assertEqual(code, 4)
        self.assertIn("EMPTY (count: 0)", out)
        self.assertNotIn("MISSING", out)
        self.assertNotIn("[3]", out)
        self.assertEqual(err, "")
        self.assert_no_duplicate_requests()

    def test_null_blocks_report_null(self):
        self.raw["result"]["Compare_Data"]["CompareInfo"]["CompareBlock"] = None
        code, out, _ = self.invoke()
        self.assertEqual(code, 4)
        self.assertIn("NULL", out)
        self.assertNotIn("MISSING", out)

    def test_malformed_response_fails_without_later_steps(self):
        del self.raw["result"]["Compare_Data"]["NewLawInfo"]
        code, out, err = self.invoke()
        self.assertEqual(code, 1)
        self.assertIn("[2] Parse compare result: FAILED: KeyError", err)
        self.assertNotIn("[3]", out)

    def test_wrong_block_type_fails(self):
        self.raw["result"]["Compare_Data"]["CompareInfo"]["CompareBlock"] = {}
        code, _, err = self.invoke()
        self.assertEqual(code, 1)
        self.assertIn("CompareBlock must be an array", err)

    def test_revision_mismatch_stops_instead_of_refetching(self):
        self.raw["result"]["Compare_Data"]["NewLawInfo"]["-LawDataId"] = 999
        code, out, err = self.invoke()
        self.assertEqual(code, 1)
        self.assertIn("does not match", err)
        self.assertNotIn("[3]", out)
        self.assert_no_duplicate_requests()

    def test_transport_failure_is_not_retried(self):
        self.post.side_effect = TimeoutError("offline timeout")
        code, out, err = self.invoke()
        self.assertEqual(code, 1)
        self.assertIn("[1] Compare API: FAILED: TimeoutError", err)
        self.assertNotIn("[2]", out)
        self.post.assert_called_once()

    def test_downstream_failures_have_stage_and_nonzero_exit(self):
        for function, stage in (("build_law_changes", 5), ("build_table_changes", 6)):
            with self.subTest(function=function):
                owner = self.module.law_change if stage == 5 else self.module.table_change
                self.urls.clear()
                with patch.object(owner, function, side_effect=ValueError("offline failure")):
                    code, out, err = self.invoke()
                self.assertEqual(code, 1)
                self.assertIn(f"[{stage}]", err)
                self.assertNotIn("All inspection steps completed", out)
                self.assert_no_duplicate_requests()

    def test_invalid_arguments_and_help_do_not_call_apis(self):
        for args in (["--law-data-id", "0"], ["--law-data-id", "abc"],
                     ["--sub-revision", ""], ["--help"]):
            with self.subTest(args=args), redirect_stdout(StringIO()), redirect_stderr(StringIO()):
                with self.assertRaises(SystemExit) as caught:
                    self.module.main(args)
                self.assertEqual(caught.exception.code, 0 if args == ["--help"] else 2)
        self.post.assert_not_called()

    def test_import_does_not_run_diagnostics(self):
        with redirect_stdout(StringIO()) as out:
            runpy.run_path(str(SCRIPT), run_name="import_only")
        self.assertEqual(out.getvalue(), "")
        self.post.assert_not_called()

    def test_cli_propagates_exit_code(self):
        args = [str(SCRIPT), "--law-data-id", "123", "--sub-revision", "1"]
        for expected in (0, 3):
            with self.subTest(expected=expected):
                self.urls.clear()
                if expected == 3:
                    self.raw = {"result": {"success": False, "errorMessage": "指定の新旧比較情報が存在しません。"}}
                with patch.object(sys, "argv", args), redirect_stdout(StringIO()), redirect_stderr(StringIO()):
                    with self.assertRaises(SystemExit) as caught:
                        runpy.run_path(str(SCRIPT), run_name="__main__")
                self.assertEqual(caught.exception.code, expected)
                self.assert_no_duplicate_requests()


if __name__ == "__main__":
    unittest.main()
