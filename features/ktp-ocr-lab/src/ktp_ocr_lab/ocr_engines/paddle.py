from __future__ import annotations

import os

import cv2
import numpy as np

from ktp_ocr_lab.models import OcrLine
from ktp_ocr_lab.ocr_engines.base import OcrEngine


class PaddleOcrEngine(OcrEngine):
    def __init__(self, config) -> None:
        super().__init__(config)
        os.environ.setdefault("FLAGS_use_onednn", "0")
        os.environ.setdefault("FLAGS_enable_pir_api", "0")
        try:
            from paddleocr import PaddleOCR
        except ImportError as exc:
            raise RuntimeError("Install optional dependency: pip install paddleocr") from exc

        lang = "en" if config.language in {"id", "ind", "eng"} else config.language
        self.reader = PaddleOCR(
            lang=lang,
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
            use_textline_orientation=False,
            text_detection_model_name=config.text_detection_model_name,
            text_recognition_model_name=config.text_recognition_model_name,
            text_det_limit_side_len=config.text_det_limit_side_len or 736,
        )

    def read(self, image: np.ndarray) -> list[OcrLine]:
        image = self._ensure_color(image)
        image = self._limit_size(image)
        try:
            result = self.reader.ocr(image, cls=True)
        except TypeError:
            result = self.reader.ocr(image)
        lines: list[OcrLine] = []
        for page in result or []:
            if isinstance(page, dict):
                rec_texts = page.get("rec_texts") or []
                rec_scores = page.get("rec_scores") or []
                rec_boxes = page.get("rec_boxes")
                if rec_boxes is None:
                    rec_boxes = page.get("dt_polys")
                if rec_boxes is None:
                    rec_boxes = []
                for index, text in enumerate(rec_texts):
                    confidence = rec_scores[index] if index < len(rec_scores) else None
                    box = rec_boxes[index].tolist() if index < len(rec_boxes) and hasattr(rec_boxes[index], "tolist") else None
                    if box and (not isinstance(box[0], list)):
                        box = None
                    lines.append(OcrLine(text=text, confidence=float(confidence) if confidence is not None else None, box=box))
                continue

            for item in page or []:
                box, payload = item
                text, confidence = payload
                lines.append(OcrLine(text=text, confidence=float(confidence), box=box))
        return lines

    def _ensure_color(self, image: np.ndarray) -> np.ndarray:
        if len(image.shape) == 2:
            return np.stack([image, image, image], axis=-1)
        return image

    def _limit_size(self, image: np.ndarray) -> np.ndarray:
        if not self.config.max_width:
            return image
        height, width = image.shape[:2]
        if width <= self.config.max_width:
            return image
        scale = self.config.max_width / width
        return cv2.resize(image, (self.config.max_width, int(height * scale)), interpolation=cv2.INTER_AREA)
