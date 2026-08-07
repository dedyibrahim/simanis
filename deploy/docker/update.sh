#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

docker compose build
docker compose up -d
docker compose exec -T api php artisan migrate --force
docker compose exec -T api php artisan optimize:clear
docker compose exec -T api php artisan config:cache
docker compose exec -T api php artisan route:cache

echo "Update selesai."
