from __future__ import annotations

from pathlib import Path
from typing import Any

from pydantic import BaseModel, Field


class PreprocessingConfig(BaseModel):
    auto_orient: bool = True
    resize_scale: float = 1.0
    grayscale: bool = True
    denoise: bool = False
    sharpen: bool = False
    contrast: str = "none"
    threshold: str = "none"
    deskew: bool = False


class OcrConfig(BaseModel):
    engine: str = "mock"
    language: str = "id"
    psm: int | None = None
    oem: int | None = None
    multi_pass: bool = True
    roi_scales: list[int] = Field(default_factory=lambda: [2])
    roi_psms: list[int] = Field(default_factory=lambda: [11])
    max_width: int | None = None
    text_detection_model_name: str | None = None
    text_recognition_model_name: str | None = None
    text_det_limit_side_len: int | None = None


class ParserConfig(BaseModel):
    normalize_labels: bool = True
    strict_nik: bool = True


class RoiFieldConfig(BaseModel):
    key: str
    x: float
    y: float
    w: float
    h: float
    upscale: float = 2.0
    threshold: str = "none"


class PipelineConfig(BaseModel):
    name: str
    description: str = ""
    preprocessing: PreprocessingConfig = Field(default_factory=PreprocessingConfig)
    ocr: OcrConfig = Field(default_factory=OcrConfig)
    parser: ParserConfig = Field(default_factory=ParserConfig)
    roi_fields: list[RoiFieldConfig] = Field(default_factory=list)


class OcrLine(BaseModel):
    text: str
    confidence: float | None = None
    box: list[list[float]] | None = None


class FieldResult(BaseModel):
    value: str | None = None
    confidence: float | None = None
    source: str | None = None
    warnings: list[str] = Field(default_factory=list)


class QualityReport(BaseModel):
    width: int
    height: int
    blur_score: float
    too_small: bool
    likely_blurry: bool


class KtpExtractionResult(BaseModel):
    image_path: str
    config_name: str
    engine: str
    quality: QualityReport
    fields: dict[str, FieldResult]
    raw_text: str
    lines: list[OcrLine]
    metadata: dict[str, Any] = Field(default_factory=dict)


def load_config(path: Path) -> PipelineConfig:
    return PipelineConfig.model_validate_json(path.read_text(encoding="utf-8-sig"))
