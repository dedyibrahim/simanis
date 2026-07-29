# Google Calendar Sync

SIMANIS dapat mendorong data dari tabel `events` ke satu Google Calendar pusat
memakai service account. Server kantor tidak perlu dapat diakses dari internet;
yang dibutuhkan hanya koneksi keluar dari server ke Google API.

## Google Setup

1. Aktifkan Google Calendar API pada Google Cloud project.
2. Buat service account.
3. Buat key JSON untuk service account.
4. Buat Google Calendar pusat, misalnya `Jadwal SIMANIS`.
5. Share calendar tersebut ke email service account dengan permission
   `Make changes to events`.
6. Ambil `Calendar ID` dari bagian `Integrate calendar`.

## File Credential

Simpan JSON credential di:

```bash
/var/www/apinotaris/storage/app/google-calendar/service-account-credentials.json
```

File ini tidak boleh masuk Git.

## Environment

Tambahkan ke `.env` backend:

```env
GOOGLE_CALENDAR_SYNC_ENABLED=true
GOOGLE_CALENDAR_ID=isi_calendar_id_google
GOOGLE_CALENDAR_CREDENTIALS=/var/www/apinotaris/storage/app/google-calendar/service-account-credentials.json
GOOGLE_CALENDAR_TIMEZONE=Asia/Jakarta
GOOGLE_CALENDAR_SYNC_ONLY_ON_VIP=true
```

Untuk lokal, path credential default adalah:

```text
storage/app/google-calendar/service-account-credentials.json
```

## Command

Dry-run tanpa mengirim ke Google:

```bash
php artisan events:sync-google-calendar --dry-run
```

Sinkron semua event pada rentang default `-30 days` sampai `+1 year`:

```bash
php artisan events:sync-google-calendar --force
```

Sinkron satu event:

```bash
php artisan events:sync-google-calendar --event-id=123 --force
```

Jika `GOOGLE_CALENDAR_SYNC_ENABLED=true`, scheduler Laravel akan menjalankan
sync setiap 5 menit. Pada setup HA, `GOOGLE_CALENDAR_SYNC_ONLY_ON_VIP=true`
membuat sync hanya berjalan di server yang sedang memegang VIP.
