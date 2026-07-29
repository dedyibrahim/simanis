<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\GoogleCalendarSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncGoogleCalendarEvents extends Command
{
    protected $signature = 'events:sync-google-calendar
        {--event-id= : Sinkron satu event tertentu}
        {--from=-30 days : Batas awal event yang disinkronkan}
        {--to=+1 year : Batas akhir event yang disinkronkan}
        {--force : Jalankan walau GOOGLE_CALENDAR_SYNC_ENABLED=false}
        {--dry-run : Tampilkan target tanpa mengirim ke Google Calendar}';

    protected $description = 'Sinkron jadwal SIMANIS ke Google Calendar pusat.';

    public function handle(GoogleCalendarSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (!$dryRun && !$force && !$syncService->isEnabled()) {
            $this->warn('Google Calendar sync nonaktif. Set GOOGLE_CALENDAR_SYNC_ENABLED=true atau pakai --force.');
            return self::SUCCESS;
        }

        if (!$dryRun && !$force && !$this->isAllowedToSyncOnThisServer()) {
            $this->warn('Google Calendar sync dilewati: server ini tidak memegang VIP.');
            return self::SUCCESS;
        }

        if (!$dryRun && !$syncService->isConfigured()) {
            $this->error('Google Calendar belum dikonfigurasi lengkap. Cek GOOGLE_CALENDAR_ID dan file credential JSON.');
            return self::FAILURE;
        }

        $query = Event::query()
            ->with(['users:id,nama_lengkap,email', 'creator:id,nama_lengkap,email'])
            ->orderBy('start_datetime');

        if ($this->option('event-id')) {
            $query->whereKey((int) $this->option('event-id'));
        } else {
            $from = Carbon::parse((string) $this->option('from'));
            $to = Carbon::parse((string) $this->option('to'));
            $query
                ->where('start_datetime', '<=', $to->toDateTimeString())
                ->where('end_datetime', '>=', $from->toDateTimeString());
        }

        $events = $query->get();

        if ($events->isEmpty()) {
            $this->info('Tidak ada event yang perlu disinkronkan.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['ID', 'Judul', 'Mulai', 'Selesai', 'Google Event ID'],
                $events->map(fn (Event $event) => [
                    $event->id,
                    $event->title,
                    optional($event->start_datetime)->toDateTimeString(),
                    optional($event->end_datetime)->toDateTimeString(),
                    $event->google_event_id ?: '-',
                ])->all()
            );

            return self::SUCCESS;
        }

        $success = 0;
        $failed = 0;

        foreach ($events as $event) {
            if ($syncService->syncEvent($event, true)) {
                $success++;
                $this->line("OK event #{$event->id}: {$event->title}");
                continue;
            }

            $failed++;
            $this->warn("Gagal event #{$event->id}: {$event->title}");
        }

        $this->info("Selesai. Sukses: {$success}, gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isAllowedToSyncOnThisServer(): bool
    {
        $onlyOnVip = filter_var(env('GOOGLE_CALENDAR_SYNC_ONLY_ON_VIP', true), FILTER_VALIDATE_BOOLEAN);
        if (!$onlyOnVip) {
            return true;
        }

        $vip = trim((string) env('HA_VIRTUAL_IP', ''));
        if ($vip === '') {
            return true;
        }

        $output = [];
        @exec('/sbin/ip -o -4 addr show 2>/dev/null', $output);

        foreach ($output as $line) {
            if (strpos($line, ' '.$vip.'/') !== false) {
                return true;
            }
        }

        return false;
    }
}
