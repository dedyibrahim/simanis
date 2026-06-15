<?php

namespace App\Services;

use App\Helpers\WhatsappHelper;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class AuthService
{
    public function signIn(string $email, string $password): ?array
    {
        if (!Auth::attempt(['email' => $email, 'password' => $password])) {
            return null;
        }

        $user = User::where('email', $email)->first();
        if (!$user || !Hash::check($password, $user->password, [])) {
            throw new \RuntimeException('Error in Login');
        }

        $token = $user->createToken('token-auth')->plainTextToken;
        return $this->buildPayload($user, [
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
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
}
