# KTP OCR Lab

Lab eksperimen untuk ekstraksi data KTP sebelum diintegrasikan ke backend dan frontend Simanis.

Folder ini sengaja dibuat terpisah dari aplikasi utama agar proses tuning OCR bisa dilakukan aman, terukur, dan mudah dibandingkan antar eksperimen.

## Tujuan

- Menilai kualitas foto atau scan KTP sebelum OCR.
- Mencoba banyak kombinasi preprocessing gambar.
- Membandingkan OCR engine seperti PaddleOCR, EasyOCR, dan Tesseract.
- Mengekstrak field KTP ke JSON terstruktur.
- Menyimpan output eksperimen untuk benchmark dan audit.

## Struktur

```text
features/ktp-ocr-lab/
  configs/              Preset pipeline eksperimen.
  datasets/             Ground truth anonymized untuk benchmark.
  samples/              Contoh gambar KTP dummy/anonymized.
  outputs/              Hasil OCR dan parsing.
  experiments/          Catatan eksperimen manual.
  benchmarks/           Hasil benchmark agregat.
  docs/                 Dokumentasi desain dan integrasi.
  src/ktp_ocr_lab/      Kode lab OCR.
```

## Instalasi

Gunakan Python 3.10+.

```bash
cd features/ktp-ocr-lab
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

Dependency OCR engine bersifat opsional:

```bash
pip install paddleocr
pip install easyocr
pip install pytesseract
```

Untuk Tesseract, binary Tesseract OCR juga harus terpasang di sistem.

## Cara Pakai

Baseline tanpa dependency OCR berat:

```bash
python -m ktp_ocr_lab.cli --image samples/ktp-dummy.jpg --config configs/baseline.json --engine mock
```

Dengan PaddleOCR:

```bash
python -m ktp_ocr_lab.cli --image samples/ktp-dummy.jpg --config configs/paddleocr-default.json --engine paddleocr
```

Output JSON akan ditulis ke folder `outputs/`.

## Data Sensitif

Jangan commit foto KTP asli. Gunakan data dummy, synthetic, atau anonymized. Jika memakai data real untuk uji internal, simpan di luar repository dan mask NIK/nama/alamat sebelum dibagikan.

