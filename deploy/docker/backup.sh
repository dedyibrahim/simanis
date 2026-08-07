#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

backup_dir="backups/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"

db_name="$(grep '^MYSQL_DATABASE=' .env | cut -d= -f2-)"
db_user="$(grep '^MYSQL_USER=' .env | cut -d= -f2-)"
db_pass="$(grep '^MYSQL_PASSWORD=' .env | cut -d= -f2-)"

docker compose exec -T db mariadb-dump -u"$db_user" -p"$db_pass" "$db_name" > "$backup_dir/database.sql"
cp .env "$backup_dir/env.backup"

for volume in \
  laravel_storage \
  public_berkasclient \
  public_berkaslegalisasis \
  public_berkasnotaris \
  public_berkasppat \
  public_berkaswarmerkings \
  public_chat_attachments \
  public_foto \
  public_suratnotaris \
  public_tandaterima \
  waha_sessions; do
  docker run --rm \
    -v "${COMPOSE_PROJECT_NAME:-simanis}_${volume}:/data:ro" \
    -v "$(pwd)/${backup_dir}:/backup" \
    alpine tar -czf "/backup/${volume}.tar.gz" -C /data .
done

tar -czf "${backup_dir}.tar.gz" -C "$backup_dir" .
echo "Backup selesai: ${backup_dir}.tar.gz"
