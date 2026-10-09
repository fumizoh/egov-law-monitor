"""Watch notification email builder."""

from html import escape

from models import WatchNotification, WatchSetting


def build_update_url(
    update_date: str,
    law_id: str,
) -> str:
    """Build the URL for the law on the update post."""

    return (
        f"https://egovlm.oogushioffice.com/"
        f"{update_date}-update/#law-{law_id}"
    )


def _matched_keywords(
    law_name: str,
    watches: list[WatchSetting],
) -> list[str]:
    """Return watched keywords contained in the law name."""

    return [
        watch.keyword
        for watch in watches
        if watch.keyword in law_name
    ]


def build_subject(
    notifications: list[WatchNotification],
) -> str:
    """Build email subject."""

    count = len(notifications)

    return f"法令が{count}件更新されました"


def build_body(
    notifications: list[WatchNotification],
    watches: list[WatchSetting],
    update_date: str,
) -> str:
    """Build plain-text email body."""

    lines: list[str] = [
        "ウォッチ対象の法令に更新がありました。",
        "",
        "今回更新された法令",
        "",
    ]

    for notification in notifications:
        law = notification.law
        law_url = build_update_url(
            update_date,
            law["law_id"],
        )
        keywords = _matched_keywords(law["law_name"], watches)

        lines.append(f"・{law['law_name']}")
        lines.append(
            f"  対象キーワード：{'、'.join(keywords)}"
        )
        lines.append(law_url)
        lines.append(law["url"])
        lines.append("")

    return "\\n".join(lines)


def build_html(
    notifications: list[WatchNotification],
    watches: list[WatchSetting],
    update_date: str,
) -> str:
    """Build HTML email body."""

    count = len(notifications)

    parts: list[str] = [
        """
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="
    margin: 0;
    padding: 0;
    background-color: #f5f7f9;
    color: #333333;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI',
                 'Noto Sans JP', sans-serif;
    line-height: 1.6;
">
<div style="
    max-width: 640px;
    margin: 0 auto;
    padding: 24px 16px;
">

<p style="margin: 0 0 24px;">
    ウォッチ対象の法令に更新がありました。
    <strong>{count}件</strong>
</p>

<h2 style="
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 600;
">
    今回更新された法令
</h2>
""".format(count=count)
    ]

    for notification in notifications:
        law = notification.law
        law_name = escape(law["law_name"])
        law_url = escape(
            build_update_url(
                update_date,
                law["law_id"],
            ),
            quote=True,
        )
        keywords = _matched_keywords(law["law_name"], watches)
        keyword_text = escape("、".join(keywords))

        parts.append(
            f"""
<div style="
    margin: 0 0 12px;
    padding: 16px;
    background: #ffffff;
    border: 1px solid #e1e5e8;
    border-radius: 8px;
">
    <div style="margin-bottom: 8px;">
        <a href="{law_url}" style="
            color: #1a5fb4;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
        ">
            {law_name}
        </a>
    </div>

    <div style="
        font-size: 13px;
        color: #666666;
    ">
        対象キーワード：{keyword_text}
    </div>
</div>
"""
        )

    parts.append(
        """
<p style="
    margin: 24px 0 0;
    font-size: 12px;
    color: #888888;
">
    e-Gov Law Monitor
</p>

</div>
</body>
</html>
"""
    )

    return "".join(parts)
