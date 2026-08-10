import csv

from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

import storage

from summary import cost


CSV_PATH = Path("data/logs") / "ai_summary_log.csv"


def main() -> None:
    """Export AI summary logs to CSV."""

    logs = storage.load_ai_summary_logs()

    CSV_PATH.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with open(
        CSV_PATH,
        "w",
        newline="",
        encoding="utf-8",
    ) as f:

        writer = csv.writer(f)

        writer.writerow(
            [
                "timestamp",
                "service",
                "target",
                "model",
                "prompt_tokens",
                "output_tokens",
                "thoughts_tokens",
                "total_tokens",
                "elapsed_seconds",
                "estimated_cost_usd",
                "estimated_cost_jpy",
                "response_id",
            ]
        )

        for log in logs:

            estimated_cost_usd, estimated_cost_jpy = (
                cost.calculate_cost(
                    log.usage,
                )
            )

            writer.writerow(
                [
                    log.timestamp,
                    log.service,
                    log.target,
                    log.usage.model,
                    log.usage.prompt_tokens,
                    log.usage.output_tokens,
                    log.usage.thoughts_tokens,
                    log.usage.total_tokens,
                    round(
                        log.usage.elapsed_seconds,
                        2,
                    ),
                    round(
                        estimated_cost_usd,
                        3,
                    ),
                    round(
                        estimated_cost_jpy,
                        1,
                    ),
                    log.usage.response_id,
                ]
            )

    print(
        f"Exported {len(logs)} AI summary logs to {CSV_PATH}"
    )


if __name__ == "__main__":
    main()