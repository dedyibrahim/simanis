<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppBroadcastController extends Controller
{
    private function authorizeSender(Request $request): void
    {
        abort_unless(in_array(strtoupper(trim((string) optional($request->user())->level_user)),
            ['SUPER ADMIN', 'SUPERADMIN'], true), 403, 'Hanya Super Admin yang dapat mengirim Blast WA.');
    }

    private function activeRecipients()
    {
        return User::query()
            ->whereRaw("LOWER(TRIM(COALESCE(status, 'aktif'))) NOT IN ('nonaktif', 'non aktif', 'inactive', 'disabled', '0')")
            ->whereNotNull('nama_lengkap');
    }

    public function index(Request $request)
    {
        $this->authorizeSender($request);
        return response()->json(['data' => $this->activeRecipients()->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap', 'level_user', 'phone'])->map(function ($user) {
                $phone = WahaClient::formatNumber((string) $user->phone);
                return ['id' => $user->id, 'name' => $user->nama_lengkap, 'phone' => $phone,
                    'level' => trim((string) $user->level_user),
                    'available' => (bool) preg_match('/^[1-9][0-9]{7,14}$/', $phone)];
            })]);
    }

    public function history(Request $request)
    {
        $this->authorizeSender($request);
        return response()->json(['data' => DB::table('whatsapp_broadcast_deliveries')
            ->orderByDesc('id')->limit(100)->get()]);
    }

    public function send(Request $request, WahaClient $gateway)
    {
        $this->authorizeSender($request);
        $input = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'recipient_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:4000'],
        ]);
        $message = trim($input['message']);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Pesan tidak boleh kosong.']);
        }
        $recipient = $this->activeRecipients()->find($input['recipient_id']);
        abort_unless($recipient, 422, 'Penerima bukan user aktif.');
        $phone = WahaClient::formatNumber((string) $recipient->phone);
        abort_unless(preg_match('/^[1-9][0-9]{7,14}$/', $phone), 422, 'Nomor WhatsApp penerima tidak valid.');
        $key = ['sender_id' => $request->user()->id, 'batch_id' => $input['batch_id'], 'phone' => $phone];

        // Reserve before contacting the gateway; uncertain deliveries must never be retried automatically.
        $created = DB::table('whatsapp_broadcast_deliveries')->insertOrIgnore(array_merge($key, [
            'recipient_id' => $recipient->id, 'recipient_name' => $recipient->nama_lengkap,
            'message' => $message, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $delivery = DB::table('whatsapp_broadcast_deliveries')->where($key)->first();
        abort_unless($delivery && $delivery->message === $message, 409, 'ID pengiriman sudah digunakan untuk pesan lain.');
        if ($created) {
            try {
                $result = $gateway->sendMessage($phone, $message, [
                    'source' => 'superadmin_broadcast', 'recipient_user_id' => $recipient->id,
                ]);
                $status = !empty($result['success']) ? 'sent' : (($result['status'] ?? '') === 'skipped' ? 'skipped' : 'failed');
                $error = $status === 'sent' ? null : 'Gateway belum mengonfirmasi pengiriman. Periksa WhatsApp Gateway sebelum mengirim ulang.';
            } catch (\Throwable $e) {
                report($e);
                $status = 'unknown';
                $error = 'Status pengiriman belum dapat dipastikan. Periksa WhatsApp sebelum mengirim ulang.';
            }
            DB::table('whatsapp_broadcast_deliveries')->where('id', $delivery->id)
                ->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
            $delivery = DB::table('whatsapp_broadcast_deliveries')->where('id', $delivery->id)->first();
        }
        return response()->json(['data' => $delivery]);
    }
}
