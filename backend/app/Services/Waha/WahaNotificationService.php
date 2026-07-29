<?php

namespace App\Services\Waha;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WahaNotificationService
{
    private $wahaClient;
    private const ADMIN_LEVELS = ['Admin', 'Super Admin', 'SuperAdmin'];

    public function __construct(WahaClient $wahaClient)
    {
        $this->wahaClient = $wahaClient;
    }

    private function formatEventRange(Event $event): string
    {
        if (!$event->start_datetime) {
            return '-';
        }

        if (!$event->end_datetime) {
            return $event->start_datetime->format('d M Y, H:i').' WIB';
        }

        if ($event->start_datetime->isSameDay($event->end_datetime)) {
            return $event->start_datetime->format('d M Y, H:i')
                .' - '.$event->end_datetime->format('H:i').' WIB';
        }

        return $event->start_datetime->format('d M Y, H:i')
            .' - '.$event->end_datetime->format('d M Y, H:i').' WIB';
    }

    public function notifyEventCreated(Event $event, Collection $usersToNotify, string $creatorName): void
    {
        $allParticipantNames = $this->picNames($usersToNotify);

        foreach ($usersToNotify as $user) {
            if (!$user instanceof User || empty($user->phone)) {
                continue;
            }

            $otherParticipants = array_filter($allParticipantNames, function ($name) use ($user) {
                return $name !== $user->nama_lengkap;
            });

            $message = "Halo {$user->nama_lengkap},\n\n";
            $message .= "Anda telah ditambahkan ke dalam jadwal acara baru:\n\n";
            $message .= "*Judul Acara:* {$event->title}\n";
            $message .= "*Tanggal/Jam:* ".$this->formatEventRange($event)."\n";
            $message .= "*Lokasi:* " . ($event->location ?: '-') . "\n";

            if (!empty($event->description)) {
                $message .= "*Keterangan:* {$event->description}\n";
            }

            $message .= "*Dibuat oleh:* {$creatorName}\n";

            if (!empty($otherParticipants)) {
                $message .= "*PIC:* " . implode(', ', $otherParticipants) . "\n";
            }

            $message .= "\nTerima kasih.";

            try {
                $this->wahaClient->sendMessage($user->phone, $message, [
                    'source' => 'calendar.created',
                    'event_id' => $event->id,
                    'recipient_user_id' => $user->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi WA event.created', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyEventCancelled(Event $event, Collection $usersToNotify, string $creatorName, string $deletedByName): void
    {
        $eventStartTime = $this->formatEventRange($event);

        $participantListString = implode(', ', $this->picNames($usersToNotify));

        foreach ($usersToNotify as $user) {
            if (!$user instanceof User || empty($user->phone)) {
                continue;
            }

            $message = "*PEMBERITAHUAN PEMBATALAN ACARA*\n\n";
            $message .= "Halo {$user->nama_lengkap},\n";
            $message .= "Mohon maaf, acara berikut telah DIBATALKAN:\n\n";
            $message .= "*Judul Acara:* {$event->title}\n";
            $message .= "*Waktu Semula:* {$eventStartTime}\n";
            $message .= "*Dibuat oleh:* {$creatorName}\n";
            $message .= "*Dibatalkan oleh:* {$deletedByName}\n";

            if (!empty($participantListString)) {
                $message .= "*PIC:* {$participantListString}\n";
            }

            $message .= "\nTerima kasih atas perhatiannya.";

            try {
                $this->wahaClient->sendMessage($user->phone, $message, [
                    'source' => 'calendar.cancelled',
                    'event_id' => $event->id,
                    'recipient_user_id' => $user->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi WA event.cancelled', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyEventCreatedFromChatbot(Event $event, Collection $usersToNotify, string $creatorName): void
    {
        foreach ($usersToNotify as $user) {
            if (!$user instanceof User || empty($user->phone)) {
                continue;
            }

            $message = "Halo {$user->nama_lengkap},\n\n";
            $message .= "Anda telah ditambahkan ke dalam jadwal acara baru oleh {$creatorName}:\n\n";
            $message .= "*Judul Acara:* {$event->title}\n";
            $message .= "*Waktu:* ".$this->formatEventRange($event)."\n";
            $message .= "*Lokasi:* ".($event->location ?: '-')."\n";
            if (!empty($event->description)) {
                $message .= "*Keterangan:* {$event->description}\n";
            }
            $message .= "\n";
            $message .= "Terima kasih.";

            try {
                $this->wahaClient->sendMessage($user->phone, $message, [
                    'source' => 'calendar.chatbot_created',
                    'event_id' => $event->id,
                    'recipient_user_id' => $user->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi WA event.chatbot_created', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyEventReminder(Event $event, Collection $usersToNotify, string $senderName): void
    {
        $eventStartTime = $this->formatEventRange($event);

        $participantListString = implode(', ', $this->picNames($usersToNotify));

        foreach ($usersToNotify as $user) {
            if (!$user instanceof User || empty($user->phone)) {
                continue;
            }

            $message = "*PENGINGAT ACARA*\n\n";
            $message .= "Halo {$user->nama_lengkap},\n";
            $message .= "Ini pengingat untuk agenda yang akan/ sedang berlangsung:\n\n";
            $message .= "*Judul Acara:* {$event->title}\n";
            $message .= "*Waktu:* {$eventStartTime}\n";
            $message .= "*Lokasi:* " . ($event->location ?: '-') . "\n";

            if (!empty($event->description)) {
                $message .= "*Keterangan:* {$event->description}\n";
            }

            $message .= "*Dikirim oleh:* {$senderName}\n";

            if (!empty($participantListString)) {
                $message .= "*PIC:* {$participantListString}\n";
            }

            $message .= "\nMohon persiapan tepat waktu. Terima kasih.";

            try {
                $this->wahaClient->sendMessage($user->phone, $message, [
                    'source' => 'calendar.manual_reminder',
                    'event_id' => $event->id,
                    'recipient_user_id' => $user->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi WA event.manual_reminder', [
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function picNames(Collection $users): array
    {
        return $users
            ->filter(function ($user) {
                return $user instanceof User
                    && !in_array((string) $user->level_user, self::ADMIN_LEVELS, true);
            })
            ->pluck('nama_lengkap')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
