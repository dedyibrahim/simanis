from __future__ import annotations

import cv2
import numpy as np
from pathlib import Path

from ktp_ocr_lab.models import OcrLine
from ktp_ocr_lab.ocr_engines.base import OcrEngine


class TesseractOcrEngine(OcrEngine):
    def __init__(self, config) -> None:
        super().__init__(config)
        try:
            import pytesseract
        except ImportError as exc:
            raise RuntimeError("Install optional dependency: pip install pytesseract") from exc

        self.pytesseract = pytesseract
        windows_binary = Path("C:/Program Files/Tesseract-OCR/tesseract.exe")
        if windows_binary.exists():
            self.pytesseract.pytesseract.tesseract_cmd = str(windows_binary)

    def read(self, image: np.ndarray) -> list[OcrLine]:
        rgb = cv2.cvtColor(image, cv2.COLOR_GRAY2RGB) if len(image.shape) == 2 else image
        rgb = self._limit_size(rgb)
        tesseract_config = []
        if self.config.oem is not None:
            tesseract_config.append(f"--oem {self.config.oem}")
        if self.config.psm is not None:
            tesseract_config.append(f"--psm {self.config.psm}")

        data = self.pytesseract.image_to_data(
            rgb,
            lang=self.config.language,
            config=" ".join(tesseract_config),
            output_type=self.pytesseract.Output.DICT,
        )

        lines_by_key: dict[tuple[int, int, int], list[tuple[str, float]]] = {}
        for index, text in enumerate(data["text"]):
            text = text.strip()
            if not text:
                continue
            key = (data["block_num"][index], data["par_num"][index], data["line_num"][index])
            confidence = _parse_confidence(data["conf"][index])
            lines_by_key.setdefault(key, []).append((text, confidence))

        lines: list[OcrLine] = []
        for words in lines_by_key.values():
            joined = " ".join(word for word, _ in words)
            confidences = [confidence for _, confidence in words if confidence >= 0]
            confidence = sum(confidences) / len(confidences) / 100 if confidences else None
            lines.append(OcrLine(text=joined, confidence=confidence))

        if not self.config.multi_pass:
            return lines

        nik_lines = self._read_nik_roi(rgb)
        field_lines = self._read_field_rois(rgb)
        lines = nik_lines + field_lines + lines

        return lines

    def _limit_size(self, image: np.ndarray) -> np.ndarray:
        if not self.config.max_width:
            return image
        height, width = image.shape[:2]
        if width <= self.config.max_width:
            return image
        scale = self.config.max_width / width
        return cv2.resize(image, (self.config.max_width, int(height * scale)), interpolation=cv2.INTER_AREA)

    def _read_nik_roi(self, image: np.ndarray) -> list[OcrLine]:
        height, width = image.shape[:2]
        crop = image[int(height * 0.117) : int(height * 0.235), 0 : int(width * 0.703)]
        if crop.size == 0:
            return []

        crop = cv2.resize(crop, None, fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if len(crop.shape) == 3 else crop
        lines: list[OcrLine] = []
        for psm in (8, 13):
            text = self.pytesseract.image_to_string(gray, lang="eng", config=f"--oem 3 --psm {psm}").strip()
            if "NIK" in text.upper() or any(character.isdigit() for character in text):
                lines.append(OcrLine(text=text, confidence=None))

        return lines

    def _read_field_rois(self, image: np.ndarray) -> list[OcrLine]:
        height, width = image.shape[:2]
        rois = [
            image[:, 0 : int(width * 0.72)],
            image[int(height * 0.20) : int(height * 0.84), 0 : int(width * 0.72)],
            image[int(height * 0.18) : int(height * 0.40), 0 : int(width * 0.72)],
        ]
        lines: list[OcrLine] = []
        seen: set[str] = set()

        for roi in rois:
            if roi.size == 0:
                continue
            for scale in self.config.roi_scales:
                resized = cv2.resize(roi, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
                gray = cv2.cvtColor(resized, cv2.COLOR_BGR2GRAY) if len(resized.shape) == 3 else resized
                for psm in self.config.roi_psms:
                    text = self.pytesseract.image_to_string(gray, lang="eng", config=f"--oem 3 --psm {psm}")
                    for raw_line in text.splitlines():
                        cleaned = raw_line.strip()
                        if len(cleaned) < 3:
                            continue
                        key = cleaned.upper()
                        if key in seen:
                            continue
                        seen.add(key)
                        lines.append(OcrLine(text=cleaned, confidence=None))

        return lines


def _parse_confidence(value) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return -1
