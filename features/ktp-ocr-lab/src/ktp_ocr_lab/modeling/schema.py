from __future__ import annotations

from pydantic import BaseModel, Field


class GroundTruthRecord(BaseModel):
    image_path: str
    fields: dict[str, str] = Field(default_factory=dict)


class CandidateRecord(BaseModel):
    image_path: str
    field: str
    candidate: str
    source_line: str
    label: int = 0
    features: dict[str, float | int | str] = Field(default_factory=dict)

