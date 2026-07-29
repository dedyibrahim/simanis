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
                    $this->createOfficialSession($config, $headers);
                    $this->startOfficialSession($config, $headers);
                    $response = $this->fetchOfficialSessionStatus($config, $sessionEndpoint, $headers);
                }

                $json = $response->json();
                if (!is_array($json)) {
                    $json = [];
                }

                $sessionStatus = strtoupper((string) ($json['status'] ?? 'UNKNOWN'));

                if ($sessionStatus === 'FAILED') {
                    $this->resetOfficialSession($config, $headers);
                    $response = $this->fetchOfficialSessionStatus($config, $sessionEndpoint, $headers);
                    $json = $response->json();
                    if (!is_array($json)) {
                        $json = [];
                    }
                    $sessionStatus = strtoupper((string) ($json['status'] ?? 'UNKNOWN'));
                } elseif (in_array($sessionStatus, ['STOPPED', 'UNKNOWN'], true)) {
                    if ($sessionStatus === 'UNKNOWN') {
                        $this->createOfficialSession($config, $headers);
                    }
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

    public function getResolvedConfig(): array
    {
        return $this->resolveConfig();
    }

    public function getWebhookStatus(): array
    {
        $config = $this->resolveConfig();

        if (!$config['enabled']) {
            return ['status' => 'disabled'];
        }

        if ($config['driver'] !== 'official') {
            return ['status' => 'unsupported', 'message' => 'Status webhook hanya tersedia untuk driver official.'];
        }

        if (empty($config['base_url']) || empty($config['api_key'])) {
            return ['status' => 'incomplete', 'message' => 'Konfigurasi WAHA belum lengkap.'];
        }

        try {
            $session = $this->fetchOfficialSession($config);
            $webhooks = $this->extractWebhooks($session);
            $desired = $this->buildDesiredWebhook($config);
            $matching = $this->matchingWebhook($webhooks, $desired['url']);

            return [
                'status' => $this->webhookMatches($matching, $desired) ? 'synced' : 'not_synced',
                'desired' => $this->publicWebhookConfig($desired),
                'current' => array_map(fn ($webhook) => $this->publicWebhookConfig($webhook), $webhooks),
                'matching' => $matching ? $this->publicWebhookConfig($matching) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('WAHA webhook status exception', ['error' => $e->getMessage()]);
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function syncWebhook(): array
    {
        $config = $this->resolveConfig();

        if (!$config['enabled']) {
            return ['success' => false, 'message' => 'WAHA dinonaktifkan.'];
        }

        if ($config['driver'] !== 'official') {
            return ['success' => false, 'message' => 'Sinkron webhook hanya tersedia untuk driver official.'];
        }

        if (empty($config['base_url']) || empty($config['api_key'])) {
            return ['success' => false, 'message' => 'Konfigurasi WAHA belum lengkap.'];
        }

        if (!$config['webhook_auto_configure']) {
            return ['success' => false, 'message' => 'Auto-config webhook sedang nonaktif.'];
        }

        try {
            $session = $this->fetchOfficialSession($config);
            $currentConfig = is_array($session['config'] ?? null) ? $session['config'] : [];
            $existingWebhooks = $this->extractWebhooks($session);
            $desired = $this->buildDesiredWebhook($config);
            $nextWebhooks = [];
            $replaced = false;

            foreach ($existingWebhooks as $webhook) {
                if (trim((string) ($webhook['url'] ?? '')) === $desired['url']) {
                    if (!$replaced) {
                        $nextWebhooks[] = $desired;
                        $replaced = true;
                    }
                    continue;
                }

                $nextWebhooks[] = $webhook;
            }

            if (!$replaced) {
                $nextWebhooks[] = $desired;
            }

            $changed = json_encode(array_map([$this, 'comparableWebhook'], $existingWebhooks))
                !== json_encode(array_map([$this, 'comparableWebhook'], $nextWebhooks));

            if ($changed) {
                $url = $this->buildUrl($config['base_url'], '/api/sessions/' . rawurlencode($config['session']));
                $response = Http::timeout((int) $config['timeout_seconds'])
                    ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                    ->put($url, [
                        'name' => $config['session'],
                        'config' => array_merge($currentConfig, [
                            'webhooks' => $nextWebhooks,
                        ]),
                    ]);

                if (!$response->successful()) {
                    return [
                        'success' => false,
                        'changed' => false,
                        'http_status' => $response->status(),
                        'message' => 'WAHA menolak update webhook.',
                        'response' => $response->json(),
                    ];
                }
            }

            return [
                'success' => true,
                'changed' => $changed,
                'message' => $changed ? 'Webhook WAHA berhasil disinkronkan.' : 'Webhook WAHA sudah sinkron.',
                'desired' => $this->publicWebhookConfig($desired),
                'current' => array_map(fn ($webhook) => $this->publicWebhookConfig($nextWebhooks ? $webhook : []), $nextWebhooks),
            ];
        } catch (\Throwable $e) {
            Log::warning('WAHA webhook sync exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
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

    private function createOfficialSession(array $config, array $headers): void
    {
        $url = $this->buildUrl($config['base_url'], '/api/sessions');
        $payload = [
            'name' => $config['session'],
            'config' => [
                'ignore' => [
                    'status' => true,
                    'groups' => true,
                    'channels' => true,
                    'broadcast' => true,
                ],
                'webhooks' => [
                    $this->buildDesiredWebhook($config),
                ],
            ],
        ];

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if (!$response->successful() && !in_array($response->status(), [409, 422], true)) {
                Log::warning('WAHA official create session failed', [
                    'session' => $config['session'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WAHA official create session exception', [
                'session' => $config['session'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteOfficialSession(array $config, array $headers): void
    {
        $url = $this->buildUrl($config['base_url'], '/api/sessions/' . rawurlencode($config['session']));

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($headers)
                ->delete($url);

            if (!$response->successful() && $response->status() !== 404) {
                Log::warning('WAHA official delete session failed', [
                    'session' => $config['session'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WAHA official delete session exception', [
                'session' => $config['session'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetOfficialSession(array $config, array $headers): void
    {
        $this->startOfficialSession($config, $headers);
        $stopUrl = $this->buildUrl($config['base_url'], '/api/sessions/' . rawurlencode($config['session']) . '/stop');

        try {
            Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($headers)
                ->post($stopUrl);
        } catch (\Throwable $e) {
            Log::warning('WAHA official stop session exception', [
                'session' => $config['session'],
                'error' => $e->getMessage(),
            ]);
        }

        $this->deleteOfficialSession($config, $headers);
        $this->createOfficialSession($config, $headers);
        $this->startOfficialSession($config, $headers);
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
            'webhook_url' => (string) config('services.waha.webhook_url', 'http://bothwa:8020/webhook/waha'),
            'webhook_events' => $this->normalizeWebhookEvents(config('services.waha.webhook_events', 'message')),
            'webhook_secret' => (string) config('services.waha.webhook_secret', ''),
            'webhook_auto_configure' => $this->toBoolean(config('services.waha.webhook_auto_configure', true)),
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
                'webhook_url' => (string) ($metadata['webhook_url'] ?? $defaults['webhook_url']),
                'webhook_events' => $this->normalizeWebhookEvents($metadata['webhook_events'] ?? $defaults['webhook_events']),
                'webhook_secret' => (string) ($metadata['webhook_secret'] ?? $defaults['webhook_secret']),
                'webhook_auto_configure' => array_key_exists('webhook_auto_configure', $metadata)
                    ? $this->toBoolean($metadata['webhook_auto_configure'])
                    : $defaults['webhook_auto_configure'],
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
        $config['webhook_url'] = trim((string) ($config['webhook_url'] ?? ''));
        $config['webhook_events'] = $this->normalizeWebhookEvents($config['webhook_events'] ?? 'message');
        $config['webhook_secret'] = trim((string) ($config['webhook_secret'] ?? ''));
        $config['webhook_auto_configure'] = $this->toBoolean($config['webhook_auto_configure'] ?? true);
        return $config;
    }

    private function fetchOfficialSession(array $config): array
    {
        $url = $this->buildUrl($config['base_url'], '/api/sessions/' . rawurlencode($config['session']));
        $response = Http::timeout((int) $config['timeout_seconds'])
            ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
            ->get($url);

        if ($response->status() === 404) {
            $this->startOfficialSession($config, $this->headersForDriver($config['driver'], $config['api_key']));
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders($this->headersForDriver($config['driver'], $config['api_key']))
                ->get($url);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Gagal membaca session WAHA. HTTP '.$response->status());
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    private function extractWebhooks(array $session): array
    {
        $config = is_array($session['config'] ?? null) ? $session['config'] : [];
        $webhooks = is_array($config['webhooks'] ?? null) ? $config['webhooks'] : [];

        return array_values(array_filter($webhooks, 'is_array'));
    }

    private function buildDesiredWebhook(array $config): array
    {
        $webhook = [
            'url' => $config['webhook_url'] ?: 'http://bothwa:8020/webhook/waha',
            'events' => $this->normalizeWebhookEvents($config['webhook_events']),
        ];

        if (!empty($config['webhook_secret'])) {
            $webhook['customHeaders'] = [
                ['name' => 'X-Webhook-Secret', 'value' => $config['webhook_secret']],
            ];
        }

        if (empty($webhook['events'])) {
            $webhook['events'] = ['message'];
        }

        return $webhook;
    }

    private function matchingWebhook(array $webhooks, string $url): ?array
    {
        foreach ($webhooks as $webhook) {
            if (trim((string) ($webhook['url'] ?? '')) === $url) {
                return $webhook;
            }
        }

        return null;
    }

    private function webhookMatches(?array $current, array $desired): bool
    {
        if (!$current) {
            return false;
        }

        return $this->comparableWebhook($current) === $this->comparableWebhook($desired);
    }

    private function comparableWebhook(array $webhook): array
    {
        $headers = array_map(function ($header) {
            return [
                'name' => strtolower((string) ($header['name'] ?? '')),
                'value' => (string) ($header['value'] ?? ''),
            ];
        }, is_array($webhook['customHeaders'] ?? null) ? $webhook['customHeaders'] : []);
        usort($headers, fn ($left, $right) => ($left['name'] <=> $right['name']) ?: ($left['value'] <=> $right['value']));

        $events = $this->normalizeWebhookEvents($webhook['events'] ?? []);
        sort($events);

        return [
            'url' => trim((string) ($webhook['url'] ?? '')),
            'events' => $events,
            'customHeaders' => $headers,
        ];
    }

    private function publicWebhookConfig(array $webhook): array
    {
        $headers = is_array($webhook['customHeaders'] ?? null) ? $webhook['customHeaders'] : [];

        return [
            'url' => (string) ($webhook['url'] ?? ''),
            'events' => $this->normalizeWebhookEvents($webhook['events'] ?? []),
            'has_secret' => collect($headers)->contains(function ($header) {
                return strtolower((string) ($header['name'] ?? '')) === 'x-webhook-secret'
                    && trim((string) ($header['value'] ?? '')) !== '';
            }),
        ];
    }

    private function normalizeWebhookEvents($events): array
    {
        $items = is_array($events) ? $events : explode(',', (string) $events);

        return array_values(array_unique(array_filter(array_map(function ($event) {
            return strtolower(trim((string) $event));
        }, $items))));
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
