from __future__ import annotations

import numpy as np

from ktp_ocr_lab.models import OcrLine
from ktp_ocr_lab.ocr_engines.base import OcrEngine


class EasyOcrEngine(OcrEngine):
    def __init__(self, config) -> None:
        super().__init__(config)
        try:
            import easyocr
        except ImportError as exc:
            raise RuntimeError("Install optional dependency: pip install easyocr") from exc

        languages = ["id", "en"] if config.language in {"id", "ind"} else [config.language]
        self.reader = easyocr.Reader(languages, gpu=False)

    def read(self, image: np.ndarray) -> list[OcrLine]:
        result = self.reader.readtext(image)
        return [
            OcrLine(text=text, confidence=float(confidence), box=box)
            for box, text, confidence in result
        ]

