#!/usr/bin/env bash
set -euo pipefail

root=/var/www/apinotaris
stage=/var/www/simanis-minio-stage
backup=/var/backups/simanis/minio-cutover-$(date +%Y%m%dT%H%M%S)
test -f "$stage/vendor/autoload.php"
test -f /etc/simanis/minio-app.env
mkdir -p "$backup"
files=(app/Services/DocumentStorage.php app/Http/Controllers/StoredDocumentController.php app/Http/Controllers/ClientController.php app/Http/Controllers/DocumentAccessController.php app/Http/Controllers/DokumenNotaris.php app/Http/Controllers/EmployeeChatController.php app/Http/Controllers/PembuatanNomor.php app/Http/Controllers/ScannedDocumentController.php app/Http/Controllers/TandaTerimaController.php config/filesystems.php routes/web.php public/.htaccess composer.json composer.lock)
for file in "${files[@]}"; do
    test -f "$stage/$file"
    mkdir -p "$backup/$(dirname "$file")"
    if test -f "$root/$file"; then cp -a "$root/$file" "$backup/$file"; fi
done
cp -a "$root/.env" "$backup/.env"
chmod 700 "$backup"
cp -a "$stage/vendor" "$backup/vendor-ready"
cd "$root"
php artisan down --retry=15
for file in "${files[@]}"; do
    mkdir -p "$root/$(dirname "$file")"
    cp "$stage/$file" "$root/$file"
done
mv "$root/vendor" "$backup/vendor-old"
mv "$backup/vendor-ready" "$root/vendor"
python3 - <<'PY'
from pathlib import Path
env = Path('/var/www/apinotaris/.env')
settings = {}
for line in Path('/etc/simanis/minio-app.env').read_text().splitlines():
    if line.startswith('AWS_') and '=' in line:
        key, value = line.split('=', 1)
        settings[key] = value
settings['DOCUMENTS_DISK'] = 'documents'
lines = [line for line in env.read_text().splitlines() if line.split('=', 1)[0] not in settings]
lines.extend(key+'='+value for key, value in settings.items())
env.write_text('\n'.join(lines)+'\n')
PY
php artisan config:clear
php artisan package:discover
php artisan config:cache
php artisan route:cache
php artisan view:cache
php /tmp/verify-document-storage.php
php artisan up
systemctl reload apache2
echo "BACKUP=$backup"
