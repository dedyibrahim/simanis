<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\GoogleCalendarSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class GoogleCalendarSyncController extends Controller
{
    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));

        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    public function show(Request $request, GoogleCalendarSyncService $syncService)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh melihat status Google Calendar.',
                'data' => [],
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Status Google Calendar berhasil dimuat.',
            'data' => $this->statusPayload($syncService),
        ], 200);
    }

    public function sync(Request $request, GoogleCalendarSyncService $syncService)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh menjalankan sinkron Google Calendar.',
                'data' => [],
            ], 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'string', 'max:50'],
            'to' => ['nullable', 'string', 'max:50'],
            'event_id' => ['nullable', 'integer', 'min:1'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('dry_run')) {
            return response()->json([
                'status' => true,
                'message' => 'Dry-run Google Calendar berhasil dibuat.',
                'data' => array_merge($this->statusPayload($syncService), [
                    'dry_run' => true,
                    'targets' => $this->targetEvents($validated)->take(100)->values(),
                ]),
            ], 200);
        }

        if (!$this->hasGoogleCalendarColumns()) {
            return response()->json([
                'status' => false,
                'message' => 'Kolom Google Calendar belum tersedia di database server ini. Jalankan migration setelah server standby dipromosikan atau saat database tidak read-only.',
                'data' => $this->statusPayload($syncService),
            ], 422);
        }

        $arguments = [
            '--force' => true,
            '--from' => (string) ($validated['from'] ?? '-30 days'),
            '--to' => (string) ($validated['to'] ?? '+1 year'),
        ];

        if (!empty($validated['event_id'])) {
            $arguments['--event-id'] = (int) $validated['event_id'];
        }

        $exitCode = Artisan::call('events:sync-google-calendar', $arguments);
        $output = trim(Artisan::output());

        return response()->json([
            'status' => $exitCode === 0,
            'message' => $exitCode === 0
                ? 'Sinkron Google Calendar selesai.'
                : 'Sinkron Google Calendar selesai dengan error.',
            'data' => array_merge($this->statusPayload($syncService), [
                'command' => [
                    'exit_code' => $exitCode,
                    'output' => array_slice(preg_split('/\r\n|\r|\n/', $output) ?: [], -80),
                ],
            ]),
        ], $exitCode === 0 ? 200 : 500);
    }

    public function update(Request $request, GoogleCalendarSyncService $syncService)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah pengaturan Google Calendar.',
                'data' => [],
            ], 403);
        }

        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'only_on_vip' => ['required', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:80'],
        ]);

        $this->writeEnvValues([
            'GOOGLE_CALENDAR_ID' => trim($validated['calendar_id']),
            'GOOGLE_CALENDAR_SYNC_ENABLED' => $validated['enabled'] ? 'true' : 'false',
            'GOOGLE_CALENDAR_SYNC_ONLY_ON_VIP' => $validated['only_on_vip'] ? 'true' : 'false',
            'GOOGLE_CALENDAR_TIMEZONE' => trim((string) ($validated['timezone'] ?? 'Asia/Jakarta')) ?: 'Asia/Jakarta',
        ]);

        Artisan::call('optimize:clear');

        return response()->json([
            'status' => true,
            'message' => 'Pengaturan Google Calendar berhasil disimpan.',
            'data' => $this->statusPayload($syncService),
        ], 200);
    }

    public function clearErrors(Request $request, GoogleCalendarSyncService $syncService)
    {
        if (!$this->isAdminOrSuper($request->user())) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh membersihkan error Google Calendar.',
                'data' => [],
            ], 403);
        }

        $cleared = $this->hasGoogleCalendarColumns()
            ? Event::query()
                ->whereNotNull('google_sync_error')
                ->update(['google_sync_error' => null])
            : 0;

        return response()->json([
            'status' => true,
            'message' => $cleared.' error Google Calendar berhasil dibersihkan.',
            'data' => $this->statusPayload($syncService),
        ], 200);
    }

    private function statusPayload(GoogleCalendarSyncService $syncService): array
    {
        $calendarId = trim((string) env('GOOGLE_CALENDAR_ID', ''));
        $credentialPath = trim((string) env(
            'GOOGLE_CALENDAR_CREDENTIALS',
            storage_path('app/google-calendar/service-account-credentials.json')
        ));

        $total = Event::count();
        $hasGoogleColumns = $this->hasGoogleCalendarColumns();
        $synced = $hasGoogleColumns ? Event::whereNotNull('google_event_id')->count() : 0;
        $failed = $hasGoogleColumns ? Event::whereNotNull('google_sync_error')->count() : 0;
        $lastSyncedAt = $hasGoogleColumns ? Event::whereNotNull('google_synced_at')->max('google_synced_at') : null;

        return [
            'checked_at' => now()->toIso8601String(),
            'enabled' => $syncService->isEnabled(),
            'configured' => $syncService->isConfigured(),
            'schema_ready' => $hasGoogleColumns,
            'only_on_vip' => filter_var(env('GOOGLE_CALENDAR_SYNC_ONLY_ON_VIP', true), FILTER_VALIDATE_BOOLEAN),
            'vip_active' => $this->isVipActive(),
            'calendar_id' => $calendarId,
            'timezone' => trim((string) env('GOOGLE_CALENDAR_TIMEZONE', 'Asia/Jakarta')) ?: 'Asia/Jakarta',
            'calendar_embed_url' => $calendarId !== ''
                ? 'https://calendar.google.com/calendar/embed?src='.rawurlencode($calendarId).'&ctz=Asia%2FJakarta'
                : null,
            'credential_exists' => $credentialPath !== '' && is_file($credentialPath),
            'service_account_email' => $this->serviceAccountEmail($credentialPath),
            'event_counts' => [
                'total' => $total,
                'synced' => $synced,
                'pending' => max(0, $total - $synced),
                'failed' => $failed,
            ],
            'last_synced_at' => $lastSyncedAt,
            'recent_errors' => $hasGoogleColumns
                ? Event::query()
                    ->whereNotNull('google_sync_error')
                    ->latest('updated_at')
                    ->limit(5)
                    ->get(['id', 'title', 'google_sync_error', 'updated_at'])
                    ->map(fn (Event $event) => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'error' => $event->google_sync_error,
                        'updated_at' => optional($event->updated_at)->toIso8601String(),
                    ])
                    ->values()
                : [],
        ];
    }

    private function targetEvents(array $options)
    {
        $query = Event::query()
            ->orderBy('start_datetime');

        if (!empty($options['event_id'])) {
            $query->where('id', (int) $options['event_id']);
        } else {
            $from = Carbon::parse((string) ($options['from'] ?? '-30 days'));
            $to = Carbon::parse((string) ($options['to'] ?? '+1 year'));
            $query
                ->where('start_datetime', '<=', $to->toDateTimeString())
                ->where('end_datetime', '>=', $from->toDateTimeString());
        }

        return $query
            ->limit(100)
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'start_datetime' => optional($event->start_datetime)->toDateTimeString(),
                'end_datetime' => optional($event->end_datetime)->toDateTimeString(),
                'google_event_id' => $this->hasGoogleCalendarColumns() ? $event->google_event_id : null,
            ]);
    }

    private function hasGoogleCalendarColumns(): bool
    {
        return Schema::hasColumn('events', 'google_event_id')
            && Schema::hasColumn('events', 'google_synced_at')
            && Schema::hasColumn('events', 'google_sync_error');
    }

    private function serviceAccountEmail(string $credentialPath): ?string
    {
        if ($credentialPath === '' || !is_file($credentialPath)) {
            return null;
        }

        $credentials = json_decode((string) file_get_contents($credentialPath), true);

        return is_array($credentials) ? ($credentials['client_email'] ?? null) : null;
    }

    private function isVipActive(): bool
    {
        $vip = trim((string) env('HA_VIRTUAL_IP', ''));
        if ($vip === '') {
            return false;
        }

        $output = [];
        @exec('/sbin/ip -o -4 addr show 2>/dev/null', $output);

        foreach ($output as $line) {
            if (strpos($line, ' '.$vip.'/') !== false) {
                return true;
            }
        }

        return false;
    }

    private function writeEnvValues(array $values): void
    {
        $envPath = base_path('.env');
        $content = File::exists($envPath) ? (string) File::get($envPath) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatEnvValue((string) $value);
            putenv($key.'='.(string) $value);
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
                $content = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content);
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        File::put($envPath, $content);
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'/', $value)) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }
}
