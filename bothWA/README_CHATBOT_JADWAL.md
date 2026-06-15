# Chatbot WA Jadwal (Single WAHA Session)

Bot ini bisa:
- menampilkan jadwal user lewat WA
- membuat jadwal baru lewat WA dan otomatis tersimpan ke sistem backend

Bot **tidak lagi** login WA sendiri (`whatsapp-web.js`).
Semua inbound/outbound pakai **WAHA** (single session, single scan QR).

Default API bot: `http://localhost:8020`
Endpoint webhook bot: `POST /webhook/waha`

## 1) Jalankan stack lokal

```powershell
docker compose up -d --build
```

Service lokal yang aktif:
- Bot `bothwa` di `http://localhost:8020`
- WAHA di `http://localhost:8010`

File env Docker lokal:
- `bothwa.env`

Default lokal saat ini sudah disamakan dengan server untuk:
- `BOT_API_KEY`
- `WAHA_WEBHOOK_SECRET`
- auto-sync webhook WAHA

WAHA default di `http://localhost:8010`.
Scan QR dari dashboard WAHA sampai session `default` status ready.

## 2) Jalankan backend Laravel

Pastikan backend aktif di `http://127.0.0.1:8000` (atau URL lain sesuai env).

## 3) Environment bot

Docker Compose lokal memakai `bothwa.env`.

Nilai penting yang sekarang dipakai lokal:

- `LARAVEL_BASE_URL=http://host.docker.internal:8000`
- `WAHA_BASE_URL=http://waha:3000`
- `WAHA_WEBHOOK_URL=http://bothwa:8020/webhook/waha`
- `BOT_API_KEY=S1r4t0ny4n1`
- `WAHA_WEBHOOK_SECRET=S1r4t0ny4n1`

## 4) Sinkron webhook WAHA

Jika `WAHA_WEBHOOK_URL` di-set, bot akan otomatis memastikan webhook session `default` mengarah ke URL tersebut saat startup/deploy. Di Docker lokal, URL internal yang dipakai adalah:

```text
http://bothwa:8020/webhook/waha
```

Event minimal:
- `message`

Jika pakai `WAHA_WEBHOOK_SECRET`, bot juga akan otomatis memasang header `X-Webhook-Secret`.

Jika tidak ingin auto-config, set:

```powershell
$env:WAHA_WEBHOOK_AUTO_CONFIGURE="false"
```

Fallback manual via WAHA tetap bisa dipakai bila diperlukan.

## 5) Cara pakai dari WhatsApp

### A. Buat jadwal dengan form

Kirim:

```text
buat jadwal
```

Bot mengirim satu form teks. Isi seluruh field wajib, lalu kirim kembali form tersebut:

```text
Judul: Meeting Akta
Tanggal: 10-06-2026
Mulai: 09:00
Selesai: 09:30
Lokasi: Kantor
Keterangan: Cek berkas
Asisten: 1,3
```

Waktu mulai dan selesai fleksibel. Interval 30 menit seperti `09:00-09:30`
didukung dan tidak dibulatkan menjadi satu jam.

Jika rentang bertabrakan dengan jadwal user/PIC yang dipilih, bot menampilkan
jadwal yang bentrok:

- kirim `LANJUT` untuk tetap menyimpan
- kirim `BATAL` untuk membatalkan

### B. Buat jadwal cepat (kompatibilitas)

Format:

```text
buat jadwal | Judul | Waktu | Lokasi (opsional) | Keterangan (opsional) | Asisten: noWA1,noWA2 (opsional)
```

Contoh:

```text
buat jadwal | Tanda tangan akta | besok jam 10 pagi | Kantor
buat jadwal | Meeting client A | 14-05-2026 jam 13:30
buat jadwal | Review berkas | 14-05-2026 jam 09:00 | Kantor | Dokumen prioritas | asisten: 081234567890, 6281234567890
```

Format cepat tetap memakai durasi default satu jam. Gunakan form untuk
menentukan rentang waktu sendiri.

### C. Cek jadwal

Contoh:
- `cek jadwal` untuk agenda 7 hari ke depan
- `jadwal harian` untuk sisa agenda hari ini
- `jadwal mingguan` untuk agenda 7 hari ke depan
- `jadwal bulanan` untuk agenda 1 bulan ke depan
- `cek jadwal 10-06-2026 sampai 20-06-2026`
- `jadwal 10/06/2026 s/d 20/06/2026`

Agenda yang sudah selesai tidak ditampilkan. Rentang tanggal manual maksimal
366 hari dan bagian rentang yang sudah lewat otomatis diabaikan.

### D. Bantuan

Kirim `help` atau `bantuan`.

Webhook inbound dideduplikasi berdasarkan ID pesan selama 10 menit agar retry
dari WAHA tidak membuat jadwal dua kali.

## Cek Data Client via WA

Format:

```text
cek client <kata_kunci>
cek client perorangan <kata_kunci>
cek client badan hukum <kata_kunci>
hapus jadwal <id_jadwal>
laporan reportorium
laporan reportorium per modul
laporan reportorium bulan ini
laporan reportorium 05/2026
laporan reportorium per modul 05/2026 akta, ppat, waarmerking, legalisasi
```

Contoh:

```text
cek client andi
cek client perorangan budi
cek client badan hukum pt
```

## Persetujuan Request File via WA (Admin)

Saat ada user request download file, admin menerima notifikasi WhatsApp berisi `ID Request`.

Balasan yang didukung:

```text
ya <id_request>
tidak <id_request>
```

Contoh:

```text
ya 125
tidak 126 alasan belum lengkap
```
