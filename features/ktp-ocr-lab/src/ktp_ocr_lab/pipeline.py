from __future__ import annotations

from pathlib import Path
from time import perf_counter

import cv2

from ktp_ocr_lab.models import FieldResult, KtpExtractionResult, OcrLine, PipelineConfig, RoiFieldConfig
from ktp_ocr_lab.ocr_engines.base import create_engine
from ktp_ocr_lab.parser import parse_ktp_fields, parse_roi_value, raw_text
from ktp_ocr_lab.preprocessing.image_ops import assess_quality, auto_orient_landscape, load_image, preprocess


def run_pipeline(image_path: Path, config: PipelineConfig, output_dir: Path) -> KtpExtractionResult:
    started = perf_counter()
    image = load_image(str(image_path))
    image, orientation = auto_orient_landscape(image, config.preprocessing.auto_orient)
    quality = assess_quality(image)
    processed = preprocess(image, config.preprocessing)

    output_dir.mkdir(parents=True, exist_ok=True)
    oriented_path = output_dir / f"{image_path.stem}.{config.name}.oriented.jpg"
    cv2.imwrite(str(oriented_path), image)
    processed_path = output_dir / f"{image_path.stem}.{config.name}.processed.jpg"
    cv2.imwrite(str(processed_path), processed)

    engine = create_engine(config.ocr)
    lines = engine.read(processed)
    fields = parse_ktp_fields(lines, config.parser)
    roi_results, roi_lines = _run_roi_pass(image, engine, config, output_dir, image_path.stem)
    _merge_roi_fields(fields, roi_results)

    return KtpExtractionResult(
        image_path=str(image_path),
        config_name=config.name,
        engine=config.ocr.engine,
        quality=quality,
        fields=fields,
        raw_text=raw_text(lines),
        lines=lines,
        metadata={
            "orientation": orientation,
            "oriented_image": str(oriented_path),
            "processed_image": str(processed_path),
            "roi_fields": {key: value.model_dump() for key, value in roi_results.items()},
            "roi_text": {key: raw_text(value) for key, value in roi_lines.items()},
            "duration_ms": round((perf_counter() - started) * 1000, 2),
        },
    )


def _run_roi_pass(
    image,
    engine,
    config: PipelineConfig,
    output_dir: Path,
    image_stem: str,
) -> tuple[dict[str, FieldResult], dict[str, list[OcrLine]]]:
    results: dict[str, FieldResult] = {}
    line_map: dict[str, list[OcrLine]] = {}
    if not config.roi_fields:
        return results, line_map

    roi_dir = output_dir / "roi"
    roi_dir.mkdir(parents=True, exist_ok=True)

    for roi in config.roi_fields:
        crop = _crop_roi(image, roi)
        if crop.size == 0:
            continue

        crop = _prepare_roi(crop, roi)
        roi_path = roi_dir / f"{image_stem}.{config.name}.{roi.key}.jpg"
        cv2.imwrite(str(roi_path), crop)

        lines = engine.read(crop)
        line_map[roi.key] = lines
        parsed = parse_roi_value(roi.key, lines, config.parser)
        if parsed.value:
            parsed.source = parsed.source or raw_text(lines)
            parsed.warnings.append(f"ROI: {roi.key}")
            results[roi.key] = parsed

    return results, line_map


def _crop_roi(image, roi: RoiFieldConfig):
    height, width = image.shape[:2]
    x1 = max(0, min(width - 1, int(width * roi.x)))
    y1 = max(0, min(height - 1, int(height * roi.y)))
    x2 = max(x1 + 1, min(width, int(width * (roi.x + roi.w))))
    y2 = max(y1 + 1, min(height, int(height * (roi.y + roi.h))))
    return image[y1:y2, x1:x2]


def _prepare_roi(crop, roi: RoiFieldConfig):
    result = crop.copy()
    if roi.upscale and roi.upscale != 1:
        result = cv2.resize(result, None, fx=roi.upscale, fy=roi.upscale, interpolation=cv2.INTER_CUBIC)
    result = _pad_wide_roi(result)
    if roi.threshold == "otsu":
        gray = cv2.cvtColor(result, cv2.COLOR_BGR2GRAY) if len(result.shape) == 3 else result
        _, result = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    elif roi.threshold == "adaptive":
        gray = cv2.cvtColor(result, cv2.COLOR_BGR2GRAY) if len(result.shape) == 3 else result
        result = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 9)
    return result


def _pad_wide_roi(image):
    height, width = image.shape[:2]
    if height <= 0 or width / height <= 3.2:
        return image

    target_height = int(width / 3.2)
    pad_total = max(0, target_height - height)
    pad_top = pad_total // 2
    pad_bottom = pad_total - pad_top
    color = 255 if len(image.shape) == 2 else [255, 255, 255]
    return cv2.copyMakeBorder(image, pad_top, pad_bottom, 0, 0, cv2.BORDER_CONSTANT, value=color)


def _merge_roi_fields(fields: dict[str, FieldResult], roi_results: dict[str, FieldResult]) -> None:
    for key, roi_result in roi_results.items():
        current = fields.get(key)
        if not current or not current.value:
            if _roi_value_is_clean(key, roi_result.value):
                fields[key] = roi_result
            continue
        if key == "nik" and len(str(current.value or "")) != 16 and len(str(roi_result.value or "")) == 16:
            fields[key] = roi_result
            continue
        if _roi_value_is_clean(key, roi_result.value) and (roi_result.confidence or 0) > (current.confidence or 0) + 0.08:
            fields[key] = roi_result


def _roi_value_is_clean(key: str, value: str | None) -> bool:
    upper = str(value or "").upper()
    if not upper:
        return False
    if key in {"alamat", "kecamatan", "kel_desa"}:
        noisy_tokens = ["RT/RW", "KEL/DESA", "KECAMATAN", "AGAMA", "PERKAWINAN", "PEKERJAAN", "BERLAKU"]
        return not any(token in upper for token in noisy_tokens)
    if key == "pekerjaan":
        return any(token in upper for token in ["KARYAWAN", "AKUNTAN", "PELAJAR", "MAHASISWA", "SWASTA"])
    return True
