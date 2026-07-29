from __future__ import annotations

import argparse
import importlib.util
import os
import shutil
from pathlib import Path
from uuid import uuid4

import uvicorn
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from ktp_ocr_lab.generate_sample import create_sample_image
from ktp_ocr_lab.models import load_config
from ktp_ocr_lab.pipeline import run_pipeline

ROOT = Path(os.environ.get("KTP_OCR_ROOT", "")).resolve() if os.environ.get("KTP_OCR_ROOT") else Path.cwd().resolve()
CONFIG_DIR = ROOT / "configs"
UPLOAD_DIR = ROOT / "uploads"
OUTPUT_DIR = ROOT / "outputs"
STATIC_DIR = ROOT / "web"
SAMPLE_PATH = ROOT / "samples" / "ktp-dummy.jpg"

ALLOWED_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}

UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

app = FastAPI(title="KTP OCR Lab", version="0.1.0")
app.mount("/assets", StaticFiles(directory=STATIC_DIR), name="assets")
app.mount("/outputs", StaticFiles(directory=OUTPUT_DIR), name="outputs")
app.mount("/samples", StaticFiles(directory=ROOT / "samples"), name="samples")
app.mount("/uploads", StaticFiles(directory=UPLOAD_DIR), name="uploads")


@app.get("/")
def index() -> FileResponse:
    return FileResponse(STATIC_DIR / "index.html")


@app.get("/api/health")
def health() -> dict[str, object]:
    return {
        "status": "ok",
        "service": "ktp-ocr-lab",
        "configs": sorted(path.name for path in CONFIG_DIR.glob("*.json")),
        "engines": {
            "tesseract": _tesseract_available(),
            "paddleocr": importlib.util.find_spec("paddleocr") is not None,
            "easyocr": importlib.util.find_spec("easyocr") is not None,
        },
    }


@app.get("/api/configs")
def configs() -> dict[str, list[str]]:
    return {"configs": ["auto-best"] + sorted(path.name for path in CONFIG_DIR.glob("*.json"))}


@app.get("/api/engines")
def engines() -> dict[str, list[dict[str, object]]]:
    return {
        "engines": [
            {
                "value": "mock",
                "label": "Mock",
                "available": True,
                "note": "Hanya untuk sample dummy.",
            },
            {
                "value": "tesseract",
                "label": "Tesseract",
                "available": _tesseract_available(),
                "note": "OCR lokal aktif.",
            },
            {
                "value": "paddleocr",
                "label": "PaddleOCR",
                "available": importlib.util.find_spec("paddleocr") is not None,
                "note": "Install: pip install paddleocr",
            },
            {
                "value": "easyocr",
                "label": "EasyOCR",
                "available": importlib.util.find_spec("easyocr") is not None,
                "note": "Install: pip install easyocr",
            },
        ]
    }


@app.post("/api/sample")
def generate_sample() -> dict[str, str]:
    create_sample_image(SAMPLE_PATH)
    return {"image_url": f"/samples/{SAMPLE_PATH.name}", "image_path": str(SAMPLE_PATH)}


@app.post("/api/ocr")
def ocr(
    image: UploadFile | None = File(default=None),
    image_path: str | None = Form(default=None),
    config_name: str = Form(default="baseline.json"),
    engine: str = Form(default="mock"),
) -> JSONResponse:
    safe_config_name = Path(config_name).name
    if config_name == "auto-best":
        safe_config_name = "auto-best"
    config_path = CONFIG_DIR / safe_config_name
    if safe_config_name != "auto-best" and not config_path.exists():
        raise HTTPException(status_code=400, detail="Config tidak ditemukan.")

    target_path = _resolve_input_image(image, image_path)
    requested_engine = engine

    try:
        if safe_config_name == "auto-best":
            result = _run_auto_best(target_path, engine)
        else:
            config = load_config(config_path)
            config.ocr.engine = _effective_engine(engine, config.ocr.engine, target_path)
            if config.ocr.engine == "tesseract" and config.ocr.language in {"id", "ind"}:
                config.ocr.language = "eng"
            result = run_pipeline(target_path, config, OUTPUT_DIR)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    payload = result.model_dump()
    payload["requested_engine"] = requested_engine
    payload["image_url"] = _public_url(target_path)
    payload["oriented_image_url"] = _public_url(Path(result.metadata["oriented_image"]))
    payload["processed_image_url"] = _public_url(Path(result.metadata["processed_image"]))
    return JSONResponse(payload)


def _resolve_input_image(image: UploadFile | None, image_path: str | None) -> Path:
    if image and image.filename:
        suffix = Path(image.filename).suffix.lower()
        if suffix not in ALLOWED_SUFFIXES:
            raise HTTPException(status_code=400, detail="Format gambar tidak didukung.")

        UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
        target = UPLOAD_DIR / f"{uuid4().hex}{suffix}"
        with target.open("wb") as handle:
            shutil.copyfileobj(image.file, handle)
        return target

    if image_path:
        candidate = Path(image_path)
        if not candidate.is_absolute():
            candidate = ROOT / candidate
        candidate = candidate.resolve()
        if not candidate.exists():
            raise HTTPException(status_code=400, detail="Path gambar tidak ditemukan.")
        if ROOT not in candidate.parents and candidate != ROOT:
            raise HTTPException(status_code=400, detail="Path gambar harus berada di folder lab.")
        return candidate

    raise HTTPException(status_code=400, detail="Upload gambar atau pilih sample dahulu.")


def _effective_engine(requested_engine: str, config_engine: str, image_path: Path) -> str:
    if requested_engine != "mock":
        return requested_engine

    if image_path.resolve() == SAMPLE_PATH.resolve():
        return "mock"

    if config_engine != "mock":
        return config_engine

    return "tesseract"


def _run_auto_best(target_path: Path, engine: str):
    config_names = ["tesseract-fast.json", "tesseract-photo.json"]
    if importlib.util.find_spec("paddleocr") is not None:
        config_names = ["paddleocr-roi-balanced.json", "paddleocr-roi-max.json"] + config_names
    results = []
    for name in config_names:
        config = load_config(CONFIG_DIR / name)
        config.ocr.engine = _effective_engine(engine, config.ocr.engine, target_path)
        if config.ocr.engine == "mock" and target_path.resolve() != SAMPLE_PATH.resolve():
            config.ocr.engine = "tesseract"
        if config.ocr.engine == "tesseract" and config.ocr.language in {"id", "ind"}:
            config.ocr.language = "eng"
        results.append(run_pipeline(target_path, config, OUTPUT_DIR))
    best = max(results, key=_result_score)
    best.metadata["auto_candidates"] = [
        {"config": result.config_name, "score": _result_score(result), "engine": result.engine}
        for result in results
    ]
    return best


def _result_score(result) -> int:
    fields = result.fields
    score = sum(1 for field in fields.values() if field.value)
    if fields["nik"].value and len(fields["nik"].value) == 16:
        score += 8
        birth_code = _birth_code_from_text(fields["tempat_tanggal_lahir"].value or "")
        if birth_code and fields["nik"].value[6:12] == birth_code:
            score += 20
        elif birth_code:
            score -= 30
    for key in ["nama", "tempat_tanggal_lahir", "jenis_kelamin", "alamat"]:
        if fields[key].value:
            score += 2
    return score


def _birth_code_from_text(text: str) -> str | None:
    import re

    match = re.search(r"\b(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})\b", text)
    if not match:
        return None
    day, month, year = match.groups()
    return f"{int(day):02d}{int(month):02d}{int(year[-2:]):02d}"


def _tesseract_available() -> bool:
    return Path("C:/Program Files/Tesseract-OCR/tesseract.exe").exists() or shutil.which("tesseract") is not None


def _public_url(path: Path) -> str | None:
    path = path.resolve()
    for folder, prefix in ((OUTPUT_DIR, "/outputs"), (ROOT / "samples", "/samples"), (UPLOAD_DIR, "/uploads")):
        folder = folder.resolve()
        if path == folder or folder in path.parents:
            return f"{prefix}/{path.relative_to(folder).as_posix()}"
    return None


def main() -> None:
    parser = argparse.ArgumentParser(description="Run KTP OCR Lab web UI.")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()
    uvicorn.run("ktp_ocr_lab.web:app", host=args.host, port=args.port, reload=False)


if __name__ == "__main__":
    main()
