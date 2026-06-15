<?php

namespace App\Helpers;

use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsappHelper
{
    protected static $otpExpiry = 300; // 5 minutes in seconds

    /**
     * Send OTP via WhatsApp
     */
    public static function sendOtp(string $phone): array
    {
        try {
            $formattedNumber = self::formatNumber($phone);
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            Cache::put('whatsapp_otp_' . $formattedNumber, $otp, self::$otpExpiry);

            $result = (new WahaClient())->sendMessage(
                $formattedNumber,
                "Kode OTP Anda adalah: *$otp*\n\nJangan berikan kode ini kepada siapapun. Kode berlaku 5 menit.",
                ['source' => 'auth.otp.request']
            );

            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => 'OTP berhasil dikirim',
                    'expires_in' => self::$otpExpiry,
                ];
            }

            return ['error' => $result['error'] ?? 'Gagal mengirim OTP melalui WAHA'];
        } catch (\Throwable $e) {
            Log::error('WhatsApp OTP error: ' . $e->getMessage());
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
            $cachedOtp = Cache::get('whatsapp_otp_' . $formattedNumber);

            if (!$cachedOtp) {
                return ['error' => 'OTP tidak valid atau sudah kadaluarsa'];
            }

            if ($cachedOtp !== $otp) {
                return ['error' => 'Kode OTP tidak sesuai'];
            }

            Cache::forget('whatsapp_otp_' . $formattedNumber);

            return [
                'success' => true,
                'message' => 'OTP berhasil diverifikasi',
                'phone' => $formattedNumber,
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp OTP verification error: ' . $e->getMessage());
            return ['error' => 'Terjadi kesalahan saat verifikasi OTP'];
        }
    }

    /**
     * Send custom message
     */
    public static function sendMessage(string $phone, string $message): array
    {
        return (new WahaClient())->sendMessage($phone, $message, ['source' => 'legacy.helper.message']);
    }

    /**
     * Check if number is registered on WhatsApp
     */
    public static function checkNumber(string $phone): array
    {
        return (new WahaClient())->checkNumber($phone);
    }

    /**
     * Format phone number to WhatsApp standard
     */
    public static function formatNumber(string $number): string
    {
        return WahaClient::formatNumber($number);
    }

    /**
     * Get WhatsApp QR code for authentication
     */
    public static function getQrCode(): array
    {
        $data = (new WahaClient())->getStatus();

        return [
            'qr_code' => $data['qr_code'] ?? null,
            'status' => $data['status'] ?? 'not ready',
        ];
    }
}

