import sys
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
    effective_date: str


@app.get("/")
def health_check():
    return {
        "status": "ok",
        "service": "e-Gov Law Monitor AI Summary API",
    }


@app.post("/summary")
def generate_summary(request: SummaryRequest):
    law_name = fetch_law_name(request.law_id)

    result = generator.generate_for_effective_date(
        law_id=request.law_id,
        law_name=law_name,
        effective_date=request.effective_date,
    )

    if result is None:
        raise HTTPException(
            status_code=404,
            detail="Summary could not be generated.",
        )

    return {
        "law_id": result.summary_input.law_id,
        "effective_date": request.effective_date,
        "summary": {
            "title": result.response.summary.title,
            "body": result.response.summary.body,
        },
    }