"""Create a test draft of a daily update in WordPress.

This is a test-only tool. It does not use the normal sync_daily_post()
function, so it cannot publish or update the regular daily post.
"""

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))

import storage
from wordpress.builder import build_wp_post
from wordpress.client import create_post
from wordpress.post_builder import build_post_content

POST_TYPE = "posts"
LAW_UPDATE_CATEGORY_ID = 7

def main() -> None:
    if len(sys.argv) != 2:
        print("Usage: python tools/recovery/repost_wordpress_draft.py YYYYMMDD")
        sys.exit(1)
    date = sys.argv[1]
    if len(date) != 8 or not date.isdigit():
        print("日付はYYYYMMDD形式で指定してください。")
        sys.exit(1)
    storage_paths = storage.find_storage_for_date(date)
    if storage_paths is None:
        print(f"指定した日付のデータが見つかりません: {date}")
        sys.exit(1)
    laws = storage.load_laws(paths=storage_paths)
    law_summaries = storage.load_law_summaries(paths=storage_paths)
    statistics = storage.load_statistics(paths=storage_paths)
    wp_post = build_wp_post(laws=laws, law_summaries=law_summaries, statistics_data=statistics, date=date)
    content = build_post_content(wp_post)
    post = create_post(title=f"[TEST] {wp_post.title}", content=content, excerpt=wp_post.excerpt, slug=f"{date}-update-test", status="draft", post_type=POST_TYPE, category_id=LAW_UPDATE_CATEGORY_ID)
    print("=== WordPress test draft ===")
    print(f"date: {date}")
    print(f"status: {post['status']}")
    print(f"post_id: {post['id']}")
    print(f"link: {post['link']}")

if __name__ == "__main__":
    main()
