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


def build_subject(
    notifications: list[WatchNotification],
) -> str:
    """Build email subject."""

    count = len(notifications)

    return f"【法令ウォッチ】{count}件の法令が更新されました"


def build_body(
    notifications: list[WatchNotification],
    watches: list[WatchSetting],
    update_date: str,
) -> str:
    """Build plain-text email body."""

    lines: list[str] = [
        "法令ウォッチ対象の法令が更新されました。",
        "",
        "今回更新された法令",
        "",
    ]

    for notification in notifications:
        law = notification.law
        summary = notification.summary

        law_url = build_update_url(
            update_date,
            law["law_id"],
        )

        lines.append(
            f"・{law['law_name']}"
        )
        lines.append(law_url)

        if summary is not None:
            lines.append(summary.title)
        else:
            lines.append("AIサマリなし")

        lines.append(law["url"])
        lines.append("")

    lines.extend(
        [
            "──────────────────",
            "",
            f"現在設定中のキーワード（{len(watches)}件）",
            "",
        ]
    )

    for watch in watches:
        lines.append(f"・{watch.keyword}")

    return "\n".join(lines)


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
    ウォッチ対象の法令が
    <strong>{count}件</strong>
    更新されました。
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
        summary = notification.summary

        law_name = escape(law["law_name"])

        law_url = escape(
            build_update_url(
                update_date,
                law["law_id"],
            ),
            quote=True,
        )

        title = (
            escape(summary.title)
            if summary is not None
            else "AIサマリなし"
        )

        parts.append(
            f"""
<div style="
    margin: 0 0 12px;
    padding: 16px;
    background: #ffffff;
    border: 1px solid #e1e5e8;
    border-radius: 8px;
">
    <div style="margin-bottom: 4px;">
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
        font-size: 14px;
        color: #555555;
    ">
        {title}
    </div>
</div>
"""
        )

    parts.append(
        """
<div style="
    margin-top: 32px;
    padding-top: 20px;
    border-top: 1px solid #d9dde1;
">

<h2 style="
    margin: 0 0 12px;
    font-size: 15px;
    font-weight: 600;
">
    現在設定中のキーワード（{watch_count}件）
</h2>

<ul style="
    margin: 0;
    padding-left: 20px;
    font-size: 13px;
    color: #666666;
">
""".format(watch_count=len(watches))
    )

    for watch in watches:
        keyword = escape(watch.keyword)

        parts.append(
            f"""
<li style="margin: 4px 0;">
    {keyword}
</li>
"""
        )

    parts.append(
        """
</ul>

</div>

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