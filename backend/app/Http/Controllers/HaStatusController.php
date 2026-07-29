<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WahaConfig;
use App\Services\Waha\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class HaStatusController extends Controller
{
    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));

        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    public function show(Request $request)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh melihat status sinkronisasi.',
                'data' => [],
            ], 403);
        }

        $local = $this->localStatus();
        $peer = $this->peerStatus();

        return response()->json([
            'status' => true,
            'message' => 'Status sinkronisasi berhasil dimuat.',
            'data' => [
                'checked_at' => now()->toIso8601String(),
                'virtual_ip' => env('HA_VIRTUAL_IP', '192.168.0.12'),
                'local' => $local,
                'peer' => $peer,
                'summary' => $this->buildSummary($local, $peer),
            ],
        ], 200);
    }

    public function whatsapp(Request $request, WahaClient $wahaClient)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh melihat status WhatsApp.',
                'data' => [],
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Status WhatsApp berhasil dimuat.',
            'data' => $this->whatsappStatus($wahaClient, true),
        ], 200);
    }

    public function startWhatsapp(Request $request, WahaClient $wahaClient)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh menjalankan WhatsApp.',
                'data' => [],
            ], 403);
        }

        $result = $this->runServerCommand(
            (string) env('BOTHWA_START_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-start'),
            45
        );

        return response()->json([
            'status' => $result['exit_code'] === 0,
            'message' => $result['exit_code'] === 0
                ? 'WhatsApp Gateway sedang dijalankan.'
                : 'Gagal menjalankan WhatsApp Gateway.',
            'data' => array_merge($this->whatsappStatus($wahaClient, true), [
                'command' => $result,
            ]),
        ], $result['exit_code'] === 0 ? 200 : 500);
    }

    public function stopWhatsapp(Request $request, WahaClient $wahaClient)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh menghentikan WhatsApp.',
                'data' => [],
            ], 403);
        }

        $result = $this->runServerCommand(
            (string) env('BOTHWA_STOP_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-stop'),
            20
        );

        return response()->json([
            'status' => $result['exit_code'] === 0,
            'message' => $result['exit_code'] === 0
                ? 'WhatsApp Gateway dihentikan.'
                : 'Gagal menghentikan WhatsApp Gateway.',
            'data' => array_merge($this->whatsappStatus($wahaClient, false), [
                'command' => $result,
            ]),
        ], $result['exit_code'] === 0 ? 200 : 500);
    }

    public function updateWhatsappSettings(Request $request, WahaClient $wahaClient)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah WhatsApp.',
                'data' => [],
            ], 403);
        }

        $payload = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'driver' => ['nullable', 'in:official,legacy'],
            'base_url' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'string', 'max:100'],
            'send_message_endpoint' => ['required', 'string', 'max:255'],
            'check_number_endpoint' => ['required', 'string', 'max:255'],
            'status_endpoint' => ['required', 'string', 'max:255'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'webhook_url' => ['required', 'string', 'max:255'],
            'webhook_events' => ['required'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'webhook_auto_configure' => ['nullable', 'boolean'],
            'sync_webhook' => ['nullable', 'boolean'],
        ]);

        $config = WahaConfig::query()->firstOrNew(['name' => 'default']);
        $metadata = is_array($config->metadata) ? $config->metadata : [];
        $events = $this->normalizeWebhookEvents($payload['webhook_events'] ?? 'message');

        $config->fill([
            'base_url' => trim((string) $payload['base_url']),
            'api_key' => array_key_exists('api_key', $payload) ? trim((string) $payload['api_key']) : $config->api_key,
            'send_message_endpoint' => trim((string) $payload['send_message_endpoint']),
            'check_number_endpoint' => trim((string) $payload['check_number_endpoint']),
            'status_endpoint' => trim((string) $payload['status_endpoint']),
            'timeout_seconds' => (int) $payload['timeout_seconds'],
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'metadata' => array_merge($metadata, [
                'driver' => $payload['driver'] ?? 'official',
                'session' => trim((string) ($payload['session'] ?? 'default')) ?: 'default',
                'webhook_url' => trim((string) $payload['webhook_url']),
                'webhook_events' => $events ?: ['message'],
                'webhook_secret' => trim((string) ($payload['webhook_secret'] ?? '')),
                'webhook_auto_configure' => (bool) ($payload['webhook_auto_configure'] ?? true),
            ]),
        ]);
        $config->save();

        $syncResult = null;
        if ((bool) ($payload['sync_webhook'] ?? false)) {
            $syncResult = $wahaClient->syncWebhook();
        }

        return response()->json([
            'status' => $syncResult ? (bool) ($syncResult['success'] ?? false) : true,
            'message' => $syncResult
                ? (string) ($syncResult['message'] ?? 'Pengaturan WhatsApp disimpan.')
                : 'Pengaturan WhatsApp berhasil disimpan.',
            'data' => array_merge($this->whatsappStatus($wahaClient, true), [
                'sync_result' => $syncResult,
            ]),
        ], $syncResult && empty($syncResult['success']) ? 422 : 200);
    }

    public function syncWhatsappWebhook(Request $request, WahaClient $wahaClient)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh memperbaiki webhook.',
                'data' => [],
            ], 403);
        }

        $syncResult = $wahaClient->syncWebhook();

        return response()->json([
            'status' => (bool) ($syncResult['success'] ?? false),
            'message' => (string) ($syncResult['message'] ?? 'Sinkron webhook selesai.'),
            'data' => array_merge($this->whatsappStatus($wahaClient, true), [
                'sync_result' => $syncResult,
            ]),
        ], !empty($syncResult['success']) ? 200 : 422);
    }

    public function ktpOcr(Request $request)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh melihat OCR KTP.',
                'data' => [],
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Status OCR KTP berhasil dimuat.',
            'data' => $this->ktpOcrStatus(),
        ], 200);
    }

    public function updateKtpOcrSettings(Request $request)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah OCR KTP.',
                'data' => [],
            ], 403);
        }

        $validated = $request->validate([
            'base_url' => ['required', 'string', 'max:255'],
            'config' => ['required', 'string', 'max:100'],
            'engine' => ['required', 'string', 'max:50'],
            'timeout_ms' => ['required', 'integer', 'min:5000', 'max:300000'],
        ]);

        $result = $this->writeBothwaEnv([
            'KTP_OCR_BASE_URL' => trim((string) $validated['base_url']),
            'KTP_OCR_CONFIG' => trim((string) $validated['config']),
            'KTP_OCR_ENGINE' => trim((string) $validated['engine']),
            'KTP_OCR_TIMEOUT_MS' => (string) (int) $validated['timeout_ms'],
        ]);

        return response()->json([
            'status' => $result['ok'],
            'message' => $result['ok']
                ? 'Pengaturan OCR KTP disimpan. Restart bothWA agar env baru aktif.'
                : 'Gagal menyimpan pengaturan OCR KTP.',
            'data' => array_merge($this->ktpOcrStatus(), [
                'write_result' => $result,
            ]),
        ], $result['ok'] ? 200 : 500);
    }

    public function startKtpOcr(Request $request)
    {
        return $this->runKtpOcrAction($request, 'start');
    }

    public function stopKtpOcr(Request $request)
    {
        return $this->runKtpOcrAction($request, 'stop');
    }

    public function restartKtpOcr(Request $request)
    {
        return $this->runKtpOcrAction($request, 'restart');
    }

    public function internal(Request $request)
    {
        $expectedKey = trim((string) env('HA_SHARED_KEY', ''));
        $providedKey = trim((string) $request->header('X-HA-Key', ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return response()->json([
            'status' => true,
            'data' => $this->localStatus(),
        ], 200);
    }

    private function localStatus(): array
    {
        $slaveRows = DB::select('SHOW SLAVE STATUS');
        $masterRows = DB::select('SHOW MASTER STATUS');
        $database = DB::selectOne(
            'SELECT COUNT(*) AS client_count, MAX(updated_at) AS latest_client_update FROM data_clients'
        );
        $slave = $slaveRows[0] ?? null;
        $master = $masterRows[0] ?? null;
        $fileStatusPath = env('HA_STATUS_FILE', '/var/lib/simanis-ha/file-sync-status.json');
        $fileStatus = [];

        if (is_file($fileStatusPath)) {
            $decoded = json_decode((string) file_get_contents($fileStatusPath), true);
            $fileStatus = is_array($decoded) ? $decoded : [];
        }

        return [
            'hostname' => gethostname(),
            'server_ip' => env('HA_SERVER_IP', request()->server('SERVER_ADDR')),
            'role' => env('HA_ROLE', 'standalone'),
            'vip_active' => $this->isVirtualIpActive(),
            'application_release' => $this->releaseVersion(),
            'resources' => $this->resourceStatus(),
            'database' => [
                'client_count' => (int) ($database->client_count ?? 0),
                'latest_client_update' => $database->latest_client_update ?? null,
                'master_log_file' => $master->File ?? null,
                'master_log_position' => isset($master->Position) ? (int) $master->Position : null,
                'slave_configured' => $slave !== null,
                'slave_io_running' => $slave->Slave_IO_Running ?? null,
                'slave_sql_running' => $slave->Slave_SQL_Running ?? null,
                'seconds_behind_master' => isset($slave->Seconds_Behind_Master)
                    ? (int) $slave->Seconds_Behind_Master
                    : null,
                'master_host' => $slave->Master_Host ?? null,
                'last_io_error' => $slave->Last_IO_Error ?? '',
                'last_sql_error' => $slave->Last_SQL_Error ?? '',
            ],
            'files' => $fileStatus,
        ];
    }

    private function whatsappStatus(WahaClient $wahaClient, bool $includeQr): array
    {
        $wahaStatus = [];

        try {
            $wahaStatus = $wahaClient->getStatus();
        } catch (\Throwable $error) {
            $wahaStatus = ['error' => $error->getMessage()];
        }

        $qrCode = (string) ($wahaStatus['qr_code'] ?? '');
        $qrImage = null;

        if ($includeQr && $qrCode !== '') {
            try {
                $svg = QrCode::format('svg')
                    ->size(260)
                    ->margin(1)
                    ->generate($qrCode);
                $qrImage = 'data:image/svg+xml;base64,'.base64_encode((string) $svg);
            } catch (\Throwable $error) {
                $wahaStatus['qr_error'] = $error->getMessage();
            }
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'containers' => $this->containerStatus(['bothwa', 'simanis-waha']),
            'waha' => $wahaStatus,
            'settings' => $this->publicWhatsappSettings($wahaClient->getResolvedConfig()),
            'webhook' => $wahaClient->getWebhookStatus(),
            'qr_image' => $qrImage,
            'qr_available' => $qrImage !== null,
        ];
    }

    private function ktpOcrStatus(): array
    {
        $settings = $this->ktpOcrSettings();
        $healthUrl = $this->ktpOcrHealthUrl($settings['base_url']);
        $health = [
            'ok' => false,
            'http_status' => null,
            'url' => $healthUrl,
            'error' => null,
        ];

        try {
            $response = Http::timeout(4)
                ->acceptJson()
                ->get(rtrim($healthUrl, '/').'/api/health');
            $health = [
                'ok' => $response->successful(),
                'http_status' => $response->status(),
                'url' => $healthUrl,
                'body' => $response->json(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Throwable $error) {
            $health['error'] = $error->getMessage();
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'settings' => $settings,
            'containers' => $this->containerStatus(['ktp-ocr-lab']),
            'health' => $health,
        ];
    }

    private function ktpOcrHealthUrl(string $baseUrl): string
    {
        $url = trim($baseUrl) ?: 'http://127.0.0.1:8765';
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: 8765;

        if (in_array($host, ['ktp-ocr-lab', 'ocr', 'localhost'], true)) {
            return 'http://127.0.0.1:'.$port;
        }

        return $url;
    }

    private function ktpOcrSettings(): array
    {
        $envValues = $this->readBothwaEnv([
            'KTP_OCR_BASE_URL',
            'KTP_OCR_CONFIG',
            'KTP_OCR_ENGINE',
            'KTP_OCR_TIMEOUT_MS',
        ]);

        return [
            'base_url' => $envValues['KTP_OCR_BASE_URL'] ?? env('KTP_OCR_BASE_URL', 'http://ktp-ocr-lab:8765'),
            'config' => $envValues['KTP_OCR_CONFIG'] ?? env('KTP_OCR_CONFIG', 'paddleocr-fast.json'),
            'engine' => $envValues['KTP_OCR_ENGINE'] ?? env('KTP_OCR_ENGINE', 'paddleocr'),
            'timeout_ms' => (int) ($envValues['KTP_OCR_TIMEOUT_MS'] ?? env('KTP_OCR_TIMEOUT_MS', 90000)),
            'env_path' => env('BOTHWA_ENV_FILE', '/var/www/bothWA/bothwa.env'),
        ];
    }

    private function runKtpOcrAction(Request $request, string $action)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengontrol OCR KTP.',
                'data' => [],
            ], 403);
        }

        $command = [
            'start' => env('KTP_OCR_START_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-start'),
            'stop' => env('KTP_OCR_STOP_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-stop'),
            'restart' => env('KTP_OCR_RESTART_COMMAND', 'sudo -n /usr/local/sbin/simanis-ktp-ocr-restart'),
        ][$action] ?? '';
        $result = $this->runServerCommand((string) $command, 120);

        return response()->json([
            'status' => $result['exit_code'] === 0,
            'message' => $result['exit_code'] === 0
                ? 'Aksi OCR KTP berhasil dijalankan.'
                : 'Aksi OCR KTP gagal dijalankan.',
            'data' => array_merge($this->ktpOcrStatus(), [
                'command' => $result,
            ]),
        ], $result['exit_code'] === 0 ? 200 : 500);
    }

    private function readBothwaEnv(array $keys): array
    {
        $path = env('BOTHWA_ENV_FILE', '/var/www/bothWA/bothwa.env');
        if (!is_file($path)) {
            return [];
        }

        $wanted = array_fill_keys($keys, true);
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if (isset($wanted[$key])) {
                $values[$key] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $values;
    }

    private function writeBothwaEnv(array $values): array
    {
        $path = env('BOTHWA_ENV_FILE', '/var/www/bothWA/bothwa.env');
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return ['ok' => false, 'error' => 'Folder env bothWA tidak dapat ditulis.'];
        }

        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $seen = [];
        $next = [];

        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                $next[] = $line;
                continue;
            }
            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if (array_key_exists($key, $values)) {
                $next[] = $key.'='.$values[$key];
                $seen[$key] = true;
            } else {
                $next[] = $line;
            }
        }

        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $next[] = $key.'='.$value;
            }
        }

        $backup = $path.'.bak.'.date('YmdHis');
        if (is_file($path)) {
            @copy($path, $backup);
        }

        $written = file_put_contents($path, implode(PHP_EOL, $next).PHP_EOL, LOCK_EX);

        return [
            'ok' => $written !== false,
            'path' => $path,
            'backup' => is_file($backup) ? $backup : null,
        ];
    }

    private function publicWhatsappSettings(array $config): array
    {
        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'driver' => (string) ($config['driver'] ?? 'official'),
            'base_url' => (string) ($config['base_url'] ?? ''),
            'api_key' => (string) ($config['api_key'] ?? ''),
            'session' => (string) ($config['session'] ?? 'default'),
            'send_message_endpoint' => (string) ($config['send_message_endpoint'] ?? '/api/sendText'),
            'check_number_endpoint' => (string) ($config['check_number_endpoint'] ?? '/api/contacts/check-exists'),
            'status_endpoint' => (string) ($config['status_endpoint'] ?? '/api/sessions'),
            'timeout_seconds' => (int) ($config['timeout_seconds'] ?? 30),
            'webhook_url' => (string) ($config['webhook_url'] ?? 'http://bothwa:8020/webhook/waha'),
            'webhook_events' => array_values((array) ($config['webhook_events'] ?? ['message'])),
            'webhook_secret' => (string) ($config['webhook_secret'] ?? ''),
            'webhook_auto_configure' => (bool) ($config['webhook_auto_configure'] ?? true),
        ];
    }

    private function normalizeWebhookEvents($events): array
    {
        $items = is_array($events) ? $events : explode(',', (string) $events);

        return array_values(array_unique(array_filter(array_map(function ($event) {
            return strtolower(trim((string) $event));
        }, $items))));
    }

    private function containerStatus(array $names): array
    {
        $result = array_fill_keys(array_values($names), [
            'exists' => false,
            'running' => false,
            'status' => 'missing',
            'image' => null,
            'restart_count' => null,
        ]);
        $statusCommand = trim((string) env('BOTHWA_STATUS_COMMAND', 'sudo -n /usr/local/sbin/simanis-bothwa-status'));
        $lines = [];
        $exitCode = 1;

        if ($statusCommand !== '') {
            exec($statusCommand.' 2>/dev/null', $lines, $exitCode);
        }

        if ($exitCode === 0 && !empty($lines)) {
            foreach ($lines as $line) {
                [$name, $status, $running, $image, $restartCount] = array_pad(explode('|', $line), 5, null);
                $name = ltrim((string) $name, '/');

                if (!array_key_exists($name, $result)) {
                    continue;
                }

                $result[$name] = [
                    'exists' => $status !== 'missing',
                    'running' => $running === 'true',
                    'status' => $status ?: 'missing',
                    'image' => $image ?: null,
                    'restart_count' => is_numeric($restartCount) ? (int) $restartCount : null,
                ];
            }
        }

        foreach ($names as $name) {
            if (($result[$name]['exists'] ?? false) === true) {
                continue;
            }

            $command = 'docker inspect --format ' . escapeshellarg('{{.State.Status}}|{{.State.Running}}|{{.Config.Image}}|{{.RestartCount}}') . ' ' . escapeshellarg($name) . ' 2>/dev/null';
            $lines = [];
            $exitCode = 1;
            exec($command, $lines, $exitCode);

            if ($exitCode !== 0 || empty($lines)) {
                continue;
            }

            [$status, $running, $image, $restartCount] = array_pad(explode('|', $lines[0]), 4, null);
            $result[$name] = [
                'exists' => true,
                'running' => $running === 'true',
                'status' => $status,
                'image' => $image,
                'restart_count' => is_numeric($restartCount) ? (int) $restartCount : null,
            ];
        }

        return $result;
    }

    private function runServerCommand(string $command, int $timeoutSeconds): array
    {
        $command = trim($command);

        if ($command === '') {
            return [
                'exit_code' => 1,
                'output' => ['Perintah server belum dikonfigurasi.'],
            ];
        }

        $output = [];
        $exitCode = 1;
        $wrappedCommand = 'timeout '.max(1, $timeoutSeconds).'s '.$command.' 2>&1';
        exec($wrappedCommand, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => array_slice($output, -30),
        ];
    }

    private function peerStatus(): array
    {
        $peerApi = rtrim(trim((string) env('HA_PEER_API', '')), '/');
        $sharedKey = trim((string) env('HA_SHARED_KEY', ''));

        if ($peerApi === '' || $sharedKey === '') {
            return [
                'reachable' => false,
                'error' => 'Konfigurasi peer belum tersedia.',
            ];
        }

        try {
            $response = Http::timeout(4)
                ->acceptJson()
                ->withHeaders(['X-HA-Key' => $sharedKey])
                ->get($peerApi.'/api/internal/ha-status');

            if (!$response->successful()) {
                return [
                    'reachable' => false,
                    'error' => 'Peer mengembalikan HTTP '.$response->status().'.',
                ];
            }

            return [
                'reachable' => true,
                'data' => $response->json('data'),
            ];
        } catch (\Throwable $error) {
            return [
                'reachable' => false,
                'error' => $error->getMessage(),
            ];
        }
    }

    private function buildSummary(array $local, array $peer): array
    {
        $peerData = $peer['data'] ?? [];
        $peerDatabase = $peerData['database'] ?? [];
        $localDatabase = $local['database'] ?? [];
        $replicaHealthy = ($peerData['role'] ?? '') === 'standby'
            ? ($peerDatabase['slave_io_running'] ?? '') === 'Yes'
                && ($peerDatabase['slave_sql_running'] ?? '') === 'Yes'
                && (int) ($peerDatabase['seconds_behind_master'] ?? 999999) <= 10
            : true;
        $databaseMatches = ($peer['reachable'] ?? false)
            && (int) ($localDatabase['client_count'] ?? -1) === (int) ($peerDatabase['client_count'] ?? -2)
            && (string) ($localDatabase['latest_client_update'] ?? '') === (string) ($peerDatabase['latest_client_update'] ?? '');
        $peerFiles = $peerData['files'] ?? [];
        $peerFileStatus = (string) ($peerFiles['status'] ?? '');
        $filesHealthy = $peerFileStatus === 'synced'
            && !empty($peerFiles['last_success_at']);
        $filesHealthy = $filesHealthy
            || (
                in_array($peerFileStatus, ['synced', 'standby'], true)
                && (int) ($peerFiles['progress_percent'] ?? 0) >= 100
                && empty($peerFiles['last_error'])
            )
            || (
                (int) ($peerFiles['progress_percent'] ?? 0) >= 100
                && empty($peerFiles['last_error'])
            );
        $applicationMatches = ($peer['reachable'] ?? false)
            && ($local['application_release'] ?? '') !== ''
            && ($local['application_release'] ?? '') === ($peerData['application_release'] ?? '');

        return [
            'peer_reachable' => (bool) ($peer['reachable'] ?? false),
            'database_replication_healthy' => $replicaHealthy,
            'database_data_matches' => $databaseMatches,
            'files_synchronized' => $filesHealthy,
            'application_matches' => $applicationMatches,
            'overall_healthy' => (bool) ($peer['reachable'] ?? false)
                && $replicaHealthy
                && $databaseMatches
                && $filesHealthy
                && $applicationMatches,
        ];
    }

    private function releaseVersion(): string
    {
        $path = env('HA_RELEASE_FILE', '/var/lib/simanis-ha/release');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    private function isVirtualIpActive(): bool
    {
        $virtualIp = trim((string) env('HA_VIRTUAL_IP', ''));

        if ($virtualIp === '') {
            return false;
        }

        $addresses = [];
        exec('/sbin/ip -o -4 addr show 2>/dev/null', $addresses);

        return str_contains(implode("\n", $addresses), $virtualIp.'/');
    }

    private function resourceStatus(): array
    {
        $memory = $this->memoryStatus();
        $diskTotal = @disk_total_space('/var/www') ?: 0;
        $diskFree = @disk_free_space('/var/www') ?: 0;
        $load = sys_getloadavg() ?: [0, 0, 0];
        $uptime = is_readable('/proc/uptime')
            ? (int) floor((float) explode(' ', trim((string) file_get_contents('/proc/uptime')))[0])
            : 0;

        return [
            'cpu_usage_percent' => $this->cpuUsagePercent(),
            'cpu_cores' => $this->cpuCoreCount(),
            'cpu_model' => $this->cpuModel(),
            'load_1' => round((float) ($load[0] ?? 0), 2),
            'load_5' => round((float) ($load[1] ?? 0), 2),
            'load_15' => round((float) ($load[2] ?? 0), 2),
            'memory_total_bytes' => $memory['total'],
            'memory_used_bytes' => $memory['used'],
            'memory_usage_percent' => $memory['percent'],
            'disk_total_bytes' => (int) $diskTotal,
            'disk_used_bytes' => (int) max(0, $diskTotal - $diskFree),
            'disk_usage_percent' => $diskTotal > 0
                ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1)
                : 0,
            'uptime_seconds' => $uptime,
        ];
    }

    private function memoryStatus(): array
    {
        $values = [];

        if (is_readable('/proc/meminfo')) {
            foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                    $values[$matches[1]] = (int) $matches[2] * 1024;
                }
            }
        }

        $total = (int) ($values['MemTotal'] ?? 0);
        $available = (int) ($values['MemAvailable'] ?? 0);
        $used = max(0, $total - $available);

        return [
            'total' => $total,
            'used' => $used,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function cpuUsagePercent(): float
    {
        $first = $this->cpuTimes();
        usleep(100000);
        $second = $this->cpuTimes();
        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];

        return $totalDelta > 0
            ? round((1 - ($idleDelta / $totalDelta)) * 100, 1)
            : 0;
    }

    private function cpuTimes(): array
    {
        $line = is_readable('/proc/stat')
            ? trim((string) (file('/proc/stat', FILE_IGNORE_NEW_LINES)[0] ?? ''))
            : '';
        $parts = preg_split('/\s+/', $line) ?: [];
        $times = array_map('intval', array_slice($parts, 1));

        return [
            'idle' => (int) (($times[3] ?? 0) + ($times[4] ?? 0)),
            'total' => array_sum($times),
        ];
    }

    private function cpuCoreCount(): int
    {
        $matches = [];
        $contents = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
        preg_match_all('/^processor\s*:/m', $contents, $matches);

        return max(1, count($matches[0] ?? []));
    }

    private function cpuModel(): string
    {
        $contents = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';

        return preg_match('/^model name\s*:\s*(.+)$/m', $contents, $matches)
            ? trim($matches[1])
            : 'Tidak diketahui';
    }
}
