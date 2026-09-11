#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/var/www/apinotaris}"
SOURCE_DIR="${SOURCE_DIR:-${APP_ROOT}/public}"
DESTINATION="${DESTINATION:-simanis-app-migration/simanis-documents/legacy-public}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/simanis/database}"
APP_ENV="${APP_ROOT}/.env"
MINIO_ENV="/etc/simanis/minio-app.env"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"

env_value() {
  local key="$1"
  local file="$2"
  local value

  value="$(sed -n "s/^${key}=//p" "$file" | tail -n 1)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "$value"
}

for required in "$SOURCE_DIR" "$APP_ENV" "$MINIO_ENV"; do
  if [[ ! -e "$required" ]]; then
    echo "Required path is missing: $required" >&2
    exit 1
  fi
done

DB_HOST="$(env_value DB_HOST "$APP_ENV")"
DB_PORT="$(env_value DB_PORT "$APP_ENV")"
DB_DATABASE="$(env_value DB_DATABASE "$APP_ENV")"
DB_USERNAME="$(env_value DB_USERNAME "$APP_ENV")"
DB_PASSWORD="$(env_value DB_PASSWORD "$APP_ENV")"

mkdir -p "$BACKUP_ROOT"
chmod 0700 "$BACKUP_ROOT"
BACKUP_FILE="${BACKUP_ROOT}/${DB_DATABASE}-${TIMESTAMP}.sql.gz"

echo "Creating consistent database backup: $BACKUP_FILE"
MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --single-transaction \
  --quick \
  --routines \
  --events \
  --triggers \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE" | gzip -1 > "$BACKUP_FILE"
chmod 0600 "$BACKUP_FILE"
gzip -t "$BACKUP_FILE"
sha256sum "$BACKUP_FILE" > "${BACKUP_FILE}.sha256"

set -a
# shellcheck disable=SC1090
source "$MINIO_ENV"
set +a

mc alias set simanis-app-migration \
  "$AWS_ENDPOINT" "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" >/dev/null

echo "Uploading database backup to MinIO"
mc cp "$BACKUP_FILE" "${BACKUP_FILE}.sha256" \
  simanis-app-migration/simanis-documents/system-backups/database/

echo "Mirroring $SOURCE_DIR to $DESTINATION"
mc mirror --preserve --retry "$SOURCE_DIR" "$DESTINATION"

echo "Migration copy completed at $(date -u +%FT%TZ)"
