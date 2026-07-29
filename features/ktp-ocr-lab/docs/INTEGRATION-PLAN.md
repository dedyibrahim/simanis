# Rencana Integrasi OCR KTP

Dokumen ini adalah pagar desain sebelum OCR KTP masuk ke backend dan frontend Simanis.

## Fase 1: Lab

- Kumpulkan sample dummy/anonymized.
- Jalankan preset baseline, PaddleOCR, EasyOCR, dan Tesseract.
- Simpan raw OCR, parsed JSON, processed image, dan catatan error.
- Pilih engine berdasarkan akurasi field, bukan sekadar raw OCR terlihat bagus.

## Fase 2: Service Boundary

Rekomendasi awal: expose lab sebagai service Python kecil atau worker queue yang menghasilkan JSON.

Backend Laravel cukup mengirim file dan menerima payload seperti:

```json
{
  "nik": { "value": "3171010101900001", "confidence": 0.95 },
  "nama": { "value": "BUDI SANTOSO", "confidence": 0.9 }
}
```

## Fase 3: Backend

- Tambah endpoint upload OCR KTP.
- Validasi file type, size, dan auth.
- Jangan simpan KTP asli permanen kecuali memang diperlukan secara legal/operasional.
- Simpan extracted field, confidence, raw text terbatas, dan audit metadata.

## Fase 4: Frontend

- Upload/camera capture.
- Preview crop KTP.
- Status quality check.
- Form hasil OCR yang bisa diedit manual.
- Tombol apply ke data client hanya setelah user review.

## Kriteria Siap Integrasi

- NIK benar minimal 95% pada sample valid.
- Nama dan tempat/tanggal lahir benar minimal 90%.
- Field penting punya confidence dan warning.
- Ada fallback manual ketika kualitas gambar buruk.
- Tidak ada data KTP real yang ikut commit.

