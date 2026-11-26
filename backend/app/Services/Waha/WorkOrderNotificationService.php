<?php

namespace App\Services\Waha;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderNotificationService
{
    public function stage(int $id, string $stage, ?object $actor = null): void
    {
        $item = DB::table('work_order_items')->where('id', $id)->first();
        if (!$item) return;
        $order = DB::table('orders')->where('id_order', $item->id_order)->first();
        if (!$order) return;
        $users = DB::table('users')->get(['id_user', 'nama_lengkap', 'phone', 'level_user']);
        $recipients = $users->filter(fn ($user) =>
            in_array($user->id_user, [$item->id_user, $order->id_user], true)
            || in_array(strtoupper(trim($user->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true)
        )->filter(fn ($user) => trim((string) $user->phone) !== '')
            ->unique(fn ($user) => WahaClient::formatNumber($user->phone))->values();
        $assignee = $users->firstWhere('id_user', $item->id_user);
        $message = "*UPDATE PEKERJAAN*\n\nTahap: {$stage}\nPesanan: {$order->nama_pesanan} ({$order->id_order})\nPekerjaan: {$item->title}\nJenis: ".strtoupper($item->type)
            ."\nPenanggung jawab: ".($assignee->nama_lengkap ?? '-');
        if ($item->event_id) {
            $event = DB::table('events')->where('id', $item->event_id)->first();
            if ($event) $message .= "\nJadwal TTD: {$event->start_datetime} s/d {$event->end_datetime}\nLokasi: ".($event->location ?: '-');
        }
        if ($item->book_number !== null) $message .= "\nNomor: {$item->book_number}";
        $message .= "\nOleh: ".($actor->nama_lengkap ?? $actor->id_user ?? 'Sistem')
            ."\nWaktu: ".now()->format('d-m-Y H:i');
        // Capture before deletion; send only after the outermost transaction commits.
        DB::afterCommit(function () use ($recipients, $message, $id, $stage, $item) {
            foreach ($recipients as $recipient) {
                try {
                    app(WahaClient::class)->sendMessage($recipient->phone, $message, [
                        'source' => 'work_order.stage', 'work_item_id' => $id,
                        'order_id' => $item->id_order, 'stage' => $stage,
                        'recipient_user_id' => $recipient->id_user,
                    ]);
                } catch (\Throwable $exception) {
                    Log::error('Work order WhatsApp notification failed', [
                        'work_item_id' => $id, 'stage' => $stage,
                        'recipient_user_id' => $recipient->id_user, 'error' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }
}
