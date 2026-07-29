from __future__ import annotations

from abc import ABC, abstractmethod
from functools import lru_cache

import numpy as np

from ktp_ocr_lab.models import OcrConfig, OcrLine


class OcrEngine(ABC):
    def __init__(self, config: OcrConfig) -> None:
        self.config = config

    @abstractmethod
    def read(self, image: np.ndarray) -> list[OcrLine]:
        raise NotImplementedError


def create_engine(config: OcrConfig) -> OcrEngine:
    return _create_engine_cached(config.model_dump_json())


@lru_cache(maxsize=8)
def _create_engine_cached(config_json: str) -> OcrEngine:
    config = OcrConfig.model_validate_json(config_json)
    if config.engine == "mock":
        from ktp_ocr_lab.ocr_engines.mock import MockOcrEngine

        return MockOcrEngine(config)
    if config.engine == "paddleocr":
        from ktp_ocr_lab.ocr_engines.paddle import PaddleOcrEngine

        return PaddleOcrEngine(config)
    if config.engine == "easyocr":
        from ktp_ocr_lab.ocr_engines.easy import EasyOcrEngine

        return EasyOcrEngine(config)
    if config.engine == "tesseract":
        from ktp_ocr_lab.ocr_engines.tesseract import TesseractOcrEngine

        return TesseractOcrEngine(config)

    raise ValueError(f"Unsupported OCR engine: {config.engine}")
