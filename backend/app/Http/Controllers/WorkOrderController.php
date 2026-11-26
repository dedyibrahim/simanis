<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\GoogleCalendarSyncService;
use App\Services\OrderService;
use App\Services\Waha\WorkOrderNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    private const TYPES = ['notaris', 'ppat', 'legalisasi', 'waarmerking'];
    private const BOOKS = [
        'notaris' => ['buku_notaris', 'id_buku_notaris', 'no_akta', 'tgl_akta', 'status_akta'],
        'ppat' => ['buku_ppats', 'id_buku_ppat', 'no_akta', 'tanggal_akta', 'status_akta'],
        'legalisasi' => ['buku_legalisasis', 'id_buku_legalisasi', 'no_legalisasi', 'tgl_surat', 'status_legalisasi'],
        'waarmerking' => ['buku_warmerkings', 'id_buku_warmerking', 'no_warmerking', 'tgl_didaftarkan', 'status_warmerking'],
    ];

    public function actTypes(string $type): JsonResponse
    {
        abort_unless(in_array($type, ['notaris', 'ppat'], true), 404);
        $rows = DB::table('daftar_aktas')
            ->where('pekerjaan_milik', strtoupper($type))
            ->orderBy('nama_akta')
            ->get(['id_akta', 'nama_akta']);
        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $orders = DB::table('orders')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('work_order_items')
                ->whereColumn('work_order_items.id_order', 'orders.id_order'))
            ->whereYear('orders.created_at', substr($month, 0, 4))
            ->whereMonth('orders.created_at', substr($month, 5, 2))
            ->orderByDesc('orders.created_at')
            ->get(['orders.id_order', 'orders.nama_pesanan', 'orders.keterangan_order', 'orders.id_user', 'orders.status_order', 'orders.created_at']);

        $orderIds = $orders->pluck('id_order');
        $items = DB::table('work_order_items')
            ->leftJoin('users', 'users.id_user', '=', 'work_order_items.id_user')
            ->leftJoin('events', 'events.id', '=', 'work_order_items.event_id')
            ->whereIn('work_order_items.id_order', $orderIds)
            ->orderBy('work_order_items.id')
            ->get(['work_order_items.*', 'users.nama_lengkap as assignee_name',
                'events.start_datetime as scheduled_start', 'events.end_datetime as scheduled_end', 'events.location as scheduled_location']);
        $participants = DB::table('work_order_participants as p')
            ->join('data_clients as c', 'c.id_client', '=', 'p.id_client')
            ->leftJoin('data_clients as m', 'm.id_client', '=', 'p.id_mewakili')
            ->whereIn('p.work_order_item_id', $items->pluck('id'))
            ->get(['p.work_order_item_id', 'p.id_client', 'c.nama_client', 'p.status_kedudukan', 'p.id_mewakili', 'm.nama_client as mewakili'])
            ->groupBy('work_order_item_id');
        $itemsByOrder = $items->map(function ($item) use ($participants) {
            $item->details = json_decode((string) $item->details, true) ?: [];
            $item->penghadap = ($participants->get($item->id) ?: collect())->values();
            return $item;
        })->groupBy('id_order');

        return response()->json(['status' => true, 'data' => $orders->map(function ($order) use ($itemsByOrder) {
            $order->jobs = ($itemsByOrder->get($order->id_order) ?: collect())->values();
            return $order;
        })]);
    }

    public function show(int $id): JsonResponse
    {
        $item = DB::table('work_order_items')->where('id', $id)->first();
        abort_unless($item, 404);
        $item->details = json_decode((string) $item->details, true) ?: [];
        $item->penghadap = DB::table('work_order_participants as p')
            ->join('data_clients as c', 'c.id_client', '=', 'p.id_client')
            ->leftJoin('data_clients as m', 'm.id_client', '=', 'p.id_mewakili')
            ->where('p.work_order_item_id', $id)
            ->get(['p.id_client', 'c.nama_client', 'p.status_kedudukan', 'p.id_mewakili', 'm.nama_client as mewakili']);
        return response()->json(['status' => true, 'data' => $item]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWritable($request);
        $data = $request->validate([
            'nama_pesanan' => ['required', 'string', 'max:255'],
            'id_user' => ['required', 'exists:users,id_user'],
            'keterangan_order' => ['nullable', 'string'],
            'jobs' => ['required', 'array', 'min:1'],
            'jobs.*.type' => ['required', Rule::in(self::TYPES)],
            'jobs.*.title' => ['required', 'string', 'max:255'],
            'jobs.*.id_user' => ['required', 'exists:users,id_user'],
            'jobs.*.details' => ['nullable', 'array'],
            'jobs.*.penghadap' => ['required', 'array', 'min:1'],
            'jobs.*.penghadap.*.id_client' => ['required', 'exists:data_clients,id_client'],
            'jobs.*.penghadap.*.status_kedudukan' => ['required', 'string', 'max:100'],
            'jobs.*.penghadap.*.id_mewakili' => ['nullable', 'exists:data_clients,id_client'],
        ]);

        foreach ($data['jobs'] as $index => $job) {
            $this->validateJobDetails($job, 'jobs.'.$index.'.');
        }

        $orderId = DB::transaction(function () use ($data, $request) {
            $latest = DB::table('orders')->lockForUpdate()->orderByDesc('id_order')->value('id_order');
            $orderId = 'ORD'.str_pad((string) ((int) substr((string) $latest, 3) + 1), 7, '0', STR_PAD_LEFT);
            DB::table('orders')->insert([
                'id_order' => $orderId,
                'nama_pesanan' => $data['nama_pesanan'],
                'id_user' => $data['id_user'],
                'keterangan_order' => $data['keterangan_order'] ?? '',
                'status_order' => 'Proses',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($data['jobs'] as $job) {
                $this->insertJob($orderId, $job, (string) $request->user()->id_user);
            }
            return $orderId;
        });

        return response()->json(['status' => true, 'message' => 'Pekerjaan berhasil dibuat.', 'data' => ['id_order' => $orderId]], 201);
    }

    public function addJob(Request $request, string $orderId): JsonResponse
    {
        $this->assertWritable($request);
        $data = $this->validateJob($request);
        DB::transaction(function () use ($orderId, $data, $request) {
            $order = DB::table('orders')->where('id_order', $orderId)->lockForUpdate()->first();
            abort_unless($order, 404);
            $this->assertOrderAccess($request, $order);
            if ($order->status_order !== 'Proses') {
                throw ValidationException::withMessages(['id_order' => 'Pesanan ini sudah ditutup.']);
            }
            $this->insertJob($orderId, $data, (string) $request->user()->id_user);
        });
        return response()->json(['status' => true, 'message' => 'Pekerjaan ditambahkan.'], 201);
    }

    public function destroy(Request $request, int $id, GoogleCalendarSyncService $calendar): JsonResponse
    {
        $this->assertWritable($request);
        DB::transaction(function () use ($request, $id, $calendar) {
            $reference = DB::table('work_order_items')->where('id', $id)->first(['id_order']);
            abort_unless($reference, 404);
            $order = DB::table('orders')->where('id_order', $reference->id_order)->lockForUpdate()->first();
            $this->assertOrderAccess($request, $order);
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            if ($order->status_order !== 'Proses' || $item->book_id || !in_array($item->status, ['draft', 'scheduled', 'signed'], true)) {
                throw ValidationException::withMessages(['status' => 'Pekerjaan yang sudah bernomor atau pesanan selesai tidak dapat dihapus.']);
            }
            $lastItem = !DB::table('work_order_items')->where('id_order', $item->id_order)->where('id', '!=', $id)->exists();
            if ($lastItem) {
                foreach (['detail_pesanans', 'invoice_taxs', 'invoice_non_taxs', 'dokumen_orders'] as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->where('id_order', $item->id_order)->exists()) {
                        throw ValidationException::withMessages(['status' => 'Pesanan ini masih memiliki detail, invoice, atau dokumen terkait. Pekerjaan terakhir tidak dapat dihapus.']);
                    }
                }
                if (!empty($order->no_inv)) {
                    throw ValidationException::withMessages(['status' => 'Pesanan ini sudah memiliki invoice. Pekerjaan terakhir tidak dapat dihapus.']);
                }
            }
            $event = $item->event_id ? Event::whereKey($item->event_id)->lockForUpdate()->first() : null;
            if ($event && $event->google_event_id && !$calendar->deleteEvent($event)) {
                throw ValidationException::withMessages(['schedule' => 'Jadwal Google Calendar belum berhasil dihapus. Pekerjaan tetap disimpan; coba lagi setelah sinkronisasi tersedia.']);
            }
            $this->notifyStage($id, 'Pekerjaan dihapus', $request);
            DB::table('work_order_participants')->where('work_order_item_id', $id)->delete();
            DB::table('work_order_items')->where('id', $id)->delete();
            if ($event) { $event->users()->detach(); $event->delete(); }
            if ($lastItem) DB::table('orders')->where('id_order', $item->id_order)->delete();
        });
        return response()->json(['status' => true, 'message' => 'Pekerjaan dan jadwal terkait berhasil dihapus.']);
    }

    public function updateJob(Request $request, int $id): JsonResponse
    {
        $this->assertWritable($request);
        $data = $this->validateJob($request);
        DB::transaction(function () use ($id, $data, $request) {
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            $this->assertOrderAccess($request, DB::table('orders')->where('id_order', $item->id_order)->first());
            if ($item->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Ubah pekerjaan sebelum membuat jadwal TTD.']);
            }
            if ($item->type !== $data['type']) {
                throw ValidationException::withMessages(['type' => 'Jenis pekerjaan tidak dapat diubah.']);
            }
            DB::table('work_order_items')->where('id', $id)->update([
                'title' => $data['title'],
                'id_user' => $data['id_user'],
                'details' => json_encode($data['details'] ?? []),
                'updated_at' => now(),
            ]);
            DB::table('work_order_participants')->where('work_order_item_id', $id)->delete();
            $this->insertParticipants($id, $data['penghadap']);
            $this->notifyStage($id, 'Draf diperbarui', $request);
        });
        return response()->json(['status' => true, 'message' => 'Pekerjaan diperbarui.']);
    }

    public function schedule(Request $request, int $id, GoogleCalendarSyncService $calendar): JsonResponse
    {
        $this->assertWritable($request);
        $data = $request->validate([
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);
        $data['start_datetime'] = Carbon::parse($data['start_datetime'])->format('Y-m-d H:i:s');
        $data['end_datetime'] = Carbon::parse($data['end_datetime'])->format('Y-m-d H:i:s');
        $event = DB::transaction(function () use ($id, $data, $request) {
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            $this->assertOrderAccess($request, DB::table('orders')->where('id_order', $item->id_order)->first());
            if (!in_array($item->status, ['draft', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Jadwal tidak dapat diubah setelah TTD.']);
            }
            $assignee = DB::table('users')->where('id_user', $item->id_user)->first(['id']);
            $conflict = DB::table('events')
                ->join('event_user', 'event_user.event_id', '=', 'events.id')
                ->where('event_user.user_id', $assignee->id)
                ->when($item->event_id, fn ($query) => $query->where('events.id', '!=', $item->event_id))
                ->where('events.start_datetime', '<', $data['end_datetime'])
                ->where('events.end_datetime', '>', $data['start_datetime'])
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['start_datetime' => 'Jadwal penanggung jawab bentrok.']);
            }
            $event = $item->event_id ? Event::find($item->event_id) : null;
            $payload = [
                'title' => 'TTD '.$item->title,
                'description' => 'Pekerjaan '.$item->id_order.' / '.$item->type,
                'location' => $data['location'] ?? null,
                'start_datetime' => $data['start_datetime'],
                'end_datetime' => $data['end_datetime'],
                'creator_id' => $request->user()->id,
            ];
            if ($event) {
                $event->update($payload);
            } else {
                $event = Event::create($payload);
            }
            $event->users()->sync($assignee ? [$assignee->id] : []);
            DB::table('work_order_items')->where('id', $id)->update([
                'event_id' => $event->id,
                'status' => 'scheduled',
                'updated_at' => now(),
            ]);
            $this->notifyStage($id, $item->status === 'scheduled' ? 'Jadwal TTD diubah' : 'TTD dijadwalkan', $request);
            return $event;
        });
        $calendar->syncEvent($event);
        return response()->json(['status' => true, 'message' => 'Jadwal TTD tersimpan di kalender.', 'data' => ['event_id' => $event->id]]);
    }

    public function cancelSchedule(Request $request, int $id, GoogleCalendarSyncService $calendar): JsonResponse
    {
        $this->assertWritable($request);
        DB::transaction(function () use ($request, $id, $calendar) {
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            $this->assertOrderAccess($request, DB::table('orders')->where('id_order', $item->id_order)->first());
            if ($item->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Hanya jadwal sebelum TTD yang dapat dibatalkan.']);
            }
            $event = $item->event_id ? Event::whereKey($item->event_id)->lockForUpdate()->first() : null;
            if ($event && $event->google_event_id && !$calendar->deleteEvent($event)) {
                throw ValidationException::withMessages(['schedule' => 'Jadwal Google Calendar belum berhasil dihapus. Coba lagi setelah sinkronisasi tersedia.']);
            }
            DB::table('work_order_items')->where('id', $id)->update([
                'status' => 'draft', 'event_id' => null, 'updated_at' => now(),
            ]);
            $this->notifyStage($id, 'Jadwal TTD dibatalkan', $request);
            if ($event) {
                $event->users()->detach();
                $event->delete();
            }
        });
        return response()->json(['status' => true, 'message' => 'Jadwal TTD dibatalkan. Pekerjaan dapat diedit dan dijadwalkan ulang.']);
    }

    public function confirmSigning(Request $request, int $id): JsonResponse
    {
        $this->assertWritable($request);
        DB::transaction(function () use ($request, $id) {
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            $admin = in_array(strtoupper(trim((string) $request->user()->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
            abort_unless($admin || $item->id_user === $request->user()->id_user, 403);
            if ($item->status !== 'scheduled' || !$item->event_id || !Event::whereKey($item->event_id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Jadwalkan TTD sebelum mengonfirmasi.']);
            }
            DB::table('work_order_items')->where('id', $id)->update(['status' => 'signed', 'signed_at' => now(), 'updated_at' => now()]);
            $this->notifyStage($id, 'TTD dikonfirmasi — siap ambil nomor', $request);
        });
        return response()->json(['status' => true, 'message' => 'TTD dikonfirmasi. Nomor dapat diambil.']);
    }

    public function takeNumber(Request $request, int $id): JsonResponse
    {
        $this->assertWritable($request);
        return DB::transaction(function () use ($request, $id) {
            $item = DB::table('work_order_items')->where('id', $id)->lockForUpdate()->first();
            abort_unless($item, 404);
            $bookData = (array) $request->input('data_buku', []);
            foreach (['id_buku_notaris', 'id_buku_legalisasi', 'id_buku_warmerking'] as $key) {
                if (array_key_exists($key, $bookData)) {
                    throw ValidationException::withMessages(['number' => 'Ambil nomor hanya dapat membuat nomor baru.']);
                }
            }
            if (array_key_exists('id_buku_ppat', $request->all())) {
                throw ValidationException::withMessages(['number' => 'Ambil nomor hanya dapat membuat nomor baru.']);
            }
            $request->merge(['work_item_id' => $id]);
            $request->attributes->set('work_order_assignee', $item->id_user);
            self::assertNumberRequest($request, $item->type);
            $controller = app(PembuatanNomor::class);
            $controller->workOrderNotification = true;
            $response = match ($item->type) {
                'notaris' => $controller->SimpanNomorNotaris($request),
                'ppat' => $controller->SimpanNomorPPAT($request),
                'legalisasi' => $controller->SimpanNomorLegalisasi($request),
                'waarmerking' => $controller->SimpanNomorWarmerking($request),
            };
            $result = $response->getData(true);
            if ($response->getStatusCode() >= 400 || !($result['status'] ?? false)) {
                throw ValidationException::withMessages(['number' => $result['message'] ?? 'Gagal mengambil nomor.']);
            }
            $book = $result['data'] ?? [];
            if (empty($book['id']) || !isset($book['number'])) {
                throw ValidationException::withMessages(['number' => 'Nomor tidak dapat ditautkan ke pekerjaan.']);
            }
            self::attachNumber($item, (string) $book['id'], (string) $book['number']);
            $this->notifyStage($id, 'Nomor berhasil diambil', $request);
            return $response;
        });
    }

    public function complete(Request $request, string $orderId, OrderService $service): JsonResponse
    {
        $this->assertWritable($request);
        DB::transaction(function () use ($request, $orderId, $service) {
            $order = DB::table('orders')->where('id_order', $orderId)->lockForUpdate()->first();
            abort_unless($order, 404);
            $admin = in_array(strtoupper(trim((string) $request->user()->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
            abort_unless($admin || $order->id_user === $request->user()->id_user, 403);
            if ($order->status_order !== 'Proses') {
                throw ValidationException::withMessages(['status' => 'Pesanan ini sudah ditutup.']);
            }
            $items = DB::table('work_order_items')
                ->join('users', 'users.id_user', '=', 'work_order_items.id_user')
                ->where('work_order_items.id_order', $orderId)
                ->get(['work_order_items.*', 'users.nama_lengkap']);
            if ($items->isEmpty() || $items->contains(fn ($item) => $item->status !== 'numbered')) {
                throw ValidationException::withMessages(['status' => 'Semua pekerjaan harus bernomor sebelum pesanan diselesaikan.']);
            }
            $prices = DB::table('detail_pesanans')->where('id_order', $orderId)->pluck('harga', 'id_pekerjaan');
            $records = [];
            foreach ($items as $item) {
                [$table, $idColumn, $numberColumn, $dateColumn, $statusColumn] = self::BOOKS[$item->type];
                $book = DB::table($table)->where($idColumn, $item->book_id)->lockForUpdate()->first();
                if (!$book) {
                    throw ValidationException::withMessages(['number' => 'Nomor buku terkait pekerjaan tidak ditemukan.']);
                }
                $records[] = [
                    'id_pekerjaan' => $item->book_id,
                    'jenis_pekerjaan' => $item->type,
                    'nama_pekerjaan' => $item->title,
                    'no_pekerjaan' => $book->{$numberColumn},
                    'tanggal_pekerjaan' => $book->{$dateColumn},
                    'pembuat' => $item->nama_lengkap,
                    'harga' => $prices->get($item->book_id, 0),
                ];
                DB::table($table)->where($idColumn, $item->book_id)->update([$statusColumn => 'Selesai', 'updated_at' => now()]);
            }
            $service->simpanDetailOrder($records, ['id_order' => $orderId], null, true);
            foreach ($items as $item) $this->notifyStage($item->id, 'Pesanan selesai — siap invoice', $request);
        });
        return response()->json(['status' => true, 'message' => 'Pesanan selesai dan siap diproses invoice.']);
    }

    public static function assertNumberRequest(Request $request, string $type): ?object
    {
        if (!$request->filled('work_item_id')) {
            return null;
        }
        $item = DB::table('work_order_items')->where('id', $request->input('work_item_id'))->first();
        if (!$item || $item->type !== $type || $item->status !== 'signed' || $item->book_id) {
            throw ValidationException::withMessages(['work_item_id' => 'Pekerjaan tidak siap untuk mengambil nomor.']);
        }
        $admin = in_array(strtoupper(trim((string) $request->user()->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
        abort_unless($admin || $item->id_user === $request->user()->id_user, 403);
        $expected = DB::table('work_order_participants')->where('work_order_item_id', $item->id)
            ->get(['id_client', 'status_kedudukan', 'id_mewakili'])
            ->map(fn ($row) => [$row->id_client, $row->status_kedudukan, $row->id_mewakili ?: ''])->sort()->values()->all();
        $actual = collect((array) $request->input('penghadap', []))
            ->map(fn ($row) => [(string) ($row['id_client'] ?? ''), (string) ($row['status_kedudukan'] ?? ''), (string) ($row['id_mewakili'] ?? '')])
            ->sort()->values()->all();
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['penghadap' => 'Data penghadap berbeda dari pekerjaan. Perbarui pekerjaan terlebih dahulu.']);
        }
        return $item;
    }

    public static function attachNumber(?object $item, string $bookId, string $number): void
    {
        if (!$item) {
            return;
        }
        $updated = DB::table('work_order_items')->where('id', $item->id)->where('status', 'signed')
            ->whereNull('book_id')->update(['status' => 'numbered', 'book_id' => $bookId, 'book_number' => $number, 'updated_at' => now()]);
        if (!$updated) {
            throw ValidationException::withMessages(['work_item_id' => 'Nomor untuk pekerjaan ini sudah diambil.']);
        }
    }

    public static function releaseNumber(string $bookId): void
    {
        $item = DB::table('work_order_items')->where('book_id', $bookId)->lockForUpdate()->first();
        if (!$item) {
            return;
        }
        $status = DB::table('orders')->where('id_order', $item->id_order)->lockForUpdate()->value('status_order');
        if ($status !== 'Proses') {
            throw ValidationException::withMessages(['book_id' => 'Nomor terkait pesanan selesai. Batalkan invoice/pesanan sebelum menghapus nomor.']);
        }
        DB::table('work_order_items')->where('id', $item->id)->update([
            'status' => 'signed',
            'book_id' => null,
            'book_number' => null,
            'updated_at' => now(),
        ]);
        app(WorkOrderNotificationService::class)->stage($item->id, 'Nomor dihapus — kembali siap ambil nomor', auth()->user());
    }

    private function notifyStage(int $id, string $stage, Request $request): void
    {
        app(WorkOrderNotificationService::class)->stage($id, $stage, $request->user());
    }

    private function validateJob(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'id_user' => ['required', 'exists:users,id_user'],
            'details' => ['nullable', 'array'],
            'penghadap' => ['required', 'array', 'min:1'],
            'penghadap.*.id_client' => ['required', 'exists:data_clients,id_client'],
            'penghadap.*.status_kedudukan' => ['required', 'string', 'max:100'],
            'penghadap.*.id_mewakili' => ['nullable', 'exists:data_clients,id_client'],
        ]);
        $this->validateJobDetails($data);
        return $data;
    }

    private function validateJobDetails(array $job, string $prefix = ''): void
    {
        $rules = [];
        if (in_array($job['type'], ['notaris', 'ppat'], true)) {
            $rules['details.jenis_akta'] = ['required', Rule::exists('daftar_aktas', 'id_akta')->where('pekerjaan_milik', strtoupper($job['type']))];
        }
        foreach (['luas_tanah', 'luas_bangunan', 'harga_transaksi', 'harga_njop', 'harga_bphtb', 'harga_pph'] as $field) {
            $rules['details.'.$field] = ['nullable', 'numeric', 'min:0'];
        }
        foreach (['tgl_bphtb', 'tgl_pph'] as $field) {
            $rules['details.'.$field] = ['nullable', 'date_format:Y-m-d'];
        }
        $validator = Validator::make($job, $rules);
        $errors = [];
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) $errors[$prefix.$key] = $messages;
        }
        $seen = [];
        foreach ($job['penghadap'] as $index => $person) {
            if (!empty($person['id_mewakili']) && (string) $person['id_client'] === (string) $person['id_mewakili']) {
                $errors[$prefix.'penghadap.'.$index.'.id_mewakili'] = ['Penghadap tidak dapat mewakili dirinya sendiri.'];
            }
            $key = json_encode([(string) $person['id_client'], trim($person['status_kedudukan']), (string) ($person['id_mewakili'] ?? '')]);
            if (isset($seen[$key])) $errors[$prefix.'penghadap.'.$index.'.id_client'] = ['Penghadap dengan kedudukan ini sudah ditambahkan.'];
            $seen[$key] = true;
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function assertWritable(Request $request): void
    {
        abort_if(strtoupper(trim((string) $request->user()->level_user)) === 'ARSIP', 403);
    }

    private function assertOrderAccess(Request $request, ?object $order): void
    {
        abort_unless($order, 404);
        $admin = in_array(strtoupper(trim((string) $request->user()->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
        abort_unless($admin || $order->id_user === $request->user()->id_user, 403);
    }

    private function insertJob(string $orderId, array $job, string $creator): void
    {
        $id = DB::table('work_order_items')->insertGetId([
            'id_order' => $orderId,
            'type' => $job['type'],
            'title' => $job['title'],
            'id_user' => $job['id_user'],
            'created_by' => $creator,
            'details' => json_encode($job['details'] ?? []),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertParticipants($id, $job['penghadap']);
        app(WorkOrderNotificationService::class)->stage($id, 'Draf pekerjaan dibuat', DB::table('users')->where('id_user', $creator)->first());
    }

    private function insertParticipants(int $id, array $participants): void
    {
        foreach ($participants as $participant) {
            DB::table('work_order_participants')->insert([
                'work_order_item_id' => $id,
                'id_client' => $participant['id_client'],
                'status_kedudukan' => $participant['status_kedudukan'],
                'id_mewakili' => $participant['id_mewakili'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
