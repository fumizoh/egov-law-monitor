"""Watch notification email builder."""

from html import escape

from models import WatchNotification, WatchSetting


def build_subject(
    notifications: list[WatchNotification],
) -> str:
    """Build email subject."""

    count = len(notifications)

    return f"【法令ウォッチ】{count}件の法令が更新されました"


def build_body(
    notifications: list[WatchNotification],
    watches: list[WatchSetting],
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

        lines.append(f"・{law['law_name']}")

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
            f"現在ウォッチ中の法令（{len(watches)}件）",
            "",
        ]
    )

    for watch in watches:
        lines.append(f"・{watch.law_name}")

    return "\n".join(lines)


def build_html(
    notifications: list[WatchNotification],
    watches: list[WatchSetting],
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
        law_url = escape(law["url"], quote=True)

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
    現在ウォッチ中の法令（{watch_count}件）
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
        law_name = escape(watch.law_name)

        # Watch APIには現在URLがないため、
        # 現時点では法令名のみ表示する。
        parts.append(
            f"""
<li style="margin: 4px 0;">
    {law_name}
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