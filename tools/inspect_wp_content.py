"""Inspect generated WordPress post content."""

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage
from wordpress.builder import build_wp_post
from wordpress.post_builder import (
    build_post_content,
    build_post_title,
)


OUTPUT_PATH = Path("tmp/wp_post.html")


def main() -> None:
    """Generate and save WordPress post HTML."""

    laws = storage.load_laws()
    law_summaries = storage.load_law_summaries()

    statistics = storage.load_statistics()
    date = statistics["egov"]["last_update"]

    wp_post = build_wp_post(
        laws=laws,
        law_summaries=law_summaries,
        date=date,
    )

    title = build_post_title(wp_post)
    content = build_post_content(wp_post)

    html = f"""<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{title}</title>
    <link rel="stylesheet" href="../docs/css/style.css">
</head>
<body>

<div class="container">

<h1>{title}</h1>

{content}

</div>

</body>
</html>
"""

    OUTPUT_PATH.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    OUTPUT_PATH.write_text(
        html,
        encoding="utf-8",
    )

    print("WordPress post content generated.")
    print()
    print(f"title: {title}")
    print(f"laws:  {len(wp_post.wp_laws)}")
    print()
    print(f"output: {OUTPUT_PATH}")


if __name__ == "__main__":
    main()