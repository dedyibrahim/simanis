#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker belum terinstall. Install Docker Engine dulu."
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose plugin belum tersedia. Install docker compose dulu."
  exit 1
fi

random_hex() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "${1:-24}"
  else
    tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${2:-48}"
  fi
}

random_key() {
  if command -v openssl >/dev/null 2>&1; then
    printf 'base64:%s' "$(openssl rand -base64 32)"
  else
    printf 'base64:%s' "$(random_hex 32 64)"
  fi
}

if [ ! -f .env ]; then
  cp .env.example .env
  echo ".env dibuat dari .env.example."
else
  echo ".env sudah ada, nilai manual tidak dioverwrite."
fi

set_env_if_placeholder() {
  key="$1"
  value="$2"
  current="$(grep -E "^${key}=" .env | tail -n 1 | cut -d= -f2- || true)"
  if [ -z "$current" ] || printf '%s' "$current" | grep -q '^change-me'; then
    sed -i "s#^${key}=.*#${key}=${value}#" .env
  fi
}

app_key="$(random_key)"
mysql_root="$(random_hex 24 48)"
mysql_pass="$(random_hex 24 48)"
internal_key="$(random_hex 24 48)"
waha_key="$(random_hex 24 48)"
webhook_secret="$(random_hex 24 48)"
dashboard_pass="$(random_hex 16 32)"

set_env_if_placeholder APP_KEY "$app_key"
set_env_if_placeholder MYSQL_ROOT_PASSWORD "$mysql_root"
set_env_if_placeholder MYSQL_PASSWORD "$mysql_pass"
set_env_if_placeholder DB_PASSWORD "$(grep '^MYSQL_PASSWORD=' .env | cut -d= -f2-)"
set_env_if_placeholder INTERNAL_API_KEY "$internal_key"
set_env_if_placeholder BOT_API_KEY "$(grep '^INTERNAL_API_KEY=' .env | cut -d= -f2-)"
set_env_if_placeholder LARAVEL_API_KEY "$(grep '^INTERNAL_API_KEY=' .env | cut -d= -f2-)"
set_env_if_placeholder WAHA_API_KEY "$waha_key"
set_env_if_placeholder WAHA_WEBHOOK_SECRET "$webhook_secret"
set_env_if_placeholder WAHA_DASHBOARD_PASSWORD "$dashboard_pass"

mkdir -p backups

docker compose build
docker compose up -d db redis
echo "Menunggu database siap..."
for _ in $(seq 1 60); do
  if docker compose exec -T db mariadb-admin ping -h 127.0.0.1 -uroot -p"$(grep '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2-)" --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker compose up -d
docker compose exec -T api php artisan migrate --force
docker compose exec -T api php artisan storage:link || true
docker compose exec -T api php artisan optimize:clear
docker compose exec -T api php artisan config:cache
docker compose exec -T api php artisan route:cache

echo
echo "SIMANIS Docker aktif."
echo "Akses aplikasi: $(grep '^APP_URL=' .env | cut -d= -f2-)"
echo "Cek status: bash health.sh"
