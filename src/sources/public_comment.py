"""
e-Gov Public Comment source.
"""

import feedparser
import re

RSS_URL = "https://public-comment.e-gov.go.jp/rss/pcm_list.xml"


def strip_html(text):
    """Remove HTML tags."""

    text = re.sub(r"<br\s*/?>", "\n", text)
    text = re.sub(r"<[^>]+>", "", text)

    return text.strip()


def parse_summary(summary):
    """Parse summary text."""

    info = {
        "published_date": "",
        "deadline": "",
        "category": "",
    }

    patterns = {
        "published_date": r"案の公示日：([^\n]+)",
        "deadline": r"受付締切日時：([^\n]+)",
        "category": r"カテゴリー：([^\n]+)",
    }

    for key, pattern in patterns.items():

        match = re.search(pattern, summary)

        if match:
            info[key] = match.group(1).strip()

    return info


def fetch():
    """Fetch public comments."""

    feed = feedparser.parse(RSS_URL)

    entries = feed.entries

    updates = []

    for entry in feed.entries:

        summary = strip_html(entry.summary)

        info = parse_summary(summary)

        updates.append(
            {
                "source": "public_comment",
                "type": "public_comment",
                "title": entry.title,
                "url": entry.link,
                "date": entry.updated,
                "summary": summary,
                "metadata": {
                    "published_date": info["published_date"],
                    "deadline": info["deadline"],
                    "category": info["category"],
                },
            }
        )

    latest_date = None

    if entries:

        latest_date = entries[0].updated

    return updates, latest_date