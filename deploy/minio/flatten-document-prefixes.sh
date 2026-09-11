#!/usr/bin/env bash
set -Eeuo pipefail

ALIAS="simanis-flatten"
BUCKET="simanis-documents"
SOURCE_PREFIX="legacy-public"
MINIO_ENV="/etc/default/minio"
ALLOWED_PREFIXES=(
  berkasclient
  berkaslegalisasis
  berkasnotaris
  berkasppat
  berkaswarmerkings
  chat_attachments
  scanned-documents
  suratnotaris
  suratppats
  tandaterima
)

set -a
# shellcheck disable=SC1090
source "$MINIO_ENV"
set +a

mc alias set "$ALIAS" http://127.0.0.1:9000 \
  "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null

read_total() {
  local path="$1"
  local result

  result="$(mc du --json --recursive "$path" | tail -n 1)"
  php -r '
    $data = json_decode($argv[1], true);
    if (!is_array($data) || ($data["status"] ?? "") !== "success") {
      fwrite(STDERR, "Invalid MinIO size response.\n");
      exit(1);
    }
    echo (int) ($data["objects"] ?? 0), " ", (int) ($data["size"] ?? 0);
  ' "$result"
}

for prefix in "${ALLOWED_PREFIXES[@]}"; do
  source_path="${ALIAS}/${BUCKET}/${SOURCE_PREFIX}/${prefix}"
  destination_path="${ALIAS}/${BUCKET}/${prefix}"

  echo "Copying ${source_path} to ${destination_path}"
  mc mirror --preserve --retry "$source_path" "$destination_path"

  read -r source_objects source_bytes <<< "$(read_total "$source_path")"
  read -r destination_objects destination_bytes <<< "$(read_total "$destination_path")"

  if [[ "$source_objects" -ne "$destination_objects" || "$source_bytes" -ne "$destination_bytes" ]]; then
    echo "Verification failed for ${prefix}: source=${source_objects}/${source_bytes}, destination=${destination_objects}/${destination_bytes}" >&2
    exit 1
  fi

  echo "Verified ${prefix}: ${destination_objects} objects, ${destination_bytes} bytes"
done

echo "All requested prefixes verified. Removing legacy and backup prefixes."
mc rm --recursive --force --versions "${ALIAS}/${BUCKET}/${SOURCE_PREFIX}"
mc rm --recursive --force --versions "${ALIAS}/${BUCKET}/system-backups"

mapfile -t remaining < <(mc ls "${ALIAS}/${BUCKET}" | awk '{print $NF}' | sed 's#/$##' | sort)
mapfile -t expected < <(printf '%s\n' "${ALLOWED_PREFIXES[@]}" | sort)

if [[ "$(printf '%s\n' "${remaining[@]}")" != "$(printf '%s\n' "${expected[@]}")" ]]; then
  echo "Unexpected bucket prefixes remain:" >&2
  printf '%s\n' "${remaining[@]}" >&2
  exit 1
fi

echo "Bucket layout completed and verified."
