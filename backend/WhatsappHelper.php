<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WhatsappHelper
{
    protected static $otpExpiry = 300; // 5 minutes in seconds

    protected static function apiUrl(): string
    {
        return rtrim((string) env('WA_GATEWAY_BASE_URL', 'http://127.0.0.1:8020'), '/');
    }

    protected static function apiKey(): string
    {
        return trim((string) env('WA_GATEWAY_API_KEY', env('BOT_API_KEY', '')));
    }

    /**
     * Send OTP via WhatsApp
     */
    public static function sendOtp(string $phone): array
    {
        try {
            $formattedNumber = self::formatNumber($phone);

            // Generate 6-digit OTP
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store OTP in cache for verification
            Cache::put('whatsapp_otp_'.$formattedNumber, $otp, self::$otpExpiry);

            // Send OTP via WhatsApp API
            $response = Http::timeout(30)
                ->withHeaders(['X-Api-Key' => self::apiKey()])
                ->post(self::apiUrl().'/send-message', [
                    'number' => $formattedNumber,
                    'message' => "Kode OTP Anda adalah: *$otp*\n\nJangan berikan kode ini kepada siapapun. Kode berlaku 5 menit."
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'OTP berhasil dikirim',
                    'expires_in' => self::$otpExpiry
                ];
            }

            Log::error('WhatsApp API error: '.$response->body());
            return ['error' => 'Gagal mengirim OTP melalui WhatsApp API'];

        } catch (\Exception $e) {
            Log::error('WhatsApp OTP error: '.$e->getMessage());
            return ['error' => 'Terjadi kesalahan saat mengirim OTP'];
        }
    }

    /**
     * Verify OTP
     */
    public static function verifyOtp(string $phone, string $otp): array
    {
        try {
            $formattedNumber = self::formatNumber($phone);
            $cachedOtp = Cache::get('whatsapp_otp_'.$formattedNumber);

            if (!$cachedOtp) {
                return ['error' => 'OTP tidak valid atau sudah kadaluarsa'];
            }

            if ($cachedOtp !== $otp) {
                return ['error' => 'Kode OTP tidak sesuai'];
            }

            // OTP verified, clear from cache
            Cache::forget('whatsapp_otp_'.$formattedNumber);

            return [
                'success' => true,
                'message' => 'OTP berhasil diverifikasi',
                'phone' => $formattedNumber
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp OTP verification error: '.$e->getMessage());
            return ['error' => 'Terjadi kesalahan saat verifikasi OTP'];
        }
    }

    /**
     * Send custom message
     */
    public static function sendMessage(string $phone, string $message): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Api-Key' => self::apiKey()])
                ->post(self::apiUrl().'/send-message', [
                    'number' => self::formatNumber($phone),
                    'message' => $message
                ]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WhatsApp send message error: '.$e->getMessage());
            return ['error' => 'Gagal mengirim pesan WhatsApp'];
        }
    }

    /**
     * Check if number is registered on WhatsApp
     */
    public static function checkNumber(string $phone): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Api-Key' => self::apiKey()])
                ->get(self::apiUrl().'/is-registered', [
                    'number' => self::formatNumber($phone)
                ]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WhatsApp check number error: '.$e->getMessage());
            return ['error' => 'Gagal memeriksa nomor WhatsApp'];
        }
    }

    /**
     * Format phone number to WhatsApp standard
     */
    public static function formatNumber(string $number): string
    {
        // Remove all non-digit characters
        $number = preg_replace('/[^0-9]/', '', $number);

        // If starts with 0, replace with country code (62 for Indonesia)
        if (strpos($number, '0') === 0) {
            $number = '62'.substr($number, 1);
        }

        return $number;
    }

    /**
     * Get WhatsApp QR code for authentication
     */
    public static function getQrCode(): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Api-Key' => self::apiKey()])
                ->get(self::apiUrl().'/status');

            $data = $response->json();

            return [
                'qr_code' => $data['qr_code'] ?? null,
                'status' => $data['status'] ?? 'not ready'
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp QR error: '.$e->getMessage());
            return ['error' => 'Gagal mendapatkan QR code'];
        }
    }
}
