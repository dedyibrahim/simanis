# KTP Reader Model

OCR engine tetap dibutuhkan untuk membaca teks dari gambar. Model di layer ini bertugas memilih kandidat field yang benar dari banyak hasil OCR, sehingga tidak bergantung penuh pada regex.

## Data Label

Buat file JSONL:

```json
{"image_path":"C:/path/ktp.jpg","fields":{"nik":"3174082904930001","nama":"ADITYA DWIYANDI PUTRA","tempat_tanggal_lahir":"BANDUNG, 29-04-1993"}}
```

Gunakan minimal 50-100 KTP anonymized/dummy untuk awal. Lebih baik 300+ contoh untuk variasi kamera, provinsi, pencahayaan, dan blur.

## Build Dataset Kandidat

```bash
python -m ktp_ocr_lab.modeling.build_dataset --ground-truth datasets/ktp-ground-truth.jsonl --config configs/tesseract-fast.json --output datasets/reader-candidates.jsonl
```

## Train Model

```bash
python -m ktp_ocr_lab.modeling.train_reader --dataset datasets/reader-candidates.jsonl --output models/ktp-reader.pkl
```

## Arah Berikutnya

- Tambah PaddleOCR untuk OCR dasar yang lebih kuat.
- Tambah annotation bounding box field jika ingin model ROI/layout.
- Setelah dataset cukup, naik ke model layout-aware seperti token classifier.

