#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
set -a
. ./.env
set +a

docker compose ps
echo
docker compose exec -T api php artisan about --only=environment || true
echo
docker compose exec -T db mariadb-admin ping -h 127.0.0.1 -uroot -p"$(grep '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2-)" --silent \
  && echo "DB OK"
read_only="$(docker compose exec -T db mariadb -N -uroot -p"$(grep '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2-)" -e "SELECT @@read_only, @@super_read_only" | tr -d '\r')"
if [ "$read_only" != $'0\t0' ]; then
  echo "Database lokal terkunci read-only: $read_only" >&2
  exit 1
fi
if [ -n "${REDIS_PASSWORD:-}" ]; then
  docker compose exec -T redis redis-cli -a "$REDIS_PASSWORD" ping || true
else
  docker compose exec -T redis redis-cli ping || true
fi
docker compose exec -T ktp-ocr python - <<'PY'
import urllib.request
print(urllib.request.urlopen("http://127.0.0.1:8765/api/health", timeout=5).read().decode())
PY
