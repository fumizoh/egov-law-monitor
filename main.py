import sys
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent / "src"))

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from sources.law_api import fetch_law_name
from summary import generator


app = FastAPI(
    title="e-Gov Law Monitor AI Summary API",
)


class SummaryRequest(BaseModel):
    law_id: str
    effective_date: date


@app.get("/")
def health_check():
    return {
        "status": "ok",
        "service": "e-Gov Law Monitor AI Summary API",
    }


@app.post("/summary")
def generate_summary(request: SummaryRequest):
    try:
        law_name = fetch_law_name(request.law_id)

        result = generator.generate_for_effective_date(
            law_id=request.law_id,
            law_name=law_name,
            effective_date=request.effective_date.isoformat(),
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=404,
            detail=str(exc),
        ) from exc

    except Exception as exc:
        raise HTTPException(
            status_code=500,
            detail="Failed to generate AI summary.",
        ) from exc

    if result is None:
        raise HTTPException(
            status_code=404,
            detail="Summary could not be generated.",
        )

    law_summary = result.law_summary

    return {
        "law_id": law_summary.summary_input.law_id,
        "effective_date": request.effective_date,
        "revision_hash": result.revision_hash,
        "summary": {
            "title": law_summary.response.summary.title,
            "body": law_summary.response.summary.body,
        },
    }