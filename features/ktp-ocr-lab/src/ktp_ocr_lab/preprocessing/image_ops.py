from __future__ import annotations

import cv2
import numpy as np

from ktp_ocr_lab.models import PreprocessingConfig, QualityReport


def load_image(path: str) -> np.ndarray:
    image = cv2.imread(path)
    if image is None:
        raise FileNotFoundError(f"Image cannot be read: {path}")
    return image


def auto_orient_landscape(image: np.ndarray, enabled: bool = True) -> tuple[np.ndarray, dict[str, str | bool]]:
    height, width = image.shape[:2]
    info: dict[str, str | bool] = {
        "applied": False,
        "reason": "disabled" if not enabled else "already_landscape",
        "rotation": "none",
    }
    if not enabled:
        return image, info

    if height <= width:
        return image, info

    candidates = [
        ("90_clockwise", cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)),
        ("90_counterclockwise", cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)),
    ]
    scored = [(rotation, candidate, _landscape_ktp_score(candidate)) for rotation, candidate in candidates]
    rotation, oriented, score = max(scored, key=lambda item: item[2])
    info = {
        "applied": True,
        "reason": "portrait_to_landscape",
        "rotation": rotation,
        "score": round(score, 4),
    }
    return oriented, info


def _landscape_ktp_score(image: np.ndarray) -> float:
    height, width = image.shape[:2]
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    red_mask = ((hsv[:, :, 0] <= 10) | (hsv[:, :, 0] >= 165)) & (hsv[:, :, 1] > 60) & (hsv[:, :, 2] > 60)
    dark_mask = gray < 95

    right = red_mask[:, int(width * 0.58) :]
    left = red_mask[:, : int(width * 0.42)]
    top = dark_mask[: int(height * 0.28), :]
    bottom = dark_mask[int(height * 0.72) :, :]
    nik_area = dark_mask[int(height * 0.08) : int(height * 0.28), : int(width * 0.62)]
    signature_area = dark_mask[int(height * 0.72) :, int(width * 0.58) :]

    return (
        _ratio(right) * 4.0
        - _ratio(left) * 2.0
        + _ratio(top) * 1.4
        - _ratio(bottom) * 0.7
        + _ratio(nik_area) * 2.2
        + _ratio(signature_area) * 0.6
    )


def _ratio(mask: np.ndarray) -> float:
    if mask.size == 0:
        return 0.0
    return float(mask.mean())


def assess_quality(image: np.ndarray) -> QualityReport:
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
    blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var())

    return QualityReport(
        width=width,
        height=height,
        blur_score=round(blur_score, 2),
        too_small=width < 900 or height < 550,
        likely_blurry=blur_score < 80,
    )


def preprocess(image: np.ndarray, config: PreprocessingConfig) -> np.ndarray:
    result = image.copy()

    if config.resize_scale and config.resize_scale != 1:
        result = cv2.resize(
            result,
            None,
            fx=config.resize_scale,
            fy=config.resize_scale,
            interpolation=cv2.INTER_CUBIC,
        )

    if config.grayscale and len(result.shape) == 3:
        result = cv2.cvtColor(result, cv2.COLOR_BGR2GRAY)

    if config.denoise:
        result = cv2.fastNlMeansDenoising(result, None, 10, 7, 21)

    if config.contrast == "clahe":
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        result = clahe.apply(_ensure_gray(result))

    if config.sharpen:
        kernel = np.array([[0, -1, 0], [-1, 5, -1], [0, -1, 0]])
        result = cv2.filter2D(result, -1, kernel)

    if config.threshold == "adaptive":
        gray = _ensure_gray(result)
        result = cv2.adaptiveThreshold(
            gray,
            255,
            cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY,
            31,
            9,
        )
    elif config.threshold == "otsu":
        gray = _ensure_gray(result)
        _, result = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    if config.deskew:
        result = deskew(result)

    return result


def deskew(image: np.ndarray) -> np.ndarray:
    gray = _ensure_gray(image)
    coords = np.column_stack(np.where(gray < 245))
    if len(coords) < 20:
        return image

    angle = cv2.minAreaRect(coords)[-1]
    if angle < -45:
        angle = -(90 + angle)
    else:
        angle = -angle

    if abs(angle) < 0.5 or abs(angle) > 15:
        return image

    height, width = image.shape[:2]
    center = (width // 2, height // 2)
    matrix = cv2.getRotationMatrix2D(center, angle, 1.0)
    return cv2.warpAffine(
        image,
        matrix,
        (width, height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_REPLICATE,
    )


def _ensure_gray(image: np.ndarray) -> np.ndarray:
    if len(image.shape) == 2:
        return image
    return cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
