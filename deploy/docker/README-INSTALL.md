# SIMANIS Docker VPS

Folder ini adalah paket deploy single VPS. Production server lama tidak disentuh.

## Kebutuhan VPS

- Ubuntu 22.04/24.04 disarankan
- Docker Engine
- Docker Compose plugin
- RAM minimal 4 GB, disarankan 8 GB karena OCR Paddle cukup berat
- Disk disarankan 80 GB+

## Install Cepat

```bash
cd /opt
git clone <repo-atau-copy-folder> simanis-source
cd /opt/simanis-source/deploy/docker
bash install.sh
```

`install.sh` akan membuat `.env` jika belum ada dan mengisi secret acak untuk nilai yang masih kosong atau `change-me...`. Kalau ingin mengatur domain/port dulu, jalankan:

```bash
cp .env.example .env
nano .env
bash install.sh
```

Nilai yang sudah diisi manual tidak dioverwrite.

## URL

Set nilai ini di `.env`:

```env
SIMANIS_HTTP_PORT=80
APP_URL=https://domain-anda.com
SIMANIS_APP_URL=https://domain-anda.com
NUXT_PUBLIC_API_BASE=/api
NUXT_PUBLIC_ASSET_BASE=
SANCTUM_STATEFUL_DOMAINS=domain-anda.com
SESSION_DOMAIN=.domain-anda.com
```

Untuk SSL, pasang reverse proxy di host atau gunakan Cloudflare/Nginx Proxy Manager. Container frontend membuka port sesuai `SIMANIS_HTTP_PORT`.

## Service

- `frontend`: Nuxt static + Nginx, entrypoint HTTP utama
- `api`: Laravel API dengan Apache
- `worker`: Laravel queue worker
- `scheduler`: Laravel scheduler loop per menit
- `bothwa`: bot WhatsApp
- `waha`: WhatsApp gateway
- `ktp-ocr`: OCR KTP FastAPI/PaddleOCR
- `db`: MariaDB
- `redis`: Redis

## Command Harian

```bash
bash health.sh
bash backup.sh
bash update.sh
docker compose logs -f api
docker compose logs -f bothwa
docker compose logs -f ktp-ocr
```

## Backup

```bash
cd /opt/simanis-source/deploy/docker
bash backup.sh
```

Output tersimpan di `backups/YYYYMMDD-HHMMSS.tar.gz`.

Backup berisi:

- dump database
- `.env`
- storage Laravel
- folder public upload
- session WAHA

## Restore

```bash
cd /opt/simanis-source/deploy/docker
bash restore.sh backups/YYYYMMDD-HHMMSS.tar.gz
```

Pastikan `.env` target sudah sesuai sebelum restore.

## Update

```bash
cd /opt/simanis-source/deploy/docker
git pull
bash update.sh
```

Data aman karena disimpan di Docker volumes, bukan di image.

## Catatan Distribusi Jualan

Untuk produk komersial, file ini bisa tetap sama tetapi bagian `build:` pada `docker-compose.yml` dapat diganti menjadi image registry, misalnya:

```yaml
image: registry.example.com/simanis/api:1.0.0
```

Dengan begitu customer hanya menerima compose, `.env`, dan script installer, tanpa source code aplikasi.
