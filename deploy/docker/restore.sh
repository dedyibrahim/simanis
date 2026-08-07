#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if [ "${1:-}" = "" ]; then
  echo "Pakai: bash restore.sh backups/YYYYMMDD-HHMMSS.tar.gz"
  exit 1
fi

restore_dir="backups/restore-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$restore_dir"
tar -xzf "$1" -C "$restore_dir"

docker compose up -d db redis

db_name="$(grep '^MYSQL_DATABASE=' .env | cut -d= -f2-)"
db_user="$(grep '^MYSQL_USER=' .env | cut -d= -f2-)"
db_pass="$(grep '^MYSQL_PASSWORD=' .env | cut -d= -f2-)"

docker compose exec -T db mariadb -u"$db_user" -p"$db_pass" "$db_name" < "$restore_dir/database.sql"

for archive in "$restore_dir"/*.tar.gz; do
  name="$(basename "$archive" .tar.gz)"
  [ "$name" = "database.sql" ] && continue
  docker run --rm \
    -v "${COMPOSE_PROJECT_NAME:-simanis}_${name}:/data" \
    -v "$(pwd)/${restore_dir}:/backup:ro" \
    alpine sh -c "rm -rf /data/* && tar -xzf /backup/${name}.tar.gz -C /data"
done

docker compose up -d
docker compose exec -T api php artisan optimize:clear

echo "Restore selesai dari $1"
