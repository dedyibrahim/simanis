# Operasional High Availability SIMANIS

## Topologi

- Primary: `192.168.0.11`
- Standby: `192.168.0.10`
- Virtual IP (VIP): `192.168.0.12`
- Frontend: `http://192.168.0.12`
- API: `http://192.168.0.12:8000/api`

Keepalived memindahkan VIP ke standby ketika Apache, port API, atau MySQL
primary tidak sehat. Database direplikasi dari primary ke standby. Folder
upload di `public` disalin ke standby oleh timer systemd.

`bothWA` memakai pola active-passive. Server primary menjalankan container
`bothwa` dan `simanis-waha`; server standby menyimpan salinan folder, image
Docker, dan container dalam kondisi stopped agar session WhatsApp tidak aktif
di dua server sekaligus.

Service OCR KTP memakai container `ktp-ocr-lab` dengan kebijakan restart
`unless-stopped`. Hook MASTER juga menjalankan start idempotent untuk memastikan
OCR tersedia pada node pemegang VIP setelah boot atau failover.

## Dashboard

Login sebagai Admin/Super Admin, lalu buka:

`Setting -> Status Sinkronisasi`

Dashboard menampilkan pemegang VIP, koneksi peer, replikasi database, versi
aplikasi, progres sinkronisasi folder public, serta kontrol `bothWA`.

Bagian WhatsApp Gateway pada dashboard dapat menjalankan/menghentikan container
`bothwa` dan `simanis-waha`. Jika session WAHA belum login, QR login akan
muncul di dashboard untuk discan dari aplikasi WhatsApp.

## Pemeriksaan Harian

Jalankan pada primary:

```bash
systemctl status keepalived
systemctl status simanis-public-sync.service
systemctl status simanis-public-sync.timer
cat /var/lib/simanis-ha/file-sync-status.json
ip -4 addr show enp0s31f6
docker ps --filter name=bothwa --filter name=simanis-waha
```

Jalankan pada standby:

```bash
systemctl status keepalived
mysql --defaults-extra-file=/root/.my.cnf -e "SHOW SLAVE STATUS\G"
ip -4 addr show enp0s31f6
docker ps -a --filter name=bothwa --filter name=simanis-waha
```

Status database sehat jika `Slave_IO_Running` dan `Slave_SQL_Running` bernilai
`Yes`. Lag dapat meningkat selama sinkronisasi file besar dan harus turun lagi
setelah aktivitas disk mereda.

Node pemegang VIP wajib menghasilkan `0 0`, sedangkan standby wajib `1 1`:

```bash
mysql --defaults-extra-file=/root/.my.cnf -NBe "SELECT @@read_only, @@super_read_only"
```

Hook pergantian role memakai lock bersama dan memeriksa kepemilikan VIP setelah
MySQL siap. Ini mencegah proses BACKUP yang terlambat saat boot mengunci kembali
database pada node MASTER.

## Saat Primary Mati

Standby mengambil VIP secara otomatis, menghentikan mode replica, dan membuka
database untuk write. Akses pengguna tetap menggunakan `192.168.0.12`.

Konfigurasi memakai `nopreempt`: primary yang hidup kembali tidak langsung
merebut VIP. Ini mencegah dua database menerima write secara bersamaan.

Jika layanan WhatsApp juga harus diaktifkan di standby, jalankan pada server
yang memegang VIP:

```bash
/usr/local/sbin/simanis-bothwa-start
```

Pastikan container `bothwa` dan `simanis-waha` di server lain dalam kondisi
stopped:

```bash
/usr/local/sbin/simanis-bothwa-stop
```

## Failback

Jangan memindahkan VIP kembali secara paksa. Setelah standby pernah menerima
write, database primary lama harus di-seed ulang dari server yang sedang aktif,
lalu dijadikan replica terlebih dahulu. Setelah replikasi kembali sehat dan
folder public sama, barulah peran server dapat dikembalikan pada maintenance
window.

## Log

```bash
journalctl -u keepalived
journalctl -u simanis-public-sync.service
docker logs --tail=100 bothwa
docker logs --tail=100 simanis-waha
tail -f /var/log/apache2/error.log
```
