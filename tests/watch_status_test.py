"""Local unit tests: python -B tests/watch_status_test.py (no network or DB)."""
import ast
import importlib.util
import os
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]


def load_file(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


client = load_file("status_client_test", ROOT / "src/wordpress/client.py")


class ClientTests(unittest.TestCase):
    def setUp(self):
        self.env = patch.dict(os.environ, {"WP_URL": "https://wp.example", "WP_API_USERNAME": "internal-test", "WP_API_APP_PASSWORD": "test-only"})
        self.env.start()
        self.addCleanup(self.env.stop)

    def response(self, status=200):
        response = Mock(status_code=status)
        response.json.return_value = {"last_checked": "2025-01-01T00:00:00.000000Z"}
        if status >= 400:
            response.raise_for_status.side_effect = client.requests.HTTPError("test")
        return response

    def test_payload_and_auth(self):
        with patch.object(client.requests, "post", return_value=self.response()) as post:
            client.send_watch_status("2025-01-01T09:00:00+09:00")
        self.assertEqual(post.call_args.kwargs["auth"], ("internal-test", "test-only"))
        self.assertEqual(post.call_args.kwargs["json"], {"last_checked": "2025-01-01T09:00:00+09:00"})
        self.assertTrue(post.call_args.args[0].endswith("/internal/watch-status"))

    def test_transient_retries_same_time(self):
        for problem in (client.requests.ReadTimeout("test"), client.requests.ConnectionError("test"), self.response(503), self.response(429)):
            with self.subTest(problem=type(problem).__name__), patch.object(client.requests, "post", side_effect=[problem, self.response()]) as post, patch.object(client.time, "sleep"):
                client.send_watch_status("2025-01-01T00:00:00Z")
                self.assertEqual(post.call_count, 2)
                self.assertEqual(post.call_args_list[0].kwargs["json"], post.call_args_list[1].kwargs["json"])

    def test_permanent_errors_not_retried(self):
        for status in (400, 401, 403, 404):
            with self.subTest(status=status), patch.object(client.requests, "post", return_value=self.response(status)) as post, patch.object(client.time, "sleep") as sleep:
                with self.assertRaises(client.requests.HTTPError):
                    client.send_watch_status("2025-01-01T00:00:00Z")
                self.assertEqual(post.call_count, 1)
                sleep.assert_not_called()

    def test_retry_exhausted(self):
        with patch.object(client.requests, "post", side_effect=client.requests.Timeout("test")) as post, patch.object(client.time, "sleep"):
            with self.assertRaises(client.requests.Timeout):
                client.send_watch_status("2025-01-01T00:00:00Z")
            self.assertEqual(post.call_count, 3)

    def test_invalid_acknowledgement(self):
        response = self.response()
        response.json.return_value = {}
        with patch.object(client.requests, "post", return_value=response):
            with self.assertRaises(ValueError):
                client.send_watch_status("2025-01-01T00:00:00Z")

    def test_unsafe_url_and_tls_are_not_retried(self):
        with patch.dict(os.environ, {"WP_URL": "http://wp.example"}), patch.object(client.requests, "post") as post:
            with self.assertRaises(ValueError):
                client.send_watch_status("2025-01-01T00:00:00Z")
            post.assert_not_called()
        with patch.object(client.requests, "post", side_effect=client.requests.exceptions.SSLError("test")) as post:
            with self.assertRaises(client.requests.exceptions.SSLError):
                client.send_watch_status("2025-01-01T00:00:00Z")
            self.assertEqual(post.call_count, 1)


class MonitorTests(unittest.TestCase):
    def setUp(self):
        names = ("models", "sources", "pipeline", "storage", "notification", "notification.service", "wordpress", "wordpress.service", "wordpress.client", "watch", "watch.service", "watch.email", "watch.mailer")
        modules = {name: types.ModuleType(name) for name in names}
        for name, module in modules.items():
            if "." in name:
                parent, child = name.rsplit(".", 1)
                setattr(modules[parent], child, module)
        for name in ("ProcessingResult", "WatchNotificationResult", "WPResult"):
            setattr(modules["models"], name, lambda **kw: types.SimpleNamespace(**kw))
        modules["sources"].egov = types.SimpleNamespace(fetch=Mock(return_value=([{}], "20250102")))
        storage = modules["storage"]
        storage.DEFAULT_STORAGE = "normal"
        storage.REPROCESS_STORAGE = "reprocess"
        storage.save_watch_status = Mock()
        storage.load_statistics = Mock(return_value={"egov": {"last_update": "20250101"}})
        modules["pipeline"].process_egov = Mock(return_value=[])
        modules["wordpress.service"].sync_daily_post = Mock(return_value=types.SimpleNamespace(status="success", action="created", post_id=1, post_status="publish"))
        modules["wordpress.client"].send_watch_status = Mock()
        modules["notification.service"].send_processing_notification = Mock()
        with patch.dict(sys.modules, modules):
            self.monitor = load_file("status_monitor_test", ROOT / "src/monitor.py")
        self.monitor.send_watch_notifications = Mock(return_value=None)

    def test_normal_success(self):
        self.monitor.main()
        sent = self.monitor.wordpress_client.send_watch_status.call_args.args[0]
        self.assertEqual(sent, self.monitor.storage.save_watch_status.call_args.args[0]["last_checked"])
        self.assertTrue(sent.endswith("+09:00"))
        self.monitor.wordpress_service.sync_daily_post.assert_called_once()

    def test_no_new_update(self):
        self.monitor.storage.load_statistics.return_value = {"egov": {"last_update": "20250102"}}
        self.monitor.main()
        self.monitor.wordpress_client.send_watch_status.assert_called_once()
        self.monitor.wordpress_service.sync_daily_post.assert_not_called()

    def test_zero_events(self):
        self.monitor.egov.fetch.return_value = ([], "20250102")
        self.monitor.main()
        self.monitor.wordpress_client.send_watch_status.assert_called_once()

    def test_fetch_failure(self):
        self.monitor.egov.fetch.side_effect = RuntimeError("test-only-fetch-failure")
        with self.assertRaises(RuntimeError):
            self.monitor.main()
        self.monitor.storage.save_watch_status.assert_not_called()
        self.monitor.wordpress_client.send_watch_status.assert_not_called()

    def test_reprocess(self):
        self.monitor.main("20250102")
        self.monitor.wordpress_client.send_watch_status.assert_not_called()
        self.assertEqual(self.monitor.storage.save_watch_status.call_args.kwargs["paths"], "reprocess")

    def test_send_failure_does_not_stop_processing(self):
        self.monitor.wordpress_client.send_watch_status.side_effect = RuntimeError("secret-test-marker")
        with self.assertLogs(self.monitor.logger, level="WARNING") as logs:
            self.monitor.main()
        self.monitor.wordpress_service.sync_daily_post.assert_called_once()
        self.monitor.send_watch_notifications.assert_called_once()
        self.monitor.service.send_processing_notification.assert_called_once()
        self.assertNotIn("secret-test-marker", "\n".join(logs.output))

    def test_post_failure_does_not_undo_check(self):
        self.monitor.wordpress_service.sync_daily_post.side_effect = RuntimeError("post-test")
        with self.assertLogs(self.monitor.logger, level="WARNING"):
            self.monitor.main()
        self.monitor.wordpress_client.send_watch_status.assert_called_once()
        self.monitor.send_watch_notifications.assert_not_called()


if __name__ == "__main__":
    for path in (ROOT / "src/monitor.py", ROOT / "src/wordpress/client.py", Path(__file__)):
        ast.parse(path.read_text(encoding="utf-8"))
    unittest.main()
