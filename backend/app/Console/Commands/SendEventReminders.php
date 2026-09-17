<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Models\WahaMessageLog;
use App\Services\Waha\WahaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders {--dry-run : Tampilkan target tanpa mengirim pesan}';

    protected $description = 'Kirim reminder satu jam kepada Admin/Super Admin dan peserta terkait';
    private const ADMIN_LEVELS = ['Admin', 'Super Admin', 'SuperAdmin'];

    private WahaClient $wahaClient;

    public function __construct(WahaClient $wahaClient)
    {
        parent::__construct();
        $this->wahaClient = $wahaClient;
    }

    public function handle(): int
    {
        if (!$this->isPrimaryServer()) {
            $this->info('Dilewati karena server ini tidak memegang Virtual IP.');
            return self::SUCCESS;
        }

        $now = now();
        $reminderLimit = $now->copy()->addHour();

        $events = Event::with([
            'users:id,nama_lengkap,phone,level_user',
            'creator:id,nama_lengkap',
        ])
            ->where('start_datetime', '>', $now)
            ->where('start_datetime', '<=', $reminderLimit)
            ->whereNull('reminder_sent_at')
            ->orderBy('start_datetime')
            ->get();

        if ($events->isEmpty()) {
            $this->info('Tidak ada acara yang perlu diingatkan saat ini.');
            return self::SUCCESS;
        }

        $globalRecipients = $this->globalRecipients();

        foreach ($events as $event) {
            $recipients = $this->eligibleRecipients($event->users, $globalRecipients);

            if ($recipients->isEmpty()) {
                Log::warning('Reminder acara dilewati karena tidak memiliki peserta dengan nomor WhatsApp', [
                    'event_id' => $event->id,
                ]);
                $event->forceFill(['reminder_sent_at' => now()])->save();
                continue;
            }

            $allSent = true;
            foreach ($recipients as $user) {
                if ($this->wasAlreadySent((int) $event->id, (int) $user->id)) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("DRY RUN: {$event->title} -> {$user->nama_lengkap}");
                    $allSent = false;
                    continue;
                }

                $result = $this->wahaClient->sendMessage(
                    (string) $user->phone,
                    $this->buildReminderMessage($event, $user->nama_lengkap, $recipients),
                    [
                        'source' => 'calendar.one_hour_reminder',
                        'event_id' => $event->id,
                        'recipient_user_id' => $user->id,
                    ]
                );

                if (!($result['success'] ?? false)) {
                    $allSent = false;
                    Log::error('Gagal mengirim reminder satu jam', [
                        'event_id' => $event->id,
                        'user_id' => $user->id,
                        'phone' => $user->phone,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            }

            if ($allSent && !$this->option('dry-run')) {
                $event->forceFill(['reminder_sent_at' => now()])->save();
            }
        }

        return self::SUCCESS;
    }

    private function isPrimaryServer(): bool
    {
        $virtualIp = trim((string) config('ha.virtual_ip', '192.168.0.12'));
        $output = [];
        $exitCode = 1;
        exec('ip -4 addr show 2>/dev/null', $output, $exitCode);

        return $exitCode === 0
            && $virtualIp !== ''
            && str_contains(implode("\n", $output), $virtualIp.'/');
    }

    private function globalRecipients(): Collection
    {
        return User::query()
            ->whereIn('level_user', ['Admin', 'Super Admin'])
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'nama_lengkap', 'phone', 'level_user']);
    }

    private function eligibleRecipients(Collection $taggedUsers, Collection $globalRecipients): Collection
    {
        return $taggedUsers
            ->concat($globalRecipients)
            ->filter(function ($user) {
                return !empty($user->phone);
            })
            ->unique('id')
            ->values();
    }

    private function wasAlreadySent(int $eventId, int $recipientUserId): bool
    {
        return WahaMessageLog::query()
            ->where('source', 'calendar.one_hour_reminder')
            ->where('event_id', $eventId)
            ->where('recipient_user_id', $recipientUserId)
            ->where('status', 'sent')
            ->exists();
    }

    private function buildReminderMessage(Event $event, string $recipientName, Collection $recipients): string
    {
        $creatorName = $event->creator->nama_lengkap ?? 'Sistem';
        $picNames = $event->users
            ->filter(fn ($user) => !in_array((string) $user->level_user, self::ADMIN_LEVELS, true))
            ->pluck('nama_lengkap')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $time = $event->start_datetime->format('d M Y, H:i');
        if ($event->end_datetime) {
            $time .= ' - '.$event->end_datetime->format('H:i');
        }

        $message = "*PENGINGAT ACARA 1 JAM LAGI*\n\n";
        $message .= "Halo {$recipientName},\n";
        $message .= "Agenda berikut akan segera dimulai:\n\n";
        $message .= "*Judul:* {$event->title}\n";
        $message .= "*Waktu:* {$time} WIB\n";
        $message .= "*Lokasi:* ".($event->location ?: '-')."\n";
        if (!empty($event->description)) {
            $message .= "*Keterangan:* {$event->description}\n";
        }
        $message .= "*Dibuat oleh:* {$creatorName}\n";
        if ($picNames !== '') {
            $message .= "*PIC:* {$picNames}\n";
        }
        $message .= "\nMohon mempersiapkan diri. Terima kasih.";

        return $message;
    }
}
