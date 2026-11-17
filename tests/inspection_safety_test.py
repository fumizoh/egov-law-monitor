"""Offline safety tests for manual inspection entrypoints; no live APIs or data."""
from contextlib import redirect_stderr, redirect_stdout
import importlib.util
from io import StringIO
from pathlib import Path
import socket
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, call, patch

ROOT = Path(__file__).resolve().parents[1]
INSPECT = ROOT / "tools/inspect"
SCRIPTS = (
    "inspect_watch_email", "inspect_watch_notifications", "inspect_wp_excerpt",
    "test_egov_api", "test_law_api", "test_pipeline_no_ai",
    "test_revision_effective_date", "test_summary_pipeline", "test_watch_users",
)


def load(name):
    spec = importlib.util.spec_from_file_location("safety_" + name, INSPECT / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def invoke(module, args):
    out, err = StringIO(), StringIO()
    with redirect_stdout(out), redirect_stderr(err):
        code = module.main(args)
    return code, out.getvalue(), err.getvalue()


def users():
    return [{"user_id": number, "email": f"private{number}@example.test", "plan": plan,
             "notifications": enabled, "watches": [{"keyword": "民法"}]}
            for number, plan, enabled in ((1, "free", False), (2, "standard", True))]


class InspectionSafetyTests(unittest.TestCase):
    def setUp(self):
        guard = patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden"))
        guard.start()
        self.addCleanup(guard.stop)
        self.law = {"law_id": "test-law", "law_name": "民法", "url": "https://example.test/law", "updates": []}

    def test_all_nine_import_without_network_or_output(self):
        import requests
        with patch.object(requests.sessions.Session, "request", side_effect=AssertionError("HTTP forbidden")) as http:
            for name in SCRIPTS:
                with self.subTest(name=name), redirect_stdout(StringIO()) as out, redirect_stderr(StringIO()) as err:
                    load(name)
                    self.assertEqual(out.getvalue(), "")
                    self.assertEqual(err.getvalue(), "")
            http.assert_not_called()

    def test_modified_entrypoints_help_requires_no_requests(self):
        import requests
        with patch.object(requests.sessions.Session, "request", side_effect=AssertionError("HTTP forbidden")) as http:
            for name in SCRIPTS:
                if name == "inspect_wp_excerpt":
                    continue  # Deliberately unchanged; has no CLI parser.
                with self.subTest(name=name), redirect_stdout(StringIO()), redirect_stderr(StringIO()):
                    with self.assertRaises(SystemExit) as caught:
                        load(name).main(["--help"])
                    self.assertEqual(caught.exception.code, 0)
            http.assert_not_called()

    def test_law_name_uses_explicit_id(self):
        module = load("test_law_api")
        with patch.object(module, "fetch_law_name", return_value="テスト法") as fetch:
            code, out, err = invoke(module, ["--law-id", "explicit-law"])
        self.assertEqual(code, 0)
        self.assertIn("テスト法", out)
        self.assertEqual(err, "")
        fetch.assert_called_once_with("explicit-law")

    def test_revision_dates_are_configurable(self):
        module = load("test_revision_effective_date")
        with patch.object(module, "get_revisions_for_effective_date", return_value=[]) as fetch:
            code, out, _ = invoke(module, ["--law-id", "law", "--date", "2026-10-01", "--date", "2026-11-01"])
        self.assertEqual(code, 0)
        self.assertIn("count: 0", out)
        self.assertEqual(fetch.call_args_list, [call("law", "2026-10-01"), call("law", "2026-11-01")])

    def test_v2_arguments_and_provision_locations(self):
        module = load("test_egov_api")
        for location_args, expected_count in (([], 3), (["--article", "第二条", "--paragraph", "第一項"], 1)):
            with self.subTest(location_args=location_args), \
                 patch.object(module, "find_revision_id", return_value="revision-id") as find, \
                 patch.object(module, "fetch_law_data", return_value={"law_full_text": {}}) as fetch, \
                 patch.object(module, "get_provision_text", return_value=("見出し", "本文")) as provision:
                code, out, _ = invoke(module, ["--law-id", "law", "--effective-date", "2026-10-01",
                                             "--amendment-id", "amendment"] + location_args)
                self.assertEqual(code, 0)
                self.assertIn("本文", out)
                self.assertEqual(find.call_args.args[0], "law")
                revision = find.call_args.args[1]
                self.assertEqual((revision.enforcement_date, revision.amendment_id), ("2026-10-01", "amendment"))
                fetch.assert_called_once_with("revision-id")
                self.assertEqual(provision.call_count, expected_count)
                if location_args:
                    self.assertEqual(provision.call_args.args[1].article, "第二条")

    def test_invalid_inputs_rejected_before_any_api(self):
        cases = (("test_law_api", []), ("test_revision_effective_date", ["--law-id", "law", "--date", "2026-02-30"]),
                 ("test_egov_api", ["--law-id", "law", "--effective-date", "2026-02-30"]),
                 ("test_egov_api", ["--law-id", "law", "--effective-date", "2026-10-01", "--item", "一"]),
                 ("test_pipeline_no_ai", ["--date", "20260230"]), ("test_watch_users", ["--user-id", "0"]),
                 ("inspect_watch_email", ["--keyword", " "]), ("inspect_watch_email", ["--keyword", "民法", "--date", "20260230"]))
        for name, args in cases:
            with self.subTest(name=name, args=args), redirect_stdout(StringIO()), redirect_stderr(StringIO()):
                with self.assertRaises(SystemExit) as caught:
                    load(name).main(args)
                self.assertEqual(caught.exception.code, 2)

    def test_watch_users_excludes_personal_details_and_filters(self):
        module = load("test_watch_users")
        with patch.object(module.service.client, "get_watch_settings", return_value=users()):
            code, out, _ = invoke(module, [])
            self.assertEqual(code, 0)
            self.assertIn("notifications: OFF", out)
            self.assertIn("notifications: ON", out)
            self.assertNotIn("@", out)
            self.assertNotIn("民法", out)
            code, out, _ = invoke(module, ["--user-id", "2"])
        self.assertEqual(code, 0)
        self.assertIn("matched_users: 1", out)
        self.assertNotIn("notifications: OFF", out)

    def test_off_candidates_and_on_targets_are_distinct(self):
        module = load("inspect_watch_notifications")
        with patch.object(module.service.client, "get_watch_settings", return_value=users()), \
             patch.object(module.storage, "load_laws", return_value={"test-law": self.law}), \
             patch.object(module.storage, "load_law_summaries", return_value={}):
            code, out, err = invoke(module, [])
        self.assertEqual(code, 0)
        self.assertEqual(err, "")
        self.assertIn("keyword_match_count: 1; notification_target_count: 0", out)
        self.assertIn("keyword-match-only (notifications OFF)", out)
        self.assertIn("keyword_match_count: 1; notification_target_count: 1", out)
        self.assertIn("notification-target: test-law", out)
        self.assertNotIn("@", out)

    def test_wordpress_errors_do_not_leak_personal_data_or_credentials(self):
        for name in ("test_watch_users", "inspect_watch_notifications"):
            module = load(name)
            with self.subTest(name=name), patch.object(module.service, "get_watch_users", side_effect=RuntimeError("private@example.test SECRET_TOKEN")):
                code, out, err = invoke(module, [])
                self.assertEqual(code, 1)
                self.assertNotIn("private@example.test", out + err)
                self.assertNotIn("SECRET_TOKEN", out + err)
                self.assertIn("RuntimeError", err)

    def test_email_preview_reads_selected_storage_without_writing_by_default(self):
        module = load("inspect_watch_email")
        with patch.object(module.storage, "load_laws", return_value={"test-law": self.law}) as laws, \
             patch.object(module.storage, "load_law_summaries", return_value={}), \
             patch.object(module.storage, "load_statistics", return_value={"egov": {"last_update": "20261117"}}), \
             patch.object(Path, "open", side_effect=AssertionError("Unexpected file write")):
            code, out, _ = invoke(module, ["--keyword", "民法", "--storage", "reprocess"])
        self.assertEqual(code, 0)
        self.assertIn("20261117-update", out)
        self.assertIn("keyword_match_count: 1", out)
        laws.assert_called_once_with(module.storage.REPROCESS_STORAGE)

    def test_explicit_preview_output_is_new_external_file_only(self):
        module = load("inspect_watch_email")
        with tempfile.TemporaryDirectory() as temporary, \
             patch.object(module.storage, "load_laws", return_value={"test-law": self.law}), \
             patch.object(module.storage, "load_law_summaries", return_value={}):
            output = Path(temporary) / "preview.html"
            args = ["--keyword", "民法", "--date", "20261117", "--output", str(output)]
            code, _, _ = invoke(module, args)
            self.assertEqual(code, 0)
            self.assertIn("民法", output.read_text(encoding="utf-8"))
            before = output.read_bytes()
            with redirect_stdout(StringIO()), redirect_stderr(StringIO()), self.assertRaises(SystemExit):
                module.main(args)
            self.assertEqual(output.read_bytes(), before)
        with redirect_stdout(StringIO()), redirect_stderr(StringIO()), self.assertRaises(SystemExit):
            module.main(["--keyword", "民法", "--output", str(ROOT / "docs/data/forbidden-preview.html")])

    def test_pipeline_artifacts_and_real_json_saves_are_temporary(self):
        module = load("test_pipeline_no_ai")
        original_download, original_extract = module.egov_bulk.DOWNLOAD_DIR, module.storage.EXTRACT_DIR
        roots = []
        original_save = module.storage.save_json

        def fetch(date):
            self.assertEqual(date, "20261117")
            root = module.egov_bulk.DOWNLOAD_DIR.parent
            roots.append(root)
            self.assertNotEqual(root, ROOT)
            self.assertTrue(module.storage.EXTRACT_DIR.is_relative_to(root))
            module.egov_bulk.DOWNLOAD_DIR.mkdir()
            (module.egov_bulk.DOWNLOAD_DIR / "fixture.zip").write_bytes(b"fixture")
            module.storage.EXTRACT_DIR.mkdir()
            (module.storage.EXTRACT_DIR / "fixture.csv").write_text("fixture", encoding="utf-8")
            return [], date

        def save(data, output_path):
            self.assertTrue(output_path.is_relative_to(roots[0]))
            original_save(data, output_path)
            self.assertTrue(output_path.is_file())

        with patch.object(module.egov, "fetch", side_effect=fetch), patch.object(module.storage, "save_json", side_effect=save) as saves:
            code, out, err = invoke(module, ["--date", "20261117"])
        self.assertEqual(code, 0)
        self.assertEqual(err, "")
        self.assertIn("laws: 0", out)
        self.assertEqual(saves.call_count, 2)
        self.assertFalse(roots[0].exists())
        self.assertEqual(module.egov_bulk.DOWNLOAD_DIR, original_download)
        self.assertEqual(module.storage.EXTRACT_DIR, original_extract)

    def test_pipeline_failure_restores_paths_and_cleans_up(self):
        module = load("test_pipeline_no_ai")
        old = (module.egov_bulk.DOWNLOAD_DIR, module.storage.EXTRACT_DIR)
        roots = []

        def fail(date):
            roots.append(module.egov_bulk.DOWNLOAD_DIR.parent)
            raise RuntimeError("SENSITIVE_ERROR")

        with patch.object(module.egov, "fetch", side_effect=fail):
            code, out, err = invoke(module, [])
        self.assertEqual(code, 1)
        self.assertNotIn("SENSITIVE_ERROR", out + err)
        self.assertEqual((module.egov_bulk.DOWNLOAD_DIR, module.storage.EXTRACT_DIR), old)
        self.assertFalse(roots[0].exists())


if __name__ == "__main__":
    unittest.main()
