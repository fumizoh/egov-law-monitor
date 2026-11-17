"""Offline article/excerpt regressions with fixed data; no storage or HTTP.

Run: python -B tests/wordpress_builder_test.py
"""
from copy import deepcopy
from dataclasses import asdict
from pathlib import Path
import socket
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))
from models import LawSummary, LawSummaryInput, Summary, SummaryResponse, SummaryUsage
from wordpress.builder import build_wp_post


def update(new=False, number=1):
    return {
        "law_data_id": number, "sub_revision": str(number),
        "published_date": "2026-09-01", "effective_date": "2026-10-01",
        "effective_comment": "一部施行", "amend_name": "新規制定" if new else "改正法",
        "amend_no": None if new else "令和八年法律第一号",
        "amendment_id": None if new else "amendment-1",
        "amend_published_date": None if new else "2026-09-02",
        "compare_url": None if new else "https://example.test/compare", "pending": True,
    }


def law(name, new=False, kind="法律", revisions=1):
    return {
        "law_id": name, "law_no": "令和八年法律第一号", "law_name": name,
        "law_type": kind, "url": "https://example.test/law/" + name,
        "updates": [update(new, n + 1) for n in range(revisions)],
    }


def article(laws, summaries=None):
    counts = {kind: sum(item["law_type"] == kind for item in laws)
              for kind in dict.fromkeys(item["law_type"] for item in laws)}
    return build_wp_post(
        laws={item["law_id"]: item for item in laws},
        law_summaries={} if summaries is None else summaries,
        statistics_data={"egov": {
            "last_update": "20261002", "update_count": sum(len(item["updates"]) for item in laws),
            "updated_law_count": len(laws), "law_type": counts, "law_count": counts,
        }},
        date="20261002",
    )


class ArticleTests(unittest.TestCase):
    def setUp(self):
        # Any accidentally introduced API/SMTP connection fails instead of escaping.
        guard = patch.object(socket.socket, "connect", side_effect=AssertionError("Network forbidden"))
        guard.start()
        self.addCleanup(guard.stop)

    def test_amendments_only(self):
        self.assertEqual(article([law("改正法A"), law("改正法B"), law("改正法C")]).excerpt,
                         "3法令が更新されました。<br><strong>改正：3法令</strong><br>「改正法A」 ほか2法令")

    def test_amendments_and_new_law(self):
        self.assertEqual(article([law("改正法A"), law("改正法B"), law("新規法A", True)]).excerpt,
                         "3法令が更新されました。<br><strong>改正：2法令</strong><br>「改正法A」 ほか1法令"
                         "<br><strong>新規制定：1法令</strong><br>「新規法A」")

    def test_new_law_boundaries(self):
        for count in (1, 2, 3, 4):
            with self.subTest(count=count):
                laws = [law("新規法" + str(n), True) for n in range(1, count + 1)]
                names = " ".join("「新規法" + str(n) + "」" for n in range(1, min(count, 3) + 1))
                expected = f"{count}法令が更新されました。<br><strong>新規制定：{count}法令</strong><br>{names}"
                if count == 4:
                    expected += " ほか1法令"
                self.assertEqual(article(laws).excerpt, expected)

    def test_zero_laws(self):
        post = article([])
        self.assertEqual(post.excerpt, "0法令が更新されました。")
        self.assertEqual(post.law_groups, [])

    def test_single_and_multiple_amendments(self):
        for count in (1, 2, 3):
            with self.subTest(count=count):
                laws = [law("改正法" + str(n)) for n in range(1, count + 1)]
                expected = f"{count}法令が更新されました。<br><strong>改正：{count}法令</strong><br>「改正法1」"
                if count > 1:
                    expected += f" ほか{count - 1}法令"
                self.assertEqual(article(laws).excerpt, expected)

    def test_multiple_revisions_do_not_duplicate_law_counts(self):
        post = article([law("改正法A", revisions=3), law("新規法A", True, revisions=2)])
        self.assertEqual(post.statistics.update_count, 5)
        self.assertEqual(post.statistics.updated_law_count, 2)
        self.assertEqual([len(item.wp_revisions) for item in post.law_groups[0].laws], [3, 2])
        self.assertEqual(post.excerpt, "2法令が更新されました。<br><strong>改正：1法令</strong><br>「改正法A」"
                         "<br><strong>新規制定：1法令</strong><br>「新規法A」")

    def test_update_conversion_and_article_metadata(self):
        source = law("テスト法")
        before = deepcopy(source)
        post = article([source])
        actual = post.law_groups[0].laws[0]
        self.assertEqual(asdict(actual.wp_revisions[0]), {
            "law_data_id": 1, "sub_revision": "1", "amendment_id": "amendment-1",
            "amendment_name": "改正法", "amendment_num": "令和八年法律第一号",
            "enforcement_date": "2026-10-01", "enforcement_comment": "一部施行",
            "published_date": "2026-09-01", "amend_published_date": "2026-09-02",
            "compare_url": "https://example.test/compare", "pending": True,
        })
        self.assertFalse(actual.wp_revisions[0].is_new_law)
        self.assertEqual((actual.law_id, actual.law_name, actual.law_no, actual.law_url),
                         ("テスト法", "テスト法", source["law_no"], source["url"]))
        self.assertEqual(post.date, "20261002")
        self.assertEqual(post.title, "2026年10月02日の法令更新")
        self.assertEqual(post.statistics.last_update, "20261002")
        self.assertEqual(source, before, "builder must not mutate input")

    def test_grouping_and_order_by_law_type(self):
        post = article([law("府省令A", kind="府省令"), law("政令A", kind="政令"),
                        law("法律A"), law("法律B")])
        self.assertEqual([group.law_type for group in post.law_groups], ["法律", "政令", "府省令"])
        self.assertEqual([[item.law_id for item in group.laws] for group in post.law_groups],
                         [["法律A", "法律B"], ["政令A"], ["府省令A"]])
        self.assertEqual(post.statistics.law_count, {"法律": 2, "政令": 1, "府省令": 1})

    def test_summary_absent_none_and_present(self):
        response = SummaryResponse(Summary("要約タイトル", "要約本文"),
                                   SummaryUsage("offline-model", 10, 5, 0, 15, 0.1, "offline-id"))
        for label, summaries, expected in (
            ("absent", {}, None),
            ("response=None", {"テスト法": LawSummary(LawSummaryInput("テスト法", "テスト法", []), None)}, None),
            ("present", {"テスト法": LawSummary(LawSummaryInput("テスト法", "テスト法", []), response)}, response.summary),
        ):
            with self.subTest(label=label):
                post = article([law("テスト法")], summaries)
                actual = post.law_groups[0].laws[0].summary
                self.assertEqual(actual, expected)
                self.assertEqual(sum(item.summary is not None for group in post.law_groups for item in group.laws),
                                 0 if expected is None else 1)


if __name__ == "__main__":
    unittest.main()
