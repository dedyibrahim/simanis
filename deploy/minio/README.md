# MinIO production service

SIMANIS runs MinIO as a native systemd service. Object data is stored outside
the application tree in `/var/www/datafile`.

Production credentials belong in `/etc/default/minio` with mode `0600`. Never
commit that file. The API listens on port `9000` and the administration console
listens on port `9001`.

The initial private bucket is `simanis-documents`. Bucket versioning must remain
enabled so overwritten and deleted objects can be recovered.

Laravel must use the restricted `simanis-app` account rather than the MinIO root
account. Its production credentials are stored at `/etc/simanis/minio-app.env`.
