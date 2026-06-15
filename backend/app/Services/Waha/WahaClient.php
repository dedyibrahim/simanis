<?php

namespace App\Services\Waha;

use App\Models\WahaConfig;
use App\Models\WahaMessageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WahaClient
{
    /**
     * Kirim pesan WhatsApp via WAHA.
     */
    public function sendMessage(string $phone, string $message, array $context = []): array
    {
        $config = $this->resolveConfig();
        $formattedPhone = self::formatNumber($phone);

        if (!$config['enabled']) {
            return $this->buildSkippedResponse(
                $formattedPhone,
                $message,
                'WAHA dinonaktifkan',
                $context
            );
        }

        if (empty($config['base_url']) || empty($config['api_key'])) {
            return $this->buildSkippedResponse(
                $formattedPhone,
                $message,
                'Konfigurasi WAHA belum lengkap (base_url/api_key kosong)',
                $context
            );
        }

        if ($config['driver'] === 'official') {
            return $this->sendMessageOfficial($config, $formattedPhone, $message, $context);
        }

        return $this->sendMessageLegacy($config, $formattedPhone, $message, $context);
    }

    /**
     * Cek apakah nomor terdaftar di WhatsApp.
     */
    public function checkNumber(string $phone): array
    {
        $config = $this->resolveConfig();
        $formattedPhone = self::formatNumber($phone);

        if (!$config['enabled']) {
            return ['error' => 'WAHA dinonaktifkan'];
        }

        if (empty($config['base_url']) || empty($config['api_key'])) {
            return ['error' => 'Konfigurasi WAHA belum lengkap'];
        }

        try {
            if ($config['driver'] === 'official') {
                $url = $this->buildUrl($config['base_url'], $config['check_number_endpoint']);
                $response = Http::timeout((int) $config['timeout_seconds'])
                    ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                    ->get($url, [
                        'phone' => $formattedPhone,
                        'session' => $config['session'],
                    ]);

                $json = $response->json();
                if (!is_array($json)) {
                    $json = [];
                }

                return array_merge([
                    'registered' => (bool) ($json['numberExists'] ?? false),
                    'phone' => $formattedPhone,
                    'user_exists' => (bool) ($json['numberExists'] ?? false),
                ], $json);
            }

            $url = $this->buildUrl($config['base_url'], $config['check_number_endpoint']);
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                ->get($url, ['number' => $formattedPhone]);

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('WAHA checkNumber exception', ['error' => $e->getMessage()]);
            return ['error' => 'Gagal memeriksa nomor WhatsApp'];
        }
    }

    /**
     * Ambil status gateway.
     */
    public function getStatus(): array
    {
        $config = $this->resolveConfig();

        if (!$config['enabled']) {
            return ['status' => 'disabled', 'qr_code' => null];
        }

        if (empty($config['base_url']) || empty($config['api_key'])) {
            return ['error' => 'Konfigurasi WAHA belum lengkap'];
        }

        try {
            if ($config['driver'] === 'official') {
                $sessionsEndpoint = rtrim((string) $config['status_endpoint'], '/');
                $sessionEndpoint = $sessionsEndpoint . '/' . $config['session'];
                $headers = $this->headersForDriver($config['driver'], $config['api_key']);
                $response = $this->fetchOfficialSessionStatus($config, $sessionEndpoint, $headers);

                if ($response->status() === 404) {
                    $this->startOfficialSession($config, $headers);
                    $response = $this->fetchOfficialSessionStatus($config, $sessionEndpoint, $headers);
                }

                $json = $response->json();
                if (!is_array($json)) {
                    $json = [];
                }

                $sessionStatus = strtoupper((string) ($json['status'] ?? 'UNKNOWN'));

                if (in_array($sessionStatus, ['STOPPED', 'FAILED', 'UNKNOWN'], true)) {
                    $this->startOfficialSession($config, $headers);
                    $response = $this->fetchOfficialSessionStatus($config, $sessionEndpoint, $headers);
                    $json = $response->json();
                    if (!is_array($json)) {
                        $json = [];
                    }
                    $sessionStatus = strtoupper((string) ($json['status'] ?? 'UNKNOWN'));
                }

                $isReady = in_array($sessionStatus, ['WORKING', 'CONNECTED', 'AUTHENTICATED'], true);

                $result = [
                    'status' => $isReady ? 'ready' : 'not ready',
                    'session_status' => $sessionStatus,
                    'session' => $config['session'],
                    'http_status' => $response->status(),
                    'qr_code' => null,
                    'raw' => $json,
                ];

                if (!$isReady && in_array($sessionStatus, ['SCAN_QR_CODE', 'STARTING', 'STOPPED', 'FAILED', 'UNKNOWN'], true)) {
                    $qrEndpoint = '/api/' . $config['session'] . '/auth/qr?format=raw';
                    $qrUrl = $this->buildUrl($config['base_url'], $qrEndpoint);
                    $qrResponse = Http::timeout((int) $config['timeout_seconds'])
                        ->withHeaders($headers)
                        ->withHeaders(['Accept' => 'application/json'])
                        ->get($qrUrl);
                    $qrJson = $qrResponse->json();

                    if (is_array($qrJson) && isset($qrJson['value'])) {
                        $result['qr_code'] = (string) $qrJson['value'];
                    }
                }

                return $result;
            }

            $url = $this->buildUrl($config['base_url'], $config['status_endpoint']);
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                ->get($url);

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('WAHA getStatus exception', ['error' => $e->getMessage()]);
            return ['error' => 'Gagal mendapatkan status WAHA'];
        }
    }

    public static function formatNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (strpos($number, '0') === 0) {
            return '62' . substr($number, 1);
        }

        return $number;
    }

    private function sendMessageLegacy(array $config, string $formattedPhone, string $message, array $context): array
    {
        $url = $this->buildUrl($config['base_url'], $config['send_message_endpoint']);
        $httpStatus = null;
        $responseData = null;
        $status = 'failed';
        $errorMessage = null;

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                ->post($url, [
                    'number' => $formattedPhone,
                    'message' => $message,
                ]);

            $httpStatus = $response->status();
            $responseData = $response->json();

            if ($response->successful()) {
                $status = 'sent';
            } else {
                $errorMessage = 'WAHA API mengembalikan status non-2xx';
                Log::warning('WAHA legacy sendMessage failed', [
                    'status' => $httpStatus,
                    'phone' => $formattedPhone,
                    'response' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            Log::error('WAHA legacy sendMessage exception', [
                'phone' => $formattedPhone,
                'error' => $errorMessage,
            ]);
        }

        $this->storeLog($formattedPhone, $message, $status, $httpStatus, $responseData, $errorMessage, $context);

        return [
            'success' => $status === 'sent',
            'status' => $status,
            'phone' => $formattedPhone,
            'http_status' => $httpStatus,
            'data' => $responseData,
            'error' => $errorMessage,
        ];
    }

    private function sendMessageOfficial(array $config, string $formattedPhone, string $message, array $context): array
    {
        $url = $this->buildUrl($config['base_url'], $config['send_message_endpoint']);
        $httpStatus = null;
        $responseData = null;
        $status = 'failed';
        $errorMessage = null;

        $chatId = $formattedPhone . '@c.us';
        $payload = [
            'session' => $config['session'],
            'chatId' => $chatId,
            'text' => $message,
        ];

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                ->post($url, $payload);

            $httpStatus = $response->status();
            $responseData = $response->json();

            if ($response->successful()) {
                $status = 'sent';
            } else {
                // Fallback: cek chatId resmi lalu kirim ulang sekali.
                $exists = $this->checkNumber($formattedPhone);
                $fallbackChatId = (string) ($exists['chatId'] ?? '');
                if (!empty($fallbackChatId) && $fallbackChatId !== $chatId) {
                    $retryPayload = $payload;
                    $retryPayload['chatId'] = $fallbackChatId;

                    $retry = Http::timeout((int) $config['timeout_seconds'])
                        ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                        ->post($url, $retryPayload);

                    $httpStatus = $retry->status();
                    $responseData = $retry->json();

                    if ($retry->successful()) {
                        $status = 'sent';
                    } else {
                        $errorMessage = 'WAHA official mengembalikan status non-2xx setelah retry';
                    }
                } else {
                    $errorMessage = 'WAHA official mengembalikan status non-2xx';
                }

                if ($status !== 'sent') {
                    Log::warning('WAHA official sendMessage failed', [
                        'status' => $httpStatus,
                        'phone' => $formattedPhone,
                        'response' => is_null($responseData) ? null : $responseData,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            Log::error('WAHA official sendMessage exception', [
                'phone' => $formattedPhone,
                'error' => $errorMessage,
            ]);
        }

        $this->storeLog($formattedPhone, $message, $status, $httpStatus, $responseData, $errorMessage, $context);

        return [
            'success' => $status === 'sent',
            'status' => $status,
            'phone' => $formattedPhone,
            'http_status' => $httpStatus,
            'data' => $responseData,
            'error' => $errorMessage,
        ];
    }

    private function headersForDriver(string $driver, ?string $apiKey): array
    {
        if ($driver === 'official') {
            return ['X-Api-Key' => (string) $apiKey];
        }

        return ['X-API-Key' => (string) $apiKey];
    }

    private function fetchOfficialSessionStatus(array $config, string $sessionEndpoint, array $headers)
    {
        $url = $this->buildUrl($config['base_url'], $sessionEndpoint);

        return Http::timeout((int) $config['timeout_seconds'])
            ->withHeaders($headers)
            ->get($url);
    }

    private function startOfficialSession(array $config, array $headers): void
    {
        $startEndpoint = '/api/sessions/' . $config['session'] . '/start';
        $startUrl = $this->buildUrl($config['base_url'], $startEndpoint);

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($headers)
                ->post($startUrl);

            if (!$response->successful() && $response->status() !== 422) {
                Log::warning('WAHA official start session failed', [
                    'session' => $config['session'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WAHA official start session exception', [
                'session' => $config['session'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildSkippedResponse(string $phone, string $message, string $reason, array $context): array
    {
        $this->storeLog($phone, $message, 'skipped', null, null, $reason, $context);

        return [
            'success' => false,
            'status' => 'skipped',
            'phone' => $phone,
            'http_status' => null,
            'data' => null,
            'error' => $reason,
        ];
    }

    private function resolveConfig(): array
    {
        $defaults = [
            'driver' => (string) config('services.waha.driver', 'legacy'),
            'base_url' => (string) config('services.waha.base_url', 'http://127.0.0.1:8010'),
            'api_key' => config('services.waha.api_key'),
            'session' => (string) config('services.waha.session', 'default'),
            'force_default_session' => $this->toBoolean(config('services.waha.force_default_session', true)),
            'send_message_endpoint' => (string) config('services.waha.send_message_endpoint', '/send-message'),
            'check_number_endpoint' => (string) config('services.waha.check_number_endpoint', '/is-registered'),
            'status_endpoint' => (string) config('services.waha.status_endpoint', '/status'),
            'timeout_seconds' => (int) config('services.waha.timeout_seconds', 30),
            'enabled' => $this->toBoolean(config('services.waha.enabled', true)),
        ];

        if (!$this->tableExists('waha_configs')) {
            return $this->enforceSessionPolicy($defaults);
        }

        try {
            $config = WahaConfig::query()->where('name', 'default')->first();

            if (!$config) {
                $config = WahaConfig::query()->create(array_merge(['name' => 'default'], $defaults));
            }

            $metadata = is_array($config->metadata) ? $config->metadata : [];

            return $this->enforceSessionPolicy([
                'driver' => (string) ($metadata['driver'] ?? $defaults['driver']),
                'base_url' => $config->base_url ?: $defaults['base_url'],
                'api_key' => $config->api_key ?: $defaults['api_key'],
                'session' => (string) ($metadata['session'] ?? $defaults['session']),
                'force_default_session' => $defaults['force_default_session'],
                'send_message_endpoint' => $config->send_message_endpoint ?: $defaults['send_message_endpoint'],
                'check_number_endpoint' => $config->check_number_endpoint ?: $defaults['check_number_endpoint'],
                'status_endpoint' => $config->status_endpoint ?: $defaults['status_endpoint'],
                'timeout_seconds' => $config->timeout_seconds ?: $defaults['timeout_seconds'],
                'enabled' => is_null($config->enabled) ? $defaults['enabled'] : (bool) $config->enabled,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal membaca waha_configs, fallback ke env', ['error' => $e->getMessage()]);
            return $this->enforceSessionPolicy($defaults);
        }
    }

    private function enforceSessionPolicy(array $config): array
    {
        $session = trim((string) ($config['session'] ?? 'default'));
        if ($session === '') {
            $session = 'default';
        }

        $driver = strtolower((string) ($config['driver'] ?? ''));
        $forceDefaultSession = $this->toBoolean($config['force_default_session'] ?? true);

        if ($driver === 'official' && $forceDefaultSession && $session !== 'default') {
            Log::warning('Session WAHA dipaksa ke default (WAHA Core)', [
                'requested_session' => $session,
            ]);
            $session = 'default';
        }

        $config['session'] = $session;
        return $config;
    }

    private function storeLog(
        string $phone,
        string $message,
        string $status,
        $httpStatus,
        $responseData,
        $errorMessage,
        array $context
    ): void {
        if (!$this->tableExists('waha_message_logs')) {
            return;
        }

        try {
            WahaMessageLog::query()->create([
                'event_id' => $context['event_id'] ?? null,
                'recipient_user_id' => $context['recipient_user_id'] ?? null,
                'source' => $context['source'] ?? 'general',
                'phone' => $phone,
                'message' => $message,
                'status' => $status,
                'http_status' => $httpStatus,
                'response_body' => is_null($responseData) ? null : json_encode($responseData),
                'error_message' => $errorMessage,
                'sent_at' => in_array($status, ['sent', 'failed'], true) ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal menyimpan log WAHA', ['error' => $e->getMessage()]);
        }
    }

    private function buildUrl(string $baseUrl, string $endpoint): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = trim($endpoint, '/');

        if ($endpoint === '') {
            return $baseUrl;
        }

        return $baseUrl . '/' . $endpoint;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
