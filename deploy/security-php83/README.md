# Production PHP runtime (2026-10-22)

The production web servers still use Ubuntu 18.04 and have PHP 8.1 installed on
the host. Web requests and the Laravel scheduler must use the patched PHP 8.3.36
FPM container instead.
Do not re-enable `libapache2-mod-php8.1` as an ordinary rollback: that exposes
the PHP core RCE vulnerabilities again.

On both servers, Apache has `proxy` and `proxy_fcgi` enabled, `php8.1` disabled,
and `apache-fpm.conf` enabled as `simanis-php83-fpm.conf`. The FPM container uses
host networking and listens only on `127.0.0.1:19000`. Build the image with:

```sh
docker build -t simanis/php-fpm:8.3.36-rcefix-v3 deploy/security-php83
```

Run one `simanis-php83-fpm` container per server with `--network host`,
`--restart unless-stopped`, and these bind mounts:

- Both: `/var/www/apinotaris:/var/www/apinotaris` and
  `/var/lib/php/sessions:/var/lib/php/sessions`.
- Both: mount `/var/lib/simanis-ha`, `/var/lib/simanis-ha-bridge`, and
  `/var/www/bothWA/bothwa.env` read-only at the same paths in the container.
- server1 (.10): additionally mount `security-small.ini` read-only at
  `/usr/local/etc/php/conf.d/zzzz-node.ini` to preserve the old 8M/2M upload limits.
- server2 (.11): additionally mount `/var/www/notaris.com:/var/www/notaris.com`.
  That site's old `php_value` directives were moved from `.htaccess` to its
  `.user.ini`; Apache must deny HTTP access to `.user.ini`.

The production Laravel `.env` on each server has `APP_ENV=production`,
`APP_DEBUG=false`, and mode `640 root:www-data`. Preserve these settings during
deployment. PHP code and dependencies must not be writable by the web worker;
only application storage and upload directories should be writable.

Install `ha-bridge-server.py` as `/usr/local/libexec/simanis-ha-bridge-server.py`
and `simanis-ha-bridge.service` as a systemd unit. Install
`simanis-ocr-config-update` under `/usr/local/sbin` as root-owned mode 755, with
the dedicated sudoers rule:

```text
www-data ALL=(root) NOPASSWD: /usr/local/sbin/simanis-ocr-config-update
```

Existing sudoers rules permit only the named bothWA, OCR, and MinIO helpers.
Do not mount `/var/run/docker.sock` into the FPM container. The bridge socket
is local, shared with the container read-only, and accepts only enumerated
actions. Set `BOTHWA_*_COMMAND`, `KTP_OCR_*_COMMAND`, and `MINIO_SYNC_COMMAND`
in `.env` to the quoted `/usr/local/bin/simanis-ha-bridge <action>` commands.
Start the host bridge service before the FPM container.
The read-only `system-info` action supplies host OS, Apache, host PHP CLI,
MinIO, and Docker versions to the synchronization page. PHP web, Laravel, and
database versions come from the running Laravel request; host inventory is
cached for ten minutes per node.

The root crontab on each node must run:

```cron
* * * * * docker exec -w /var/www/apinotaris simanis-php83-fpm php artisan schedule:run >> /dev/null 2>&1
```

The image includes `iproute2` for VIP checks and `mysqldump` for monthly
backups. The backup service disables TLS only for loopback database connections;
the production databases on both nodes use `127.0.0.1`.

After a runtime update, verify on **both** nodes and through the VIP (.12):

```sh
docker exec simanis-php83-fpm php -r 'echo PHP_VERSION, "\n";'
apache2ctl -M | grep -E 'php_module|proxy_fcgi_module'
curl -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/api/auth/login
curl -o /dev/null -w '%{http_code}\n' http://127.0.0.1/
```

The expected results are PHP `8.3.36`, `proxy_fcgi_module` without `php_module`,
API `405` for GET login, and frontend `200`. Server2 must also return `200`
on port `8082`. Test login and document uploads interactively after deployment.
Rebuild on a newer patched PHP release when available. Migrating the Ubuntu 18.04
hosts remains separate work and needs a verified backup, failover rehearsal, and
maintenance window. Host PHP 8.1 must not be reintroduced into web or cron
paths. A schema-only `mysqldump` probe passed on both nodes; a complete monthly
backup and restore still need scheduled verification.
