#!/usr/bin/env sh
set -e

mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache public || true

if [ -n "${APP_KEY:-}" ]; then
  php artisan storage:link >/dev/null 2>&1 || true
  php artisan config:cache >/dev/null 2>&1 || true
  php artisan route:cache >/dev/null 2>&1 || true
fi

exec "$@"
