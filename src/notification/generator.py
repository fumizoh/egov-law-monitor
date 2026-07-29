def group_updates(updates):
    """
    法令名ごとに更新をグループ化する。
    """

    grouped = {}

    for update in updates:

        law_name = update["title"]

        if law_name not in grouped:
            grouped[law_name] = []

        grouped[law_name].append(update)

    return grouped


def highlight_keywords(text, keywords):
    """
    キーワードを【】で囲んで強調する。
    """

    if not text:
        return text

    result = text

    # 長いキーワードを優先
    sorted_keywords = sorted(
        keywords,
        key=len,
        reverse=True,
    )

    for keyword in sorted_keywords:

        if not keyword:
            continue

        result = result.replace(
            keyword,
            f"【{keyword}】"
        )

    return result


def format_date(date):
    """
    YYYYMMDD → YYYY-MM-DD に変換する。
    """

    if len(date) != 8:
        return date

    return (
        f"{date[:4]}-"
        f"{date[4:6]}-"
        f"{date[6:]}"
    )


def create_email_subject(updates, date):
    """
    メール件名を生成する。
    """

    date = format_date(date)

    return (
        f"[eGov Law Monitor] "
        f"{date} "
        f"法令更新（{len(updates)}件）"
    )


def create_email_body(updates, keywords, date):
    """
    メール本文（プレーンテキスト）を生成する。
    """

    date = format_date(date)

    egov_updates = [
        update
        for update in updates
        if update["source"] == "egov"
    ]

    grouped = group_updates(egov_updates)

    lines = []

    lines.append("eGov Law Monitor")
    lines.append(date)
    lines.append("")
    lines.append(f"更新件数：{len(egov_updates)}件")
    lines.append("")
    lines.append("-" * 40)
    lines.append("")
    lines.append("更新法令一覧")
    lines.append("")

    for law_name, items in grouped.items():

        display_name = highlight_keywords(
            law_name,
            keywords,
        )

        if len(items) == 1:
            lines.append(f"・{display_name}")
        else:
            lines.append(
                f"・{display_name}（{len(items)}件）"
            )

    lines.append("")
    lines.append("-" * 40)
    lines.append("")
    lines.append("詳細はこちら")
    lines.append("https://fumizoh.github.io/egov-law-monitor/")

    return "\n".join(lines)