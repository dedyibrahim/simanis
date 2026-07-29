from __future__ import annotations

import argparse
from pathlib import Path

import cv2
import numpy as np


def create_sample_image(output: Path) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)

    image = np.full((620, 980, 3), (235, 226, 202), dtype=np.uint8)
    cv2.rectangle(image, (20, 20), (960, 600), (80, 170, 190), 3)
    cv2.rectangle(image, (700, 155), (895, 405), (210, 210, 210), -1)
    cv2.putText(image, "FOTO", (750, 290), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (100, 100, 100), 2)

    lines = [
        ("PROVINSI DKI JAKARTA", 300, 65, 1.0, 2),
        ("NIK 3171010101900001", 70, 130, 1.1, 3),
        ("Nama BUDI SANTOSO", 70, 190, 0.85, 2),
        ("Tempat/Tgl Lahir JAKARTA, 01-01-1990", 70, 230, 0.78, 2),
        ("Jenis Kelamin LAKI-LAKI Gol. Darah O", 70, 270, 0.78, 2),
        ("Alamat JL MERDEKA NO 1", 70, 310, 0.78, 2),
        ("RT/RW 001/002", 70, 350, 0.78, 2),
        ("Kel/Desa GAMBIR", 70, 390, 0.78, 2),
        ("Kecamatan GAMBIR", 70, 430, 0.78, 2),
        ("Agama ISLAM", 70, 470, 0.78, 2),
        ("Status Perkawinan BELUM KAWIN", 70, 510, 0.78, 2),
        ("Pekerjaan KARYAWAN SWASTA", 70, 550, 0.78, 2),
    ]

    for text, x, y, scale, thickness in lines:
        cv2.putText(image, text, (x, y), cv2.FONT_HERSHEY_SIMPLEX, scale, (35, 55, 65), thickness)

    cv2.imwrite(str(output), image)


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate a synthetic KTP-like sample.")
    parser.add_argument("--output", default="samples/ktp-dummy.jpg")
    args = parser.parse_args()

    output = Path(args.output)
    create_sample_image(output)
    print(f"Wrote {output}")


if __name__ == "__main__":
    main()
