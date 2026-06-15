<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    private const RETENTION_MONTHS = 12;

    private string $backupDirectory;

    public function __construct()
    {
        $this->backupDirectory = storage_path('app/database-backups');
    }

    public function summary(): array
    {
        $backups = $this->listBackups();
        $lastBackup = $backups[0] ?? null;
        $totalSizeBytes = array_sum(array_map(static fn (array $backup) => (int) ($backup['size_bytes'] ?? 0), $backups));
        $nextRunAt = now()->startOfMonth()->addMonth()->setTime(2, 0);

        return [
            'policy' => [
                'frequency' => 'monthly',
                'retention_months' => self::RETENTION_MONTHS,
                'format' => 'sql',
                'directory' => $this->backupDirectory,
                'schedule_time' => 'Tanggal 1 setiap bulan pukul 02:00',
            ],
            'scheduler' => [
                'command' => 'backup:database-monthly',
                'next_run_at' => $nextRunAt->toIso8601String(),
                'next_run_at_label' => $nextRunAt->translatedFormat('d F Y H:i'),
                'requires_schedule_run' => true,
            ],
            'last_backup' => $lastBackup,
            'total_backups' => count($backups),
            'total_size_bytes' => $totalSizeBytes,
            'total_size_human' => $this->formatBytes($totalSizeBytes),
            'backups' => $backups,
        ];
    }

    public function runMonthlyBackup(bool $force = false): array
    {
        $this->ensureBackupDirectory();

        $currentMonthKey = now()->format('Y-m');
        $existing = collect($this->listBackups())->firstWhere('month_key', $currentMonthKey);

        if ($existing && !$force) {
            $pruned = $this->pruneExpiredBackups();

            return [
                'created' => false,
                'message' => 'Backup SQL untuk bulan ini sudah tersedia.',
                'backup' => $existing,
                'pruned' => $pruned,
            ];
        }

        $fileName = $this->generateFileName();
        $absolutePath = $this->backupDirectory.DIRECTORY_SEPARATOR.$fileName;
        $binary = $this->resolveDumpBinary();

        $connection = config('database.default');
        $database = (array) config("database.connections.{$connection}", []);
        $dbName = (string) ($database['database'] ?? '');
        $dbHost = (string) ($database['host'] ?? '127.0.0.1');
        $dbPort = (string) ($database['port'] ?? '3306');
        $dbUser = (string) ($database['username'] ?? '');
        $dbPassword = (string) ($database['password'] ?? '');
        $dbSocket = trim((string) ($database['unix_socket'] ?? ''));

        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('Konfigurasi database belum lengkap untuk menjalankan backup.');
        }

        $arguments = [
            $binary,
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--host='.$dbHost,
            '--port='.$dbPort,
            '--user='.$dbUser,
            '--result-file='.$absolutePath,
        ];

        if ($dbPassword !== '') {
            $arguments[] = '--password='.$dbPassword;
        }

        if ($dbSocket !== '') {
            $arguments[] = '--socket='.$dbSocket;
        }

        $arguments[] = $dbName;

        $process = new Process($arguments, base_path(), null, null, 300);
        $process->run();

        if (!$process->isSuccessful() || !is_file($absolutePath)) {
            @unlink($absolutePath);

            throw new RuntimeException(
                trim($process->getErrorOutput()) !== ''
                    ? trim($process->getErrorOutput())
                    : 'Backup SQL gagal dibuat. Pastikan mysqldump tersedia dan konfigurasi database benar.'
            );
        }

        $pruned = $this->pruneExpiredBackups();

        return [
            'created' => true,
            'message' => 'Backup SQL berhasil dibuat.',
            'backup' => $this->fileToArray($absolutePath),
            'pruned' => $pruned,
        ];
    }

    public function pruneExpiredBackups(): array
    {
        $this->ensureBackupDirectory();

        $threshold = now()->subMonthsNoOverflow(self::RETENTION_MONTHS);
        $deleted = [];

        foreach (File::files($this->backupDirectory) as $file) {
            if (strtolower($file->getExtension()) !== 'sql') {
                continue;
            }

            $modifiedAt = Carbon::createFromTimestamp($file->getMTime());
            if ($modifiedAt->greaterThanOrEqualTo($threshold)) {
                continue;
            }

            $deleted[] = $file->getFilename();
            @unlink($file->getPathname());
        }

        return [
            'deleted_count' => count($deleted),
            'deleted_files' => $deleted,
            'threshold' => $threshold->toIso8601String(),
            'threshold_label' => $threshold->translatedFormat('d F Y H:i'),
        ];
    }

    public function downloadPath(string $fileName): string
    {
        $safeFileName = basename($fileName);

        if ($safeFileName !== $fileName || !Str::endsWith(strtolower($safeFileName), '.sql')) {
            throw new RuntimeException('Nama file backup tidak valid.');
        }

        $absolutePath = $this->backupDirectory.DIRECTORY_SEPARATOR.$safeFileName;

        if (!is_file($absolutePath)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return $absolutePath;
    }

    public function listBackups(): array
    {
        $this->ensureBackupDirectory();

        $files = collect(File::files($this->backupDirectory))
            ->filter(static fn ($file) => strtolower($file->getExtension()) === 'sql')
            ->sortByDesc(static fn ($file) => $file->getMTime())
            ->values();

        return $files->map(fn ($file) => $this->fileToArray($file->getPathname()))->all();
    }

    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDirectory)) {
            File::makeDirectory($this->backupDirectory, 0755, true);
        }
    }

    private function generateFileName(): string
    {
        $appName = Str::slug((string) config('app.name', 'simanis'));
        $databaseName = Str::slug((string) config('database.connections.'.config('database.default').'.database', 'database'));

        return sprintf(
            '%s-%s-%s.sql',
            $appName !== '' ? $appName : 'simanis',
            $databaseName !== '' ? $databaseName : 'database',
            now()->format('Y-m-d_H-i-s')
        );
    }

    private function resolveDumpBinary(): string
    {
        $configured = trim((string) env('DB_BACKUP_BINARY', ''));
        $candidates = array_filter(array_merge(
            $configured !== '' ? [$configured] : [],
            ['mysqldump', 'mysqldump.exe'],
            $this->commonWindowsCandidates()
        ));

        foreach ($candidates as $candidate) {
            if ($this->commandExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'mysqldump tidak ditemukan. Tambahkan ke PATH atau isi DB_BACKUP_BINARY dengan path mysqldump.exe.'
        );
    }

    private function commandExists(string $candidate): bool
    {
        if (str_contains($candidate, '\\') || str_contains($candidate, '/')) {
            return is_file($candidate);
        }

        $finder = PHP_OS_FAMILY === 'Windows' ? ['where', $candidate] : ['which', $candidate];
        $process = new Process($finder, base_path(), null, null, 10);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    private function commonWindowsCandidates(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $patterns = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\*\\bin\\mysqldump.exe',
            'C:\\Program Files\\MariaDB*\\bin\\mysqldump.exe',
        ];

        $paths = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $match) {
                $paths[] = $match;
            }
        }

        return $paths;
    }

    private function fileToArray(string $absolutePath): array
    {
        $modifiedAt = Carbon::createFromTimestamp(filemtime($absolutePath));
        $sizeBytes = filesize($absolutePath) ?: 0;
        $threshold = now()->subMonthsNoOverflow(self::RETENTION_MONTHS);

        return [
            'file_name' => basename($absolutePath),
            'display_name' => basename($absolutePath),
            'absolute_path' => $absolutePath,
            'size_bytes' => $sizeBytes,
            'size_human' => $this->formatBytes($sizeBytes),
            'created_at' => $modifiedAt->toIso8601String(),
            'created_at_label' => $modifiedAt->translatedFormat('d F Y H:i'),
            'month_key' => $modifiedAt->format('Y-m'),
            'eligible_for_prune' => $modifiedAt->lt($threshold),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 2).' '.$units[$power];
    }
}
