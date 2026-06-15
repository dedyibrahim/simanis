<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use RuntimeException;

class MonthlyDatabaseBackup extends Command
{
    protected $signature = 'backup:database-monthly {--force : Buat backup baru walau bulan ini sudah ada}';

    protected $description = 'Membuat backup database SQL bulanan dan menghapus backup yang lebih lama dari satu tahun.';

    public function handle(DatabaseBackupService $databaseBackupService): int
    {
        try {
            $result = $databaseBackupService->runMonthlyBackup((bool) $this->option('force'));
            $pruned = (array) ($result['pruned'] ?? []);

            $this->info((string) ($result['message'] ?? 'Backup selesai diproses.'));

            if (!empty($result['backup']['file_name'])) {
                $this->line('File: '.$result['backup']['file_name']);
            }

            if (isset($pruned['deleted_count'])) {
                $this->line('Backup terhapus: '.$pruned['deleted_count']);
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
