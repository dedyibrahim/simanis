#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${1:-/var/www/bothWA/bothwa.env}"
tmp="$(mktemp)"
touch "${ENV_FILE}"

awk -F= '
  BEGIN {
    values["KTP_OCR_BASE_URL"]="http://ktp-ocr-lab:8765"
    values["KTP_OCR_CONFIG"]="paddleocr-roi-balanced.json"
    values["KTP_OCR_ENGINE"]="paddleocr"
    values["KTP_OCR_TIMEOUT_MS"]="90000"
  }
  {
    key=$1
    if (key in values) {
      print key "=" values[key]
      seen[key]=1
    } else {
      print $0
    }
  }
  END {
    for (key in values) {
      if (!(key in seen)) {
        print key "=" values[key]
      }
    }
  }
' "${ENV_FILE}" > "${tmp}"

cat "${tmp}" > "${ENV_FILE}"
rm -f "${tmp}"
