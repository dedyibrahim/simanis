import os
import shutil
import tempfile
from pathlib import Path

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from starlette.background import BackgroundTask

import scan_line_apply as scan_lines


APP_ROOT = Path(os.getenv("GARIS_AKTA_ROOT", "/app")).resolve()
MODEL_PATH = Path(os.getenv("GARIS_AKTA_MODEL", APP_ROOT / "scan_line_model_datatrain_v2.json")).resolve()
MAX_UPLOAD_BYTES = int(os.getenv("GARIS_AKTA_MAX_UPLOAD_BYTES", str(25 * 1024 * 1024)))

app = FastAPI(title="SIMANIS Garis Otomatis Akta", version="1.0.0")


def _safe_stem(filename: str) -> str:
    stem = Path(filename or "akta").stem.strip() or "akta"
    allowed = []
    for char in stem:
        allowed.append(char if char.isalnum() or char in ("-", "_", " ") else "_")
    return "".join(allowed).strip()[:80] or "akta"


def _parse_hex_color(value: str) -> tuple[float, float, float]:
    normalized = (value or "#111827").strip().lower()
    if len(normalized) != 7 or not normalized.startswith("#"):
        raise HTTPException(status_code=422, detail="Warna garis harus menggunakan format hex #RRGGBB.")
    try:
        channels = [int(normalized[index:index + 2], 16) / 255.0 for index in (1, 3, 5)]
    except ValueError as exc:
        raise HTTPException(status_code=422, detail="Warna garis tidak valid.") from exc
    return tuple(channels)


@app.get("/health")
def health():
    return {
        "status": True,
        "model": MODEL_PATH.name,
        "model_exists": MODEL_PATH.exists(),
        "input_format": "pdf",
    }


@app.post("/process")
async def process_pdf_document(
    document: UploadFile = File(...),
    outside_shift: float = Form(4.0),
    zoom: float = Form(2.0),
    line_color: str = Form("#111827"),
):
    filename = document.filename or "akta.pdf"
    suffix = Path(filename).suffix.lower()
    if suffix != ".pdf":
        raise HTTPException(status_code=422, detail="File harus berformat PDF.")
    if not MODEL_PATH.exists():
        raise HTTPException(status_code=503, detail=f"Model garis tidak ditemukan: {MODEL_PATH.name}")

    temp_dir = Path(tempfile.mkdtemp(prefix="simanis_garis_akta_"))
    try:
        stem = _safe_stem(filename)
        input_pdf = temp_dir / f"{stem}.pdf"

        total = 0
        with input_pdf.open("wb") as handle:
            while True:
                chunk = await document.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > MAX_UPLOAD_BYTES:
                    raise HTTPException(status_code=413, detail="Ukuran PDF terlalu besar.")
                handle.write(chunk)

        if total == 0 or input_pdf.read_bytes()[:5] != b"%PDF-":
            raise HTTPException(status_code=422, detail="Isi file bukan dokumen PDF yang valid.")

        output_pdf = temp_dir / f"{stem}__garis_otomatis.pdf"
        final_output, segment_count = scan_lines.apply_scan_model_with_options(
            input_pdf=str(input_pdf),
            output_pdf=str(output_pdf),
            model_path=str(MODEL_PATH),
            zoom=max(1.0, min(float(zoom), 4.0)),
            force_scan=False,
            no_merge=False,
            outside_shift=max(0.0, min(float(outside_shift), 30.0)),
            line_color=_parse_hex_color(line_color),
        )

        return FileResponse(
            final_output,
            media_type="application/pdf",
            filename=Path(final_output).name,
            headers={"X-Garis-Segments": str(segment_count)},
            background=BackgroundTask(shutil.rmtree, temp_dir, ignore_errors=True),
        )
    except HTTPException:
        shutil.rmtree(temp_dir, ignore_errors=True)
        raise
    except Exception as exc:
        shutil.rmtree(temp_dir, ignore_errors=True)
        raise HTTPException(status_code=500, detail=f"Gagal memproses garis otomatis: {exc}") from exc
    finally:
        await document.close()
