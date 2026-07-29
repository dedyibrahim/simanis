from __future__ import annotations

import numpy as np

from ktp_ocr_lab.models import OcrLine
from ktp_ocr_lab.ocr_engines.base import OcrEngine


class MockOcrEngine(OcrEngine):
    """Deterministic engine for parser development without heavy OCR deps."""

    def read(self, image: np.ndarray) -> list[OcrLine]:
        return [
            OcrLine(text="PROVINSI DKI JAKARTA", confidence=1.0),
            OcrLine(text="NIK 3171010101900001", confidence=1.0),
            OcrLine(text="Nama BUDI SANTOSO", confidence=1.0),
            OcrLine(text="Tempat/Tgl Lahir JAKARTA, 01-01-1990", confidence=1.0),
            OcrLine(text="Jenis Kelamin LAKI-LAKI Gol. Darah O", confidence=1.0),
            OcrLine(text="Alamat JL MERDEKA NO 1", confidence=1.0),
            OcrLine(text="RT/RW 001/002", confidence=1.0),
            OcrLine(text="Kel/Desa GAMBIR", confidence=1.0),
            OcrLine(text="Kecamatan GAMBIR", confidence=1.0),
            OcrLine(text="Agama ISLAM", confidence=1.0),
            OcrLine(text="Status Perkawinan BELUM KAWIN", confidence=1.0),
            OcrLine(text="Pekerjaan KARYAWAN SWASTA", confidence=1.0),
            OcrLine(text="Kewarganegaraan WNI", confidence=1.0),
            OcrLine(text="Berlaku Hingga SEUMUR HIDUP", confidence=1.0),
        ]

