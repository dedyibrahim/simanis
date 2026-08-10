import os
import shutil
import subprocess
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


def _office_binary() -> str:
    for candidate in ("soffice", "libreoffice"):
        resolved = shutil.which(candidate)
        if resolved:
            return resolved
    raise HTTPException(status_code=503, detail="LibreOffice belum tersedia di container garis-akta.")


def _convert_word_to_pdf(word_path: Path, output_dir: Path) -> Path:
    command = [
        _office_binary(),
        "--headless",
        "--nologo",
        "--nofirststartwizard",
        "--convert-to",
        "pdf",
        "--outdir",
        str(output_dir),
        str(word_path),
    ]
    try:
        subprocess.run(command, check=True, capture_output=True, text=True, timeout=240)
    except subprocess.TimeoutExpired as exc:
        raise HTTPException(status_code=504, detail="Konversi DOC/DOCX ke PDF melewati batas waktu.") from exc
    except subprocess.CalledProcessError as exc:
        detail = (exc.stderr or exc.stdout or str(exc)).strip()
        raise HTTPException(status_code=422, detail=f"Konversi DOC/DOCX ke PDF gagal: {detail[:500]}") from exc

    expected = output_dir / f"{word_path.stem}.pdf"
    if expected.exists() and expected.stat().st_size > 0:
        return expected

    pdfs = sorted(output_dir.glob("*.pdf"), key=lambda item: item.stat().st_mtime, reverse=True)
    if pdfs and pdfs[0].stat().st_size > 0:
        return pdfs[0]
    raise HTTPException(status_code=422, detail="Konversi DOC/DOCX tidak menghasilkan PDF.")


@app.get("/health")
def health():
    return {
        "status": True,
        "model": MODEL_PATH.name,
        "model_exists": MODEL_PATH.exists(),
        "office": bool(shutil.which("soffice") or shutil.which("libreoffice")),
    }


@app.post("/process")
async def process_word_document(
    document: UploadFile = File(...),
    outside_shift: float = Form(4.0),
    zoom: float = Form(2.0),
):
    filename = document.filename or "akta.docx"
    suffix = Path(filename).suffix.lower()
    if suffix not in (".doc", ".docx"):
        raise HTTPException(status_code=422, detail="File harus berformat .doc atau .docx.")
    if not MODEL_PATH.exists():
        raise HTTPException(status_code=503, detail=f"Model garis tidak ditemukan: {MODEL_PATH.name}")

    temp_dir = Path(tempfile.mkdtemp(prefix="simanis_garis_akta_"))
    try:
        stem = _safe_stem(filename)
        input_word = temp_dir / f"{stem}{suffix}"

        total = 0
        with input_word.open("wb") as handle:
            while True:
                chunk = await document.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if total > MAX_UPLOAD_BYTES:
                    raise HTTPException(status_code=413, detail="Ukuran DOC/DOCX terlalu besar.")
                handle.write(chunk)

        converted_pdf = _convert_word_to_pdf(input_word, temp_dir)
        output_pdf = temp_dir / f"{stem}__garis_otomatis.pdf"
        final_output, segment_count = scan_lines.apply_scan_model_with_options(
            input_pdf=str(converted_pdf),
            output_pdf=str(output_pdf),
            model_path=str(MODEL_PATH),
            zoom=max(1.0, min(float(zoom), 4.0)),
            force_scan=False,
            no_merge=False,
            outside_shift=max(0.0, min(float(outside_shift), 30.0)),
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
