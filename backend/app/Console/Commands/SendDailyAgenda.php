<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Models\WahaMessageLog;
use App\Services\Waha\WahaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SendDailyAgenda extends Command
{
    protected $signature = 'events:send-daily-agenda
        {slot : morning, noon, atau evening}
        {--dry-run : Tampilkan target tanpa mengirim pesan}';

    protected $description = 'Kirim agenda harian kepada Admin/Super Admin dan peserta terkait';

    private WahaClient $wahaClient;

    public function __construct(WahaClient $wahaClient)
    {
        parent::__construct();
        $this->wahaClient = $wahaClient;
    }

    public function handle(): int
    {
        $slot = strtolower((string) $this->argument('slot'));
        $slotLabels = [
            'morning' => 'PAGI',
            'noon' => 'SIANG',
            'evening' => 'SORE',
        ];

        if (!isset($slotLabels[$slot])) {
            $this->error('Slot harus morning, noon, atau evening.');
            return self::INVALID;
        }

        $now = now();
        $events = Event::with([
            'users:id,nama_lengkap,phone,level_user',
            'creator:id,nama_lengkap',
        ])
            ->where('start_datetime', '<=', $now->copy()->endOfDay())
            ->where(function ($query) use ($now) {
                $query
                    ->where('end_datetime', '>=', $now)
                    ->orWhere(function ($withoutEndQuery) use ($now) {
                        $withoutEndQuery
                            ->whereNull('end_datetime')
                            ->where('start_datetime', '>=', $now);
                    });
            })
            ->orderBy('start_datetime')
            ->get();

        $globalRecipients = $this->globalRecipients();
        $agendasByUser = [];
        foreach ($events as $event) {
            foreach ($this->eligibleRecipients($event->users, $globalRecipients) as $user) {
                if (!isset($agendasByUser[$user->id])) {
                    $agendasByUser[$user->id] = [
                        'user' => $user,
                        'events' => collect(),
                    ];
                }
                $agendasByUser[$user->id]['events']->push($event);
            }
        }

        if (empty($agendasByUser)) {
            $this->info('Tidak ada agenda hari ini untuk PIC terkait.');
            return self::SUCCESS;
        }

        $source = 'calendar.daily_agenda.'.$slot;
        foreach ($agendasByUser as $row) {
            $user = $row['user'];
            $userEvents = $row['events'];

            if ($this->wasAlreadySentToday($source, (int) $user->id)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("DRY RUN: {$slot} -> {$user->nama_lengkap} ({$userEvents->count()} agenda)");
                continue;
            }

            $result = $this->wahaClient->sendMessage(
                (string) $user->phone,
                $this->buildAgendaMessage($slotLabels[$slot], $user->nama_lengkap, $userEvents),
                [
                    'source' => $source,
                    'recipient_user_id' => $user->id,
                ]
            );

            if (!($result['success'] ?? false)) {
                Log::error('Gagal mengirim ringkasan agenda harian', [
                    'slot' => $slot,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        }

        return self::SUCCESS;
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

    private function wasAlreadySentToday(string $source, int $recipientUserId): bool
    {
        return WahaMessageLog::query()
            ->where('source', $source)
            ->where('recipient_user_id', $recipientUserId)
            ->where('status', 'sent')
            ->whereDate('created_at', today())
            ->exists();
    }

    private function buildAgendaMessage(string $slotLabel, string $recipientName, Collection $events): string
    {
        $message = "*AGENDA HARI INI - {$slotLabel}*\n";
        $message .= now()->translatedFormat('l, d F Y')."\n\n";
        $message .= "Halo {$recipientName}, berikut agenda Anda yang masih akan atau sedang berlangsung:\n\n";

        foreach ($events->values() as $index => $event) {
            $time = $event->start_datetime->format('H:i');
            if ($event->end_datetime) {
                $time .= ' - '.$event->end_datetime->format('H:i');
            }

            $message .= ($index + 1).". *{$event->title}*\n";
            $message .= "   Waktu: {$time} WIB\n";
            $message .= "   Lokasi: ".($event->location ?: '-')."\n";
            if (!empty($event->description)) {
                $message .= "   Ket: {$event->description}\n";
            }
            $message .= "\n";
        }

        $message .= "Mohon mempersiapkan agenda sesuai jadwal.";
        return $message;
    }
}
