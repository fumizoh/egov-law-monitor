"""Generate an AI summary for selected law revisions."""

from __future__ import annotations

from pathlib import Path
import argparse
import json
import sys
from pprint import pprint

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from models import LawSummaryInput, RevisionHistory
from summary import generator


def _load_summaries() -> list[dict]:
    """Load existing law summaries."""
    path = Path("docs/data/law_summaries.json")
    with path.open(encoding="utf-8") as f:
        summaries = json.load(f)
    if not isinstance(summaries, list):
        raise ValueError("law_summaries.json must contain a JSON array.")
    return summaries


def _get_summary(summaries: list[dict], index: int) -> dict:
    """Return a summary selected by zero-based index."""
    if index < 0 or index >= len(summaries):
        raise ValueError(
            f"Summary index out of range: {index} (0-{len(summaries) - 1})"
        )
    return summaries[index]


def _build_summary_input(data: dict) -> LawSummaryInput:
    """Build LawSummaryInput from an existing law_summaries entry."""
    summary_input = data.get("summary_input")

    if not isinstance(summary_input, dict):
        raise ValueError("Selected summary has no summary_input.")

    revisions = [
        RevisionHistory(
            law_data_id=revision["law_data_id"],
            sub_revision=revision["sub_revision"],
            amendment_id=revision.get("amendment_id"),
            amendment_name=revision.get("amendment_name"),
            amendment_num=revision.get("amendment_num"),
            enforcement_date=revision.get("enforcement_date"),
            scheduled_enforcement_date=revision.get(
                "scheduled_enforcement_date"
            ),
            enforcement_comment=revision.get("enforcement_comment"),
            is_current=revision["is_current"],
        )
        for revision in summary_input["revisions"]
    ]

    return LawSummaryInput(
        law_id=summary_input["law_id"],
        law_name=summary_input["law_name"],
        revisions=revisions,
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compare AI summaries for an entry in law_summaries.json."
    )
    parser.add_argument(
        "index", type=int, help="Zero-based index in docs/data/law_summaries.json"
    )
    parser.add_argument(
        "--models", nargs="+", default=None, metavar="MODEL",
        help="Gemini model(s) to test. If omitted, the configured model is used.",
    )
    args = parser.parse_args()

    summaries = _load_summaries()
    data = _get_summary(summaries, args.index)
    summary_input = _build_summary_input(data)

    print("=== Selected Summary ===")
    print(f"index: {args.index}")
    print(f"law_id: {summary_input.law_id}")
    print(f"law_name: {summary_input.law_name}")

    print("\n=== Revisions ===")
    for revision in summary_input.revisions:
        print(
            f"- law_data_id={revision.law_data_id}, "
            f"sub_revision={revision.sub_revision}, "
            f"amendment={revision.amendment_name}, "
            f"amendment_num={revision.amendment_num}, "
            f"enforcement_date={revision.enforcement_date}, "
            f"scheduled={revision.scheduled_enforcement_date}"
        )

    print("\n=== Existing Summary ===")
    existing_response = data.get("response", {})
    existing_summary = existing_response.get("summary", {})
    existing_usage = existing_response.get("usage", {})
    print("Title:")
    print(existing_summary.get("title"))
    print("\nBody:")
    print(existing_summary.get("body"))
    print("\nUsage:")
    pprint(existing_usage)

    models = args.models or [existing_usage.get("model")]
    models = list(dict.fromkeys(model for model in models if model))
    if not models:
        raise ValueError("No model specified.")

    from summary import gemini_client
    original_model = gemini_client.MODEL_NAME
    try:
        for model_name in models:
            print("\n" + "=" * 72)
            print(f"=== Model: {model_name} ===")
            print("=" * 72)
            gemini_client.MODEL_NAME = model_name
            try:
                result = generator._generate_law_summary(summary_input)
            except Exception as exc:
                print("\n=== Generation Error ===")
                print(f"{type(exc).__name__}: {exc}")
                continue
            if result is None:
                print("\n=== Result ===")
                print("Summary generation failed.")
                continue
            print("\n=== Title ===")
            print(result.summary.title)
            print("\n=== Body ===")
            print(result.summary.body)
            print("\n=== Usage ===")
            pprint(result.usage)
    finally:
        gemini_client.MODEL_NAME = original_model


if __name__ == "__main__":
    main()
