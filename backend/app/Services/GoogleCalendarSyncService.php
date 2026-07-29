<?php

namespace App\Services;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarSyncService
{
    public function isEnabled(): bool
    {
        return filter_var(env('GOOGLE_CALENDAR_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return $this->calendarId() !== ''
            && $this->credentialsPath() !== ''
            && is_file($this->credentialsPath());
    }

    public function syncEvent(Event $event, bool $force = false): bool
    {
        if (!$force && !$this->isEnabled()) {
            return false;
        }

        if (!$this->isConfigured()) {
            $this->rememberError($event, 'Google Calendar belum dikonfigurasi lengkap.');
            return false;
        }

        $event->loadMissing(['users:id,nama_lengkap,email', 'creator:id,nama_lengkap,email']);

        try {
            $googleEvent = $this->buildGoogleEvent($event);
            $calendarId = $this->calendarId();

            if ($event->google_event_id) {
                $response = Http::withToken($this->accessToken())
                    ->acceptJson()
                    ->put($this->eventUrl($calendarId, $event->google_event_id), $googleEvent);

                if ($response->successful()) {
                    $this->rememberSuccess($event, (string) data_get($response->json(), 'id'));
                    return true;
                }

                if ($response->status() !== 404) {
                    throw new \RuntimeException($response->body());
                }
            }

            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->post($this->eventsUrl($calendarId), $googleEvent);

            if (!$response->successful()) {
                throw new \RuntimeException($response->body());
            }

            $this->rememberSuccess($event, (string) data_get($response->json(), 'id'));
            return true;
        } catch (\Throwable $error) {
            $this->rememberError($event, $error->getMessage());
            Log::warning('Gagal sinkron event ke Google Calendar.', [
                'event_id' => $event->id,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    public function deleteEvent(Event $event, bool $force = false): bool
    {
        if (!$event->google_event_id) {
            return false;
        }

        if (!$force && !$this->isEnabled()) {
            return false;
        }

        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->delete($this->eventUrl($this->calendarId(), $event->google_event_id));

            return $response->successful() || $response->status() === 404;
        } catch (\Throwable $error) {
            Log::warning('Gagal menghapus event Google Calendar.', [
                'event_id' => $event->id,
                'google_event_id' => $event->google_event_id,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private function buildGoogleEvent(Event $event): array
    {
        $start = $this->eventDateTime($event->start_datetime ?: now());
        $end = $this->eventDateTime($event->end_datetime ?: Carbon::parse($event->start_datetime)->addMinutes(30));

        return [
            'summary' => (string) $event->title,
            'location' => (string) ($event->location ?? ''),
            'description' => $this->description($event),
            'start' => $start,
            'end' => $end,
            'extendedProperties' => [
                'private' => [
                    'simanis_event_id' => (string) $event->id,
                    'simanis_updated_at' => optional($event->updated_at)->toIso8601String(),
                ],
            ],
        ];
    }

    private function eventDateTime($value): array
    {
        $date = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse((string) $value);

        return [
            'dateTime' => $date->timezone($this->timezone())->toRfc3339String(),
            'timeZone' => $this->timezone(),
        ];
    }

    private function description(Event $event): string
    {
        $lines = [];

        if (!empty($event->description)) {
            $lines[] = trim((string) $event->description);
            $lines[] = '';
        }

        $lines[] = 'Sumber: SIMANIS';
        $lines[] = 'ID Event: '.$event->id;

        if ($event->creator) {
            $lines[] = 'Pembuat: '.$event->creator->nama_lengkap;
        }

        if ($event->users && $event->users->isNotEmpty()) {
            $lines[] = 'Peserta: '.$event->users->pluck('nama_lengkap')->filter()->implode(', ');
        }

        return trim(implode("\n", $lines));
    }

    private function rememberSuccess(Event $event, string $googleEventId): void
    {
        $event->forceFill([
            'google_event_id' => $googleEventId,
            'google_synced_at' => now(),
            'google_sync_error' => null,
        ])->save();
    }

    private function rememberError(Event $event, string $message): void
    {
        $event->forceFill([
            'google_sync_error' => mb_substr($message, 0, 1000),
        ])->save();
    }

    private function calendarId(): string
    {
        return trim((string) env('GOOGLE_CALENDAR_ID', config('google-calendar.calendar_id', '')));
    }

    private function credentialsPath(): string
    {
        return trim((string) env(
            'GOOGLE_CALENDAR_CREDENTIALS',
            storage_path('app/google-calendar/service-account-credentials.json')
        ));
    }

    private function timezone(): string
    {
        return trim((string) env('GOOGLE_CALENDAR_TIMEZONE', config('app.timezone', 'Asia/Jakarta')));
    }

    private function eventsUrl(string $calendarId): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'
            .rawurlencode($calendarId)
            .'/events';
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->eventsUrl($calendarId).'/'.rawurlencode($eventId);
    }

    private function accessToken(): string
    {
        return Cache::remember('google_calendar_service_account_token', now()->addMinutes(50), function () {
            $credentials = json_decode((string) file_get_contents($this->credentialsPath()), true);

            if (!is_array($credentials)) {
                throw new \RuntimeException('Credential Google Calendar tidak valid.');
            }

            $clientEmail = (string) ($credentials['client_email'] ?? '');
            $privateKey = (string) ($credentials['private_key'] ?? '');
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');

            if ($clientEmail === '' || $privateKey === '') {
                throw new \RuntimeException('Credential Google Calendar tidak lengkap.');
            }

            $now = time();
            $jwt = $this->jwt([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $privateKey);

            $response = Http::asForm()
                ->timeout(20)
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Gagal mengambil token Google: '.$response->body());
            }

            $token = (string) data_get($response->json(), 'access_token');
            if ($token === '') {
                throw new \RuntimeException('Respons token Google tidak berisi access_token.');
            }

            return $token;
        });
    }

    private function jwt(array $claims, string $privateKey): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];

        $input = implode('.', $segments);
        $signature = '';

        if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Gagal menandatangani JWT Google Calendar.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
