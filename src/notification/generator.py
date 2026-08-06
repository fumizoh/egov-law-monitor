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


def create_email_subject(
    update_count,
    date,
):
    """
    メール件名を生成する。
    """

    date = format_date(date)

    return (
        f"[eGov Law Monitor] "
        f"{date} "
        f"法令更新（{update_count}件）"
    )


def create_email_body(
    laws,
    update_count,
    date,
):
    """
    メール本文（プレーンテキスト）を生成する。
    """

    date = format_date(date)

    lines = []

    lines.append("eGov Law Monitor")
    lines.append(date)
    lines.append("")
    lines.append(f"更新件数：{update_count}件")
    lines.append("")
    lines.append("-" * 40)
    lines.append("")
    lines.append("更新法令一覧")
    lines.append("")

    for law in laws:

        display_name = law["law_name"]

        count = len(law["updates"])

        if count == 1:
            lines.append(f"・{display_name}")
        else:
            lines.append(
                f"・{display_name}（{count}件）"
            )

    lines.append("")
    lines.append("-" * 40)
    lines.append("")
    lines.append("詳細はこちら")
    lines.append("https://fumizoh.github.io/egov-law-monitor/")

    return "\n".join(lines)