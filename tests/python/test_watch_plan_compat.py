"""Regression tests for the WordPress watch-settings response contract."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src"))

import monitor  # noqa: E402
import watch.email as watch_email  # noqa: E402
import watch.service as watch_service  # noqa: E402


class WatchPlanCompatibilityTest(unittest.TestCase):
    def test_plan_present_or_absent_does_not_change_watch_notifications(self) -> None:
        laws = [
            {
                "law_id": "law-1",
                "law_name": "個人情報保護法",
                "url": "https://example.test/law-1",
            }
        ]
        base_user = {
            "user_id": 42,
            "email": "watcher@example.test",
            "notifications": True,
            "watches": [{"keyword": "個人情報"}],
        }

        for plan in ("standard", None):
            with self.subTest(plan=plan):
                response_user = dict(base_user)
                if plan is not None:
                    response_user["plan"] = plan

                with patch.object(
                    watch_service.client,
                    "get_watch_settings",
                    return_value=[response_user],
                ):
                    users = watch_service.get_watch_users()

                self.assertEqual(len(users), 1)
                user = users[0]
                self.assertFalse(hasattr(user, "plan"))
                self.assertEqual(user.user_id, 42)
                self.assertEqual(user.email, "watcher@example.test")
                self.assertTrue(user.notifications)

                matching_laws = watch_service.find_watched_laws(laws, user.watches)
                self.assertEqual(matching_laws, laws)
                notifications = watch_service.build_user_notifications(
                    laws,
                    [],
                    user,
                )
                self.assertEqual(len(notifications), 1)

                body = watch_email.build_body(notifications, user.watches, "2026-10-30")
                self.assertIn("個人情報保護法", body)
                self.assertIn("対象キーワード：個人情報", body)

                with (
                    patch.object(monitor.storage, "load_law_summaries", return_value={}),
                    patch.object(monitor.watch_service, "get_watch_users", return_value=users),
                    patch.object(monitor.watch_mailer, "send_email") as send_email,
                ):
                    result = monitor.send_watch_notifications(laws, {}, "2026-10-30")

                send_email.assert_called_once()
                self.assertEqual(send_email.call_args.args[0], "watcher@example.test")
                self.assertIn("個人情報保護法", send_email.call_args.args[2])
                self.assertEqual(result.target_count, 1)
                self.assertEqual(result.sent_count, 1)
                self.assertEqual(result.failed_count, 0)

    def test_required_non_plan_response_fields_remain_required(self) -> None:
        response_user = {
            "user_id": 42,
            "notifications": True,
            "watches": [],
        }

        with patch.object(
            watch_service.client,
            "get_watch_settings",
            return_value=[response_user],
        ):
            with self.assertRaises(KeyError) as error:
                watch_service.get_watch_users()

        self.assertEqual(error.exception.args, ("email",))


if __name__ == "__main__":
    unittest.main()
