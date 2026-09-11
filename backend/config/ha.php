<?php

return [
    'virtual_ip' => env('HA_VIRTUAL_IP', '192.168.0.12'),
    'server_ip' => env('HA_SERVER_IP'),
    'peer_api' => env('HA_PEER_API'),
    'shared_key' => env('HA_SHARED_KEY'),
    'status_file' => env('HA_STATUS_FILE', '/var/lib/simanis-ha/file-sync-status.json'),
    'minio_migration_status_file' => env('MINIO_MIGRATION_STATUS_FILE', '/var/lib/simanis-ha/minio-migration-status.json'),
    'minio_flatten_status_file' => env('MINIO_FLATTEN_STATUS_FILE', '/var/lib/simanis-ha/minio-flatten-status.json'),
    'release_file' => env('HA_RELEASE_FILE', '/var/lib/simanis-ha/release'),
    'bothwa_env_file' => env('BOTHWA_ENV_FILE', '/var/www/bothWA/bothwa.env'),
    'commands' => [
        'bothwa_start' => env('BOTHWA_START_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-start'),
        'bothwa_stop' => env('BOTHWA_STOP_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-stop'),
        'bothwa_status' => env('BOTHWA_STATUS_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-status'),
        'ocr_start' => env('KTP_OCR_START_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-start'),
        'ocr_stop' => env('KTP_OCR_STOP_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-stop'),
        'ocr_restart' => env('KTP_OCR_RESTART_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-restart'),
        'ocr_status' => env('KTP_OCR_STATUS_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-status'),
    ],
];
