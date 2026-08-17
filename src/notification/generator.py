from models import ProcessingResult


def format_date(date: str) -> str:
    """YYYYMMDD → YYYY-MM-DD に変換する。"""

    if len(date) != 8:
        return date

    return (
        f"{date[:4]}-"
        f"{date[4:6]}-"
        f"{date[6:]}"
    )


def create_email_subject(
    result: ProcessingResult,
) -> str:
    """メール件名を生成する。"""

    date = format_date(result.date)

    if result.wp is not None and result.wp.status == "error":
        return (
            f"[e-Gov Law Monitor] "
            f"{date} "
            f"WordPress投稿エラー"
        )

    if result.update_count == 0:
        return (
            f"[e-Gov Law Monitor] "
            f"{date} "
            f"新しい法令更新はありません"
        )

    return (
        f"[e-Gov Law Monitor] "
        f"{date} "
        f"法令更新（{result.update_count}件）"
    )


def create_email_body(
    result: ProcessingResult,
) -> str:
    """メール本文（プレーンテキスト）を生成する。"""

    date = format_date(result.date)

    lines = []

    lines.extend(
        [
            "■ 処理結果",
            "",
            "e-Gov：成功",
            f"更新件数：{result.update_count}件",
            f"更新法令数：{result.updated_law_count}法令",
            "",
        ]
    )

    if result.wp is None:
        lines.append("WordPress：処理なし")
    else:
        lines.append(
            f"WordPress：{result.wp.status}"
        )

        if result.wp.error:
            lines.append(
                f"エラー：{result.wp.error}"
            )

        if result.wp.action:
            lines.append(
                f"投稿処理：{result.wp.action}"
            )

        if result.wp.post_id is not None:
            lines.append(
                f"投稿ID：{result.wp.post_id}"
            )

        if result.wp.post_status:
            lines.append(
                f"公開状態：{result.wp.post_status}"
            )

        if result.wp.link:
            lines.append(
                f"URL：{result.wp.link}"
            )

    if result.update_count == 0:
        lines.extend(
            [
                "",
                "新しい法令更新はありませんでした。",
            ]
        )
    else:
        lines.extend(
            [
                "",
                "-" * 40,
                "",
                "更新法令一覧",
                "",
            ]
        )

        for law in result.laws:
            count = len(law["updates"])

            if count == 1:
                lines.append(
                    f"・{law['law_name']}"
                )
            else:
                lines.append(
                    f"・{law['law_name']}（{count}件）"
                )

    lines.extend(
        [
            "",
            "-" * 40,
            "",
            "詳細はこちら",
            "https://fumizoh.github.io/egov-law-monitor/",
        ]
    )

    return "\n".join(lines)