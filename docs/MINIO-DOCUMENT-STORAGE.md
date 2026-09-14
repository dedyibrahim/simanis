# Document storage

Document reads, uploads, copies and deletes use `App\Services\DocumentStorage`.
The existing database paths remain unchanged. The ten migrated prefixes are
stored directly below the private `simanis-documents` bucket.

Set `DOCUMENTS_DISK=documents` and provide the AWS variables from
`/etc/simanis/minio-app.env` to enable MinIO. Local development defaults to
`documents_local`, rooted at Laravel's public directory.

Legacy public asset URLs are routed through Laravel by `.htaccess`, including
when a legacy file is present. Their existing public visibility is preserved;
download approval and scan-session authorization remain in their controllers.
The MinIO bucket itself stays private. Temporary files for ZIP creation are
removed at request shutdown. Scan posting copies the object so a failed database
transaction cannot remove its source.

Photo/profile and report branding assets (`foto`, `report-settings`, `assets`) remain local because
they were not included in the document migration. Generated reports and database
backups retain their existing storage and delivery behavior.

Before activation, run `vendor/bin/phpunit --filter DocumentStorageTest`, check
an existing object in each prefix, and verify a uniquely named test object's
upload/read/copy/delete round trip. Preserve the old files on the standby until
the application has been validated. A rollback must restore document data before
switching back to local storage on a server whose public documents were removed.
