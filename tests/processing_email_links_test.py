"""python -B tests/processing_email_links_test.py; no HTTP, SMTP or DB access."""
import ast
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))
from models import ProcessingResult, WPResult, WatchNotificationResult
from notification.generator import create_email_body, create_email_subject, processing_details_url

HOME = "https://egovlm.oogushioffice.com/"


def result(wp=None, count=1, day="20261109"):
    return ProcessingResult(date=day, update_count=count, updated_law_count=count,
                            laws=[{"law_name": "民法", "updates": [{}]}] if count else [], wp=wp,
                            watch=WatchNotificationResult(target_count=1, sent_count=1, failed_count=0))


class EmailLinksTests(unittest.TestCase):
    def test_success_uses_returned_permalink(self):
        url = HOME + "20261109-update/"
        r = result(WPResult(status="success", action="created", post_id=1, post_status="publish", link=url))
        body = create_email_body(r)
        self.assertTrue(body.endswith("詳細はこちら\n" + url))
        self.assertIn("URL：" + url, body)
        self.assertIn("更新法令一覧\n\n・民法", body)
        self.assertIn("法令ウォッチ：対象 1人", body)
        self.assertNotIn("github.io", body)
        self.assertEqual(create_email_subject(r), "[e-Gov Law Monitor] 2026-11-09 法令更新（1件）")

    def test_no_updates_no_invented_date_link(self):
        r = result(count=0)
        body = create_email_body(r)
        self.assertTrue(body.endswith("詳細はこちら\n" + HOME))
        self.assertIn("新しい法令更新はありませんでした。", body)
        self.assertIn("WordPress：処理なし", body)

    def test_post_error_falls_back(self):
        r = result(WPResult(status="error", error="test-error", link=HOME + "not-published/"))
        self.assertEqual(processing_details_url(r), HOME)
        self.assertIn("エラー：test-error", create_email_body(r))
        self.assertIn("WordPress投稿エラー", create_email_subject(r))

    def test_reprocessed_date_uses_response_not_current_date(self):
        url = HOME + "existing-custom-permalink/"
        r = result(WPResult(status="success", action="updated", post_status="publish", link=url), day="20250101")
        r.watch = None
        self.assertEqual(processing_details_url(r), url)
        self.assertIn("法令ウォッチ：処理をスキップ", create_email_body(r))

    def test_missing_or_nonpublic_post_uses_list(self):
        for status in ("draft", "private", "future", None):
            with self.subTest(status=status):
                self.assertEqual(processing_details_url(result(WPResult(status="success", post_status=status, link=HOME + "draft/"))), HOME)
        for link in (None, "", "/relative/", "https://stg.oogushioffice.com/example/", "http://egovlm.oogushioffice.com/x", HOME + "?preview=true", HOME + "x#fragment", "https://user:pass@egovlm.oogushioffice.com/", "https://egovlm.oogushioffice.com:bad/x", HOME + "x\nheader", "https://example.com/", HOME + "\\evil"):
            with self.subTest(link=link):
                self.assertEqual(processing_details_url(result(WPResult(status="success", post_status="publish", link=link))), HOME)

    def test_unrelated_links_and_message_are_unchanged(self):
        expected = ["■ 処理結果", "", "e-Gov：成功", "更新件数：1件", "更新法令数：1法令", "",
                    "WordPress：処理なし", "", "法令ウォッチ：対象 1人", "送信成功：1通", "送信失敗：0通",
                    "", "-" * 40, "", "更新法令一覧", "", "・民法", "", "-" * 40, "", "詳細はこちら", HOME]
        self.assertEqual(create_email_body(result()), "\n".join(expected))
        url = "https://laws.e-gov.go.jp/example"
        body = create_email_body(result(WPResult(status="error", error="test", link=url)))
        self.assertIn("URL：" + url, body)
        self.assertTrue(body.endswith("詳細はこちら\n" + HOME))


if __name__ == "__main__":
    ast.parse((ROOT / "src/notification/generator.py").read_text(encoding="utf-8"))
    unittest.main()
