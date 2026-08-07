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
if [ -n "${REDIS_PASSWORD:-}" ]; then
  docker compose exec -T redis redis-cli -a "$REDIS_PASSWORD" ping || true
else
  docker compose exec -T redis redis-cli ping || true
fi
docker compose exec -T ktp-ocr python - <<'PY'
import urllib.request
print(urllib.request.urlopen("http://127.0.0.1:8765/api/health", timeout=5).read().decode())
PY
