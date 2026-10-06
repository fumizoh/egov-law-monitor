import logging

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
    law_data_id: int
    sub_revision: str
    revision_hash: str = ""


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

        current_revision_hash = (
            generator.get_revision_hash_for_revision(
                law_id=request.law_id,
                law_name=law_name,
                effective_date=request.effective_date.isoformat(),
                law_data_id=request.law_data_id,
                sub_revision=request.sub_revision,
            )
        )

        if (
            request.revision_hash
            and request.revision_hash == current_revision_hash
        ):
            (
                amendment_name,
                comparison_effective_date,
            ) = generator.get_revision_metadata_for_revision(
                law_id=request.law_id,
                law_name=law_name,
                effective_date=request.effective_date.isoformat(),
                law_data_id=request.law_data_id,
                sub_revision=request.sub_revision,
            )

            return {
                "law_id": request.law_id,
                "law_name": law_name,
                "effective_date": request.effective_date,
                "law_data_id": request.law_data_id,
                "sub_revision": request.sub_revision,
                "amendment_name": amendment_name,
                "comparison_effective_date": comparison_effective_date,
                "revision_hash": current_revision_hash,
                "cached": True,
            }

        result = generator.generate_for_revision(
            law_id=request.law_id,
            law_name=law_name,
            effective_date=request.effective_date.isoformat(),
            law_data_id=request.law_data_id,
            sub_revision=request.sub_revision,
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=404,
            detail=str(exc),
        ) from exc

    except Exception as exc:
        logging.exception(
            "Failed to generate AI summary: law_id=%s, effective_date=%s, "
            "law_data_id=%s, sub_revision=%s",
            request.law_id,
            request.effective_date,
            request.law_data_id,
            request.sub_revision,
        )
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
        "law_name": law_name,
        "effective_date": request.effective_date,
        "law_data_id": request.law_data_id,
        "sub_revision": request.sub_revision,
        "amendment_name": result.amendment_name,
        "comparison_effective_date": result.comparison_effective_date,
        "revision_hash": result.revision_hash,
        "cached": False,
        "summary": {
            "title": law_summary.response.summary.title,
            "body": law_summary.response.summary.body,
        },
    }
