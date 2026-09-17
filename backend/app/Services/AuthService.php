<?php

namespace App\Services;

use App\Helpers\WhatsappHelper;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private WahaClient $wahaClient)
    {
    }

    public function signIn(string $email, string $password): ?array
    {
        $user = User::where('email', $email)->first();
        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        if ($user->login_otp_enabled) {
            return $this->createLoginOtpChallenge($user);
        }

        return $this->issueLoginToken($user);
    }

    public function verifyLoginOtp(string $challengeId, string $code): array
    {
        $challenge = LoginOtpChallenge::query()
            ->where('challenge_id', $challengeId)
            ->whereNull('consumed_at')
            ->first();

        if (!$challenge || $challenge->expires_at->isPast()) {
            return ['success' => false, 'message' => 'Kode OTP sudah kedaluwarsa. Silakan kirim ulang.', 'code' => 422];
        }

        if ($challenge->attempts >= 5) {
            return ['success' => false, 'message' => 'Batas percobaan OTP telah tercapai. Silakan kirim ulang.', 'code' => 429];
        }

        if (!Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');
            return ['success' => false, 'message' => 'Kode OTP tidak sesuai.', 'code' => 422];
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        $user = User::find($challenge->user_id);
        if (!$user) {
            return ['success' => false, 'message' => 'Akun tidak ditemukan.', 'code' => 404];
        }

        return ['success' => true, 'message' => 'Verifikasi OTP berhasil.', 'code' => 200, 'data' => $this->issueLoginToken($user)];
    }

    public function resendLoginOtp(string $challengeId): array
    {
        $challenge = LoginOtpChallenge::query()->where('challenge_id', $challengeId)->first();
        $user = $challenge ? User::find($challenge->user_id) : null;
        if (!$user || !$user->login_otp_enabled) {
            return ['success' => false, 'message' => 'Permintaan OTP tidak valid.', 'code' => 404];
        }

        if ($challenge->created_at && $challenge->created_at->gt(now()->subMinute())) {
            return ['success' => false, 'message' => 'Tunggu 60 detik sebelum mengirim ulang OTP.', 'code' => 429];
        }

        return array_merge(['success' => true, 'code' => 200], $this->createLoginOtpChallenge($user));
    }

    public function signOut($user): void
    {
        if ($user) {
            $user->tokens()->delete();
        }
    }

    public function signInGoogle(string $accessToken): array
    {
        $google = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://www.googleapis.com/oauth2/v3/userinfo')
            ->json();

        if (!isset($google['email']) || empty($google['email'])) {
            return [
                'success' => false,
                'message' => 'Invalid Token',
                'code' => 401,
                'data' => null,
            ];
        }

        $user = User::where('email', $google['email'])->first();
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Email Tidak Terdaftar didalam sistem',
                'code' => 404,
                'data' => null,
            ];
        }

        $token = $user->createToken('token-auth')->plainTextToken;
        return [
            'success' => true,
            'message' => 'Login Berhasil',
            'code' => 200,
            'data' => $this->buildPayload($user, [
                'token_google' => $accessToken,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]),
        ];
    }

    public function checkWhatsappNumber(string $phone): array
    {
        $formattedPhone = WhatsappHelper::formatNumber($phone);
        $user = User::where('phone', $formattedPhone)->first();

        return [
            'registered' => $user !== null,
            'phone' => $formattedPhone,
            'user_exists' => $user !== null,
            'message' => $user ? 'Nomor terdaftar' : 'Nomor tidak terdaftar',
        ];
    }

    public function requestWhatsappOtp(string $phone): array
    {
        $formattedPhone = WhatsappHelper::formatNumber($phone);
        $user = User::where('phone', $formattedPhone)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Nomor WhatsApp tidak terdaftar',
                'code' => 404,
                'data' => null,
            ];
        }

        $response = WhatsappHelper::sendOtp($formattedPhone);
        if (isset($response['error'])) {
            return [
                'success' => false,
                'message' => $response['error'],
                'code' => 400,
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'message' => 'Kode OTP telah dikirim ke WhatsApp Anda',
            'code' => 200,
            'data' => [
                'phone' => $formattedPhone,
                'expires_in' => 300,
            ],
        ];
    }

    public function signInWhatsapp(string $phone, string $otp): array
    {
        $formattedPhone = WhatsappHelper::formatNumber($phone);
        $verification = WhatsappHelper::verifyOtp($formattedPhone, $otp);

        if (isset($verification['error'])) {
            return [
                'success' => false,
                'message' => $verification['error'],
                'code' => 401,
                'data' => null,
            ];
        }

        $user = User::where('phone', $formattedPhone)->first();
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Nomor WhatsApp tidak terdaftar',
                'code' => 404,
                'data' => null,
            ];
        }

        $token = $user->createToken('whatsapp-auth')->plainTextToken;
        return [
            'success' => true,
            'message' => 'Login WhatsApp Berhasil',
            'code' => 200,
            'data' => $this->buildPayload($user, [
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]),
        ];
    }

    private function buildPayload(User $user, array $extra = []): array
    {
        return array_merge([
            'name' => $user->nama_lengkap,
            'phone' => $user->phone,
            'id_user' => $user->id_user,
            'level_user' => $user->level_user,
            'email' => $user->email,
            'status_code' => 200,
            'foto' => $user->foto,
        ], $extra);
    }

    private function issueLoginToken(User $user): array
    {
        $token = $user->createToken('token-auth')->plainTextToken;
        return $this->buildPayload($user, ['access_token' => $token, 'token_type' => 'Bearer']);
    }

    private function createLoginOtpChallenge(User $user): array
    {
        $phone = trim((string) $user->phone);
        if ($phone === '') {
            throw new \RuntimeException('OTP aktif tetapi nomor WhatsApp akun belum tersedia.');
        }

        LoginOtpChallenge::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $challenge = LoginOtpChallenge::create([
            'challenge_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ]);

        $result = $this->wahaClient->sendMessage($phone, "*KODE OTP LOGIN SIMANIS*\n\nKode Anda: *{$code}*\nBerlaku selama 5 menit. Jangan berikan kode ini kepada siapa pun.", [
            'source' => 'auth.login_otp',
            'recipient_user_id' => $user->id,
            'sensitive' => true,
        ]);

        if (!($result['success'] ?? false)) {
            $challenge->delete();
            throw new \RuntimeException('OTP gagal dikirim ke WhatsApp. Silakan coba kembali.');
        }

        return [
            'otp_required' => true,
            'challenge_id' => $challenge->challenge_id,
            'masked_phone' => $this->maskPhone($phone),
            'expires_in' => 300,
        ];
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length <= 6) return str_repeat('*', $length);
        return substr($phone, 0, 4).str_repeat('*', $length - 7).substr($phone, -3);
    }
}
