<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\Waha\WahaNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon; // Pastikan Carbon diimpor

class EventController extends Controller
{
    private $wahaNotificationService;

    public function __construct(WahaNotificationService $wahaNotificationService)
    {
        $this->wahaNotificationService = $wahaNotificationService;
    }

    /**
     * Menampilkan daftar semua acara.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $events = Event::with(['users:id,nama_lengkap', 'creator:id,nama_lengkap'])->latest()->get();
        return response()->json(['status' => true, 'message' => 'Berhasil mengambil semua data acara', 'data' => $events], 200);
    }

    /**
     * Menyimpan acara baru dan mengirim notifikasi WhatsApp yang detail.
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        if (!Auth::check()) {
            return response()->json(['status' => false, 'message' => 'Akses ditolak. Anda harus login untuk membuat acara.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'users' => 'nullable|array',
            'users.*' => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
        }

        $userIds = collect((array) $request->input('users', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        [$rangeStart, $rangeEnd] = $this->normalizeScheduleRange(
            (string) $request->input('start_datetime'),
            (string) $request->input('end_datetime')
        );
        $targetUserIds = collect($userIds)
            ->push((int) Auth::id())
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $conflicts = $this->findScheduleConflicts($rangeStart, $rangeEnd, $targetUserIds);
        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'code' => 'schedule_conflict',
                'message' => 'Jadwal bentrok pada rentang waktu yang dipilih. Silakan pilih waktu lain.',
                'data' => $this->buildConflictResponseData($conflicts, $rangeStart, $rangeEnd),
            ], 409);
        }

        $eventData = $request->only(['title', 'description', 'location', 'start_datetime', 'end_datetime', 'color']);
        $eventData['creator_id'] = Auth::id();

        $event = Event::create($eventData);

        if (!empty($userIds)) {
            $event->users()->attach($userIds);
        }

        $event->load(['users:id,nama_lengkap', 'creator:id,nama_lengkap']);

        if (!empty($userIds)) {
            $creatorName = Auth::user()->nama_lengkap;
            $usersToNotify = User::whereIn('id', $userIds)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get();
            $this->wahaNotificationService->notifyEventCreated($event, $usersToNotify, $creatorName);
        }

        return response()->json([
            'status' => true,
            'message' => 'Acara berhasil dibuat dan notifikasi sedang dikirim.',
            'data' => $event
        ], 201);
    }

    /**
     * Menghapus acara dan mengirim notifikasi pembatalan yang detail.
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        if (!Auth::check()) {
            return response()->json(['status' => false, 'message' => 'Akses ditolak. Anda harus login untuk menghapus acara.'], 401);
        }

        $event = Event::with(['users', 'creator'])->find($id);
        if (!$event) {
            return response()->json(['status' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }

        $usersToNotify = $event->users; // Tetap ambil semua user untuk dikirimi notifikasi
        $creatorName = $event->creator->nama_lengkap ?? 'Sistem';
        $deletedByName = Auth::user()->nama_lengkap;

        $event->delete();

        if ($usersToNotify->isNotEmpty()) {
            $this->wahaNotificationService->notifyEventCancelled($event, $usersToNotify, $creatorName, $deletedByName);
        }

        return response()->json([
            'status' => true,
            'message' => 'Acara berhasil dihapus dan notifikasi pembatalan telah dikirim.',
        ], 200);
    }

    /**
     * Menampilkan detail satu acara spesifik.
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id): JsonResponse
    {
        $event = Event::with(['users:id,nama_lengkap', 'creator:id,nama_lengkap'])->find($id);
        if (!$event) {
            return response()->json(['status' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }
        return response()->json(['status' => true, 'message' => 'Detail Acara', 'data' => $event], 200);
    }

    /**
     * Memperbarui data acara yang sudah ada.
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $event = Event::with('users')->find($id);
        if (!$event) { return response()->json(['status' => false, 'message' => 'Acara tidak ditemukan'], 404); }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_datetime' => 'sometimes|required|date',
            'end_datetime' => 'sometimes|required|date|after:start_datetime',
            'users' => 'nullable|array', 'users.*' => 'exists:users,id',
        ]);
        if ($validator->fails()) { return response()->json(['status' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422); }

        $effectiveStart = (string) $request->input('start_datetime', optional($event->start_datetime)->toDateTimeString());
        $effectiveEnd = (string) $request->input('end_datetime', optional($event->end_datetime)->toDateTimeString());
        $participantIds = $request->has('users')
            ? collect((array) $request->input('users', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all()
            : $event->users
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

        [$rangeStart, $rangeEnd] = $this->normalizeScheduleRange($effectiveStart, $effectiveEnd);
        $targetUserIds = collect($participantIds)
            ->push((int) $event->creator_id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $conflicts = $this->findScheduleConflicts($rangeStart, $rangeEnd, $targetUserIds, (int) $event->id);
        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'code' => 'schedule_conflict',
                'message' => 'Jadwal bentrok pada rentang waktu yang dipilih. Silakan pilih waktu lain.',
                'data' => $this->buildConflictResponseData($conflicts, $rangeStart, $rangeEnd),
            ], 409);
        }

        $event->update($request->only(['title', 'description', 'location', 'start_datetime', 'end_datetime', 'color']));
        if ($request->has('users')) { $event->users()->sync($request->users); }

        $event->load(['users:id,nama_lengkap', 'creator:id,nama_lengkap']);
        return response()->json(['status' => true, 'message' => 'Acara berhasil diperbarui', 'data' => $event], 200);
    }

    public function sendReminder($id): JsonResponse
    {
        if (!Auth::check()) {
            return response()->json(['status' => false, 'message' => 'Akses ditolak. Anda harus login.'], 401);
        }

        $event = Event::with([
            'users:id,nama_lengkap,phone,level_user',
            'creator:id,nama_lengkap',
        ])->find($id);

        if (!$event) {
            return response()->json(['status' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }

        $usersToNotify = $event->users->filter(function ($user) {
            return !empty($user->phone);
        })->values();

        if ($usersToNotify->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada peserta dengan nomor WhatsApp valid untuk dikirim reminder.',
            ], 422);
        }

        $senderName = Auth::user()->nama_lengkap ?? 'Sistem';
        $this->wahaNotificationService->notifyEventReminder($event, $usersToNotify, $senderName);

        return response()->json([
            'status' => true,
            'message' => 'Reminder acara sedang dikirim ke WhatsApp peserta.',
        ], 200);
    }

    public function sendBulkReminder(Request $request): JsonResponse
    {
        if (!Auth::check()) {
            return response()->json(['status' => false, 'message' => 'Akses ditolak. Anda harus login.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'timeframe' => 'required|string|in:daily,weekly,monthly',
            'base_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
        }

        $timeframe = (string) $request->input('timeframe', 'daily');
        $baseDate = $request->filled('base_date')
            ? Carbon::parse((string) $request->input('base_date'))->startOfDay()
            : now()->startOfDay();

        if ($timeframe === 'weekly') {
            $rangeStart = $baseDate->copy()->startOfWeek();
            $rangeEnd = $baseDate->copy()->endOfWeek();
            $label = 'mingguan';
        } elseif ($timeframe === 'monthly') {
            $rangeStart = $baseDate->copy()->startOfMonth();
            $rangeEnd = $baseDate->copy()->endOfMonth();
            $label = 'bulanan';
        } else {
            $rangeStart = $baseDate->copy()->startOfDay();
            $rangeEnd = $baseDate->copy()->endOfDay();
            $label = 'harian';
        }

        $events = Event::with([
            'users:id,nama_lengkap,phone,level_user',
            'creator:id,nama_lengkap',
        ])
            ->whereBetween('start_datetime', [$rangeStart, $rangeEnd])
            ->orderBy('start_datetime', 'asc')
            ->get();

        if ($events->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => "Tidak ada agenda pada periode {$label} untuk dikirim reminder.",
            ], 422);
        }

        $senderName = Auth::user()->nama_lengkap ?? 'Sistem';
        $eventsProcessed = 0;
        $recipients = 0;

        foreach ($events as $event) {
            $usersToNotify = $event->users->filter(function ($user) {
                return !empty($user->phone);
            })->values();

            if ($usersToNotify->isEmpty()) {
                continue;
            }

            $this->wahaNotificationService->notifyEventReminder($event, $usersToNotify, $senderName);

            $eventsProcessed++;
            $recipients += $usersToNotify->count();
            $event->reminder_sent_at = now();
            $event->save();
        }

        if ($eventsProcessed === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Agenda ditemukan, tetapi tidak ada peserta dengan nomor WhatsApp valid.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => "Reminder {$label} sedang dikirim untuk {$eventsProcessed} agenda ({$recipients} penerima).",
            'data' => [
                'timeframe' => $timeframe,
                'range_start' => $rangeStart->toDateTimeString(),
                'range_end' => $rangeEnd->toDateTimeString(),
                'events_processed' => $eventsProcessed,
                'recipients' => $recipients,
            ],
        ], 200);
    }


     public function getScheduleForChatbot(Request $request): JsonResponse
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'phone_candidates' => 'nullable|array',
            'phone_candidates.*' => 'string',
            'timeframe' => 'nullable|string|in:today,week,month,range',
            'start_date' => 'nullable|required_if:timeframe,range|date_format:Y-m-d',
            'end_date' => 'nullable|required_if:timeframe,range|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->query('timeframe') === 'range') {
            $requestedStart = Carbon::parse((string) $request->query('start_date'))->startOfDay();
            $requestedEnd = Carbon::parse((string) $request->query('end_date'))->endOfDay();
            if ($requestedStart->diffInDays($requestedEnd) > 366) {
                return response()->json([
                    'error' => 'Rentang tanggal maksimal 366 hari.',
                ], 400);
            }
        }

        $phoneCandidates = array_merge(
            [(string) $request->query('phone', '')],
            (array) $request->query('phone_candidates', [])
        );

        $user = $this->resolveUserByPhoneCandidates($phoneCandidates);
        if (!$user) {
            return response()->json(['status' => 'unregistered']);
        }

        $query = Event::query()
            ->with([
                'creator:id,nama_lengkap',
                'users' => function ($query) {
                    $query->select('users.id', 'nama_lengkap')
                          ->where('level_user', '!=', 'Admin');
                }
            ])
            ->orderBy('start_datetime', 'asc');

        $now = now();
        $timeframe = (string) $request->query('timeframe', 'week');

        switch ($timeframe) {
            case 'today':
                $rangeStart = $now->copy();
                $rangeEnd = $now->copy()->endOfDay();
                break;
            case 'month':
                $rangeStart = $now->copy();
                $rangeEnd = $now->copy()->addMonthNoOverflow();
                break;
            case 'range':
                $requestedStart = Carbon::parse((string) $request->query('start_date'))->startOfDay();
                $requestedEnd = Carbon::parse((string) $request->query('end_date'))->endOfDay();
                $rangeStart = $requestedStart->greaterThan($now) ? $requestedStart : $now->copy();
                $rangeEnd = $requestedEnd;
                break;
            case 'week':
            default:
                $rangeStart = $now->copy();
                $rangeEnd = $now->copy()->addWeek();
                break;
        }

        if ($rangeEnd->lessThan($rangeStart)) {
            $events = collect();
        } else {
            $events = $query
                ->where('start_datetime', '<=', $rangeEnd->toDateTimeString())
                ->where(function ($eventQuery) use ($rangeStart) {
                    $eventQuery
                        ->where('end_datetime', '>=', $rangeStart->toDateTimeString())
                        ->orWhere(function ($withoutEndQuery) use ($rangeStart) {
                            $withoutEndQuery
                                ->whereNull('end_datetime')
                                ->where('start_datetime', '>=', $rangeStart->toDateTimeString());
                        });
                })
                ->get();
        }

        return response()->json([
            'status' => 'registered',
            'user' => ['nama_lengkap' => $user->nama_lengkap],
            'period' => [
                'timeframe' => $timeframe,
                'start' => $rangeStart->toDateTimeString(),
                'end' => $rangeEnd->toDateTimeString(),
            ],
            'events' => $events,
        ]);
    }

    public function getAssistantsForChatbot(Request $request): JsonResponse
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $rows = User::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('nama_lengkap')
            ->get(['id', 'id_user', 'nama_lengkap', 'level_user', 'phone']);

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat daftar asisten chatbot.',
            'data' => $rows,
        ], 200);
    }

    /**
     * Membuat jadwal baru dari permintaan chatbot.
     * Otentikasi menggunakan API Key.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createEventFromChatbot(Request $request): JsonResponse
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'creator_phone' => 'required|string',
            'creator_phone_candidates' => 'nullable|array',
            'creator_phone_candidates.*' => 'string',
            'title' => 'required|string|max:255',
            'start_datetime' => 'required|date',
            'participants' => 'nullable|array',
            'participants.*' => 'exists:users,id',
            'participants_phone_candidates' => 'nullable|array',
            'participants_phone_candidates.*' => 'string',
            'end_datetime' => 'required|date|after:start_datetime',
            'force_conflict' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'status' => 'validation_error', 'errors' => $validator->errors()], 400);
        }

        $creatorPhoneCandidates = array_merge(
            [(string) $request->input('creator_phone', '')],
            (array) $request->input('creator_phone_candidates', [])
        );
        $creator = $this->resolveUserByPhoneCandidates($creatorPhoneCandidates);

        if (!$creator) {
            return response()->json([
                'success' => false,
                'status' => 'unregistered',
                'message' => 'Nomor WhatsApp belum terdaftar sebagai user.',
            ], 404);
        }

        $participantIdsFromPhone = collect((array) $request->input('participants_phone_candidates', []))
            ->map(function ($phone) {
                return trim((string) $phone);
            })
            ->filter()
            ->unique()
            ->map(function ($phone) {
                $user = $this->resolveUserByPhoneCandidates([$phone]);
                return $user ? $user->id : null;
            })
            ->filter()
            ->values()
            ->all();

        $participantIds = collect((array) $request->input('participants', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->merge($participantIdsFromPhone)
            ->push($creator->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $startDateTime = (string) $request->input('start_datetime');
        $endDateTime = (string) $request->input('end_datetime');
        [$rangeStart, $rangeEnd] = $this->normalizeScheduleRange($startDateTime, $endDateTime);
        $targetUserIds = collect($participantIds)
            ->push((int) $creator->id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $conflicts = $this->findScheduleConflicts($rangeStart, $rangeEnd, $targetUserIds);
        if ($conflicts->isNotEmpty() && ! $request->boolean('force_conflict')) {
            return response()->json([
                'success' => false,
                'status' => 'conflict',
                'code' => 'schedule_conflict',
                'message' => 'Jadwal bentrok pada rentang waktu yang dipilih.',
                'data' => $this->buildConflictResponseData($conflicts, $rangeStart, $rangeEnd),
            ], 409);
        }

        $event = Event::create([
            'title' => $request->title,
            'start_datetime' => $request->start_datetime,
            'end_datetime' => $endDateTime,
            'creator_id' => $creator->id,
            'location' => $request->location ?? 'Ditentukan via Chatbot',
            'description' => $request->description ?? 'Dibuat via Chatbot',
        ]);

        if (!empty($participantIds)) {
            $event->users()->sync($participantIds);
        }

        $usersToNotify = User::whereIn('id', $participantIds)
            ->where('id', '!=', $creator->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        if ($usersToNotify->isNotEmpty()) {
            $creatorName = $creator->nama_lengkap;
            $this->wahaNotificationService->notifyEventCreatedFromChatbot($event, $usersToNotify, $creatorName);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event berhasil dibuat',
            'data' => $event
        ]);
    }

    public function deleteEventFromChatbot(Request $request): JsonResponse
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'requester_phone' => 'required|string',
            'requester_phone_candidates' => 'nullable|array',
            'requester_phone_candidates.*' => 'string',
            'event_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 'validation_error',
                'errors' => $validator->errors(),
            ], 400);
        }

        $requesterPhoneCandidates = array_merge(
            [(string) $request->input('requester_phone', '')],
            (array) $request->input('requester_phone_candidates', [])
        );
        $requester = $this->resolveUserByPhoneCandidates($requesterPhoneCandidates);

        if (!$requester) {
            return response()->json([
                'success' => false,
                'status' => 'unregistered',
                'message' => 'Nomor WhatsApp belum terdaftar sebagai user.',
            ], 404);
        }

        $event = Event::with(['users', 'creator'])->find((int) $request->input('event_id'));
        if (!$event) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Jadwal tidak ditemukan.',
            ], 404);
        }

        if ((int) $event->creator_id !== (int) $requester->id) {
            return response()->json([
                'success' => false,
                'status' => 'forbidden',
                'message' => 'Hanya pembuat jadwal yang dapat menghapus jadwal ini.',
            ], 403);
        }

        $usersToNotify = $event->users;
        $creatorName = $event->creator->nama_lengkap ?? $requester->nama_lengkap;
        $deletedByName = $requester->nama_lengkap;

        $event->delete();

        if ($usersToNotify->isNotEmpty()) {
            $this->wahaNotificationService->notifyEventCancelled($event, $usersToNotify, $creatorName, $deletedByName);
        }

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil dihapus.',
        ], 200);
    }

    private function normalizeScheduleRange(string $startDateTime, string $endDateTime): array
    {
        $start = Carbon::parse($startDateTime);
        $end = Carbon::parse($endDateTime);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addMinutes(30);
        }

        return [$start, $end];
    }

    private function findScheduleConflicts(Carbon $rangeStart, Carbon $rangeEnd, array $targetUserIds, ?int $ignoreEventId = null)
    {
        $userIds = collect($targetUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = Event::query()
            ->with([
                'creator:id,nama_lengkap',
                'users:id,nama_lengkap',
            ])
            ->where('start_datetime', '<', $rangeEnd->toDateTimeString())
            ->where('end_datetime', '>', $rangeStart->toDateTimeString())
            ->where(function ($eventQuery) use ($userIds) {
                $eventQuery
                    ->whereIn('creator_id', $userIds->all())
                    ->orWhereHas('users', function ($userQuery) use ($userIds) {
                        $userQuery->whereIn('users.id', $userIds->all());
                    });
            })
            ->orderBy('start_datetime', 'asc');

        if ($ignoreEventId) {
            $query->where('id', '!=', $ignoreEventId);
        }

        return $query->get();
    }

    private function buildConflictResponseData($conflicts, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = $conflicts->map(function ($event) {
            return [
                'id' => $event->id,
                'title' => $event->title,
                'location' => $event->location,
                'start_datetime' => optional($event->start_datetime)->toDateTimeString(),
                'end_datetime' => optional($event->end_datetime)->toDateTimeString(),
                'creator' => $event->creator
                    ? [
                        'id' => $event->creator->id,
                        'nama_lengkap' => $event->creator->nama_lengkap,
                    ]
                    : null,
                'users' => $event->users
                    ->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'nama_lengkap' => $user->nama_lengkap,
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        return [
            'range_start' => $rangeStart->toDateTimeString(),
            'range_end' => $rangeEnd->toDateTimeString(),
            'conflicts' => $rows,
        ];
    }

    private function resolveUserByPhoneCandidates(array $candidates): ?User
    {
        $normalized = collect($candidates)
            ->flatMap(function ($phone) {
                return $this->phoneVariants((string) $phone);
            })
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return null;
        }

        $user = User::query()
            ->where(function ($query) use ($normalized) {
                foreach ($normalized as $phone) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();

        if ($user) {
            return $user;
        }

        $digitCandidates = $normalized
            ->map(function ($phone) {
                return preg_replace('/\D+/', '', (string) $phone);
            })
            ->filter()
            ->unique()
            ->values();

        if ($digitCandidates->isEmpty()) {
            return null;
        }

        return User::query()
            ->whereNotNull('phone')
            ->get()
            ->first(function ($row) use ($digitCandidates) {
                $phoneDigits = preg_replace('/\D+/', '', (string) $row->phone);
                return !empty($phoneDigits) && $digitCandidates->contains($phoneDigits);
            });
    }

    private function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) {
            return [];
        }

        $variants = [$digits];

        if (strpos($digits, '62') === 0) {
            $local = '0' . substr($digits, 2);
            if (strlen($local) > 1) {
                $variants[] = $local;
                $variants[] = '+62' . substr($digits, 2);
            }
        } elseif (strpos($digits, '0') === 0) {
            $intl = '62' . substr($digits, 1);
            $variants[] = $intl;
            $variants[] = '+' . $intl;
        }

        return array_values(array_unique(array_filter($variants)));
    }
}
