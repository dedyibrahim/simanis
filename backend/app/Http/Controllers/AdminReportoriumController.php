<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminReportoriumController extends Controller
{
    private const MODULES = [
        '/buku_akta',
        '/buku_legalisasi',
        '/buku_waarmerking',
        '/buku_ppat',
        '/buku_surat_notaris',
        '/buku_surat_ppat',
        '/tanda_terima',
        '/tanda_terima_masuk',
    ];

    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));
        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function moduleLabel(string $modulePath): string
    {
        return match ($modulePath) {
            '/buku_akta' => 'Buku Akta',
            '/buku_legalisasi' => 'Buku Legalisasi',
            '/buku_waarmerking' => 'Buku Waarmerking',
            '/buku_ppat' => 'Buku PPAT',
            '/buku_surat_notaris' => 'Surat Notaris',
            '/buku_surat_ppat' => 'Surat PPAT',
            '/tanda_terima' => 'Tanda Terima Keluar',
            '/tanda_terima_masuk' => 'Tanda Terima Masuk',
            default => $modulePath,
        };
    }

    private function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) {
            return [];
        }

        $variants = [$digits];
        if (strpos($digits, '62') === 0) {
            $local = '0'.substr($digits, 2);
            if (strlen($local) > 1) {
                $variants[] = $local;
                $variants[] = '+62'.substr($digits, 2);
            }
        } elseif (strpos($digits, '0') === 0) {
            $intl = '62'.substr($digits, 1);
            $variants[] = $intl;
            $variants[] = '+'.$intl;
        }

        return array_values(array_unique(array_filter($variants)));
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

    private function denyIfNotAdmin(Request $request)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        if (!$this->isAdminOrSuper($authUser)) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Fitur ini khusus Admin/Super Admin.',
                'data' => [],
            ], 403);
        }

        return null;
    }

    private function applyMonthFilter($query, string $dateColumn, ?string $month): void
    {
        if (!$month) {
            return;
        }

        $parts = explode('-', $month);
        if (count($parts) !== 2) {
            return;
        }

        $year = (int) $parts[0];
        $monthNumber = (int) $parts[1];
        if ($year <= 0 || $monthNumber < 1 || $monthNumber > 12) {
            return;
        }

        $query->whereYear($dateColumn, $year)->whereMonth($dateColumn, $monthNumber);
    }

    private function collectRows(?string $month): Collection
    {
        $items = collect();

        $notaris = DB::table('buku_notaris')
            ->leftJoin('users', 'users.id_user', '=', 'buku_notaris.id_user')
            ->selectRaw("'/buku_akta' as module_path")
            ->selectRaw("buku_notaris.id_buku_notaris as record_id")
            ->selectRaw("buku_notaris.no_akta as nomor")
            ->selectRaw("buku_notaris.judul_pekerjaan as judul")
            ->selectRaw("buku_notaris.id_user as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_notaris.tgl_akta as tanggal")
            ->selectRaw("buku_notaris.created_at as created_at");
        $this->applyMonthFilter($notaris, 'buku_notaris.tgl_akta', $month);
        $items = $items->merge($notaris->get());

        $ppat = DB::table('buku_ppats')
            ->leftJoin('users', 'users.id_user', '=', 'buku_ppats.id_user')
            ->leftJoin('daftar_aktas', 'daftar_aktas.id_akta', '=', 'buku_ppats.id_akta')
            ->selectRaw("'/buku_ppat' as module_path")
            ->selectRaw("buku_ppats.id_buku_ppat as record_id")
            ->selectRaw("buku_ppats.no_akta as nomor")
            ->selectRaw("daftar_aktas.nama_akta as judul")
            ->selectRaw("buku_ppats.id_user as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_ppats.tanggal_akta as tanggal")
            ->selectRaw("buku_ppats.created_at as created_at");
        $this->applyMonthFilter($ppat, 'buku_ppats.tanggal_akta', $month);
        $items = $items->merge($ppat->get());

        $legalisasi = DB::table('buku_legalisasis')
            ->leftJoin('users', 'users.id_user', '=', 'buku_legalisasis.id_user')
            ->selectRaw("'/buku_legalisasi' as module_path")
            ->selectRaw("buku_legalisasis.id_buku_legalisasi as record_id")
            ->selectRaw("buku_legalisasis.no_legalisasi as nomor")
            ->selectRaw("buku_legalisasis.judul_surat as judul")
            ->selectRaw("buku_legalisasis.id_user as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_legalisasis.tgl_surat as tanggal")
            ->selectRaw("buku_legalisasis.created_at as created_at");
        $this->applyMonthFilter($legalisasi, 'buku_legalisasis.tgl_surat', $month);
        $items = $items->merge($legalisasi->get());

        $warmerking = DB::table('buku_warmerkings')
            ->leftJoin('users', 'users.id_user', '=', 'buku_warmerkings.id_user')
            ->selectRaw("'/buku_waarmerking' as module_path")
            ->selectRaw("buku_warmerkings.id_buku_warmerking as record_id")
            ->selectRaw("buku_warmerkings.no_warmerking as nomor")
            ->selectRaw("buku_warmerkings.judul_surat as judul")
            ->selectRaw("buku_warmerkings.id_user as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_warmerkings.tgl_didaftarkan as tanggal")
            ->selectRaw("buku_warmerkings.created_at as created_at");
        $this->applyMonthFilter($warmerking, 'buku_warmerkings.tgl_didaftarkan', $month);
        $items = $items->merge($warmerking->get());

        $suratNotaris = DB::table('buku_surat_notaris')
            ->leftJoin('users', 'users.id_user', '=', 'buku_surat_notaris.pengirim')
            ->leftJoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_notaris.id_client')
            ->selectRaw("'/buku_surat_notaris' as module_path")
            ->selectRaw("buku_surat_notaris.id_surat_notaris as record_id")
            ->selectRaw("buku_surat_notaris.no_surat as nomor")
            ->selectRaw("COALESCE(data_clients.nama_client, buku_surat_notaris.keterangan) as judul")
            ->selectRaw("buku_surat_notaris.pengirim as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_surat_notaris.created_at as tanggal")
            ->selectRaw("buku_surat_notaris.created_at as created_at");
        $this->applyMonthFilter($suratNotaris, 'buku_surat_notaris.created_at', $month);
        $items = $items->merge($suratNotaris->get());

        $suratPpat = DB::table('buku_surat_ppats')
            ->leftJoin('users', 'users.id_user', '=', 'buku_surat_ppats.pengirim')
            ->leftJoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_ppats.id_client')
            ->selectRaw("'/buku_surat_ppat' as module_path")
            ->selectRaw("buku_surat_ppats.id_surat_ppat as record_id")
            ->selectRaw("buku_surat_ppats.no_surat as nomor")
            ->selectRaw("COALESCE(data_clients.nama_client, buku_surat_ppats.keterangan) as judul")
            ->selectRaw("buku_surat_ppats.pengirim as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("buku_surat_ppats.created_at as tanggal")
            ->selectRaw("buku_surat_ppats.created_at as created_at");
        $this->applyMonthFilter($suratPpat, 'buku_surat_ppats.created_at', $month);
        $items = $items->merge($suratPpat->get());

        $tandaTerima = DB::table('tanda_terima')
            ->leftJoin('users', 'users.id_user', '=', 'tanda_terima.pembuat')
            ->selectRaw("CASE WHEN LOWER(COALESCE(tanda_terima.status, '')) = 'masuk' THEN '/tanda_terima_masuk' ELSE '/tanda_terima' END as module_path")
            ->selectRaw("tanda_terima.id as record_id")
            ->selectRaw("tanda_terima.nomor_tanda_terima as nomor")
            ->selectRaw("tanda_terima.keterangan_tanda_terima as judul")
            ->selectRaw("tanda_terima.pembuat as assignee_id")
            ->selectRaw("users.nama_lengkap as assignee_name")
            ->selectRaw("tanda_terima.created_at as tanggal")
            ->selectRaw("tanda_terima.created_at as created_at");
        $this->applyMonthFilter($tandaTerima, 'tanda_terima.created_at', $month);
        $items = $items->merge($tandaTerima->get());

        return $items;
    }

    public function reportoriumJobs(Request $request)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        $isAdmin = $this->isAdminOrSuper($authUser);

        $validated = $request->validate([
            'module_path' => ['nullable', 'string'],
            'date' => ['nullable', 'regex:/^\d{4}\-\d{2}$/'],
            'search' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'string'],
        ]);

        $modulePath = trim((string) ($validated['module_path'] ?? 'all'));
        $search = trim((string) ($validated['search'] ?? ''));
        $date = trim((string) ($validated['date'] ?? ''));
        $assigneeId = trim((string) ($validated['assignee_id'] ?? ''));

        $rows = $this->collectRows($date !== '' ? $date : null);

        if ($modulePath !== '' && strtolower($modulePath) !== 'all') {
            if (!in_array($modulePath, self::MODULES, true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'module_path tidak valid.',
                    'data' => [],
                ], 422);
            }
            $rows = $rows->where('module_path', $modulePath)->values();
        }

        if (!$isAdmin) {
            $rows = $rows->filter(function ($row) use ($authUser) {
                return trim((string) ($row->assignee_id ?? '')) === trim((string) $authUser->id_user);
            })->values();
        } elseif ($assigneeId !== '' && strtolower($assigneeId) !== 'all') {
            $rows = $rows->filter(function ($row) use ($assigneeId) {
                return trim((string) ($row->assignee_id ?? '')) === $assigneeId;
            })->values();
        }

        if ($search !== '') {
            $keyword = strtolower($search);
            $rows = $rows->filter(function ($row) use ($keyword) {
                $values = [
                    $row->record_id ?? '',
                    $row->nomor ?? '',
                    $row->judul ?? '',
                    $row->assignee_name ?? '',
                    $row->assignee_id ?? '',
                ];

                foreach ($values as $value) {
                    if (str_contains(strtolower((string) $value), $keyword)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $sorted = $rows->sortByDesc(function ($row) {
            return strtotime((string) ($row->tanggal ?? $row->created_at ?? '')) ?: 0;
        })->values();

        return response()->json([
            'status' => true,
            'message' => $isAdmin
                ? 'Berhasil memuat kontrol pekerjaan reportorium.'
                : 'Berhasil memuat riwayat pekerjaan reportorium Anda.',
            'data' => $sorted,
        ], 200);
    }

    public function asisten(Request $request)
    {
        $guard = $this->denyIfNotAdmin($request);
        if ($guard) {
            return $guard;
        }

        $rows = User::query()
            ->orderBy('nama_lengkap')
            ->get(['id_user', 'nama_lengkap', 'level_user']);

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat daftar asisten.',
            'data' => $rows,
        ], 200);
    }

    private function resolveReassignTarget(string $modulePath): ?array
    {
        return match ($modulePath) {
            '/buku_akta' => ['table' => 'buku_notaris', 'id_field' => 'id_buku_notaris', 'assignee_field' => 'id_user'],
            '/buku_ppat' => ['table' => 'buku_ppats', 'id_field' => 'id_buku_ppat', 'assignee_field' => 'id_user'],
            '/buku_legalisasi' => ['table' => 'buku_legalisasis', 'id_field' => 'id_buku_legalisasi', 'assignee_field' => 'id_user'],
            '/buku_waarmerking' => ['table' => 'buku_warmerkings', 'id_field' => 'id_buku_warmerking', 'assignee_field' => 'id_user'],
            '/buku_surat_notaris' => ['table' => 'buku_surat_notaris', 'id_field' => 'id_surat_notaris', 'assignee_field' => 'pengirim'],
            '/buku_surat_ppat' => ['table' => 'buku_surat_ppats', 'id_field' => 'id_surat_ppat', 'assignee_field' => 'pengirim'],
            '/tanda_terima', '/tanda_terima_masuk' => ['table' => 'tanda_terima', 'id_field' => 'id', 'assignee_field' => 'pembuat'],
            default => null,
        };
    }

    public function reassign(Request $request)
    {
        $guard = $this->denyIfNotAdmin($request);
        if ($guard) {
            return $guard;
        }

        /** @var User|null $authUser */
        $authUser = $request->user();

        $validated = $request->validate([
            'module_path' => ['required', 'string'],
            'record_id' => ['required', 'string'],
            'target_id_user' => ['nullable', 'string'],
            'take_over' => ['nullable', 'boolean'],
        ]);

        $modulePath = (string) $validated['module_path'];
        $recordId = (string) $validated['record_id'];
        $takeOver = (bool) ($validated['take_over'] ?? false);
        $targetIdUser = trim((string) ($validated['target_id_user'] ?? ''));

        if ($takeOver || $targetIdUser === '') {
            $targetIdUser = (string) $authUser->id_user;
        }

        $targetUser = User::query()->where('id_user', $targetIdUser)->first();
        if (!$targetUser) {
            return response()->json([
                'status' => false,
                'message' => 'Asisten tujuan tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $target = $this->resolveReassignTarget($modulePath);
        if (!$target) {
            return response()->json([
                'status' => false,
                'message' => 'module_path tidak didukung untuk pengalihan.',
                'data' => [],
            ], 422);
        }

        $query = DB::table($target['table'])->where($target['id_field'], $recordId);

        if ($modulePath === '/tanda_terima_masuk') {
            $query->whereRaw("LOWER(COALESCE(status, '')) = 'masuk'");
        }
        if ($modulePath === '/tanda_terima') {
            $query->whereRaw("LOWER(COALESCE(status, 'keluar')) <> 'masuk'");
        }

        $row = $query->first();
        if (!$row) {
            return response()->json([
                'status' => false,
                'message' => 'Data pekerjaan tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $oldAssignee = trim((string) ($row->{$target['assignee_field']} ?? ''));
        $query->update([
            $target['assignee_field'] => $targetIdUser,
        ]);

        $oldAssigneeName = User::query()->where('id_user', $oldAssignee)->value('nama_lengkap');

        return response()->json([
            'status' => true,
            'message' => 'Berhasil mengalihkan pekerjaan reportorium.',
            'data' => [
                'module_path' => $modulePath,
                'record_id' => $recordId,
                'from_id_user' => $oldAssignee,
                'from_name' => $oldAssigneeName,
                'to_id_user' => $targetIdUser,
                'to_name' => $targetUser->nama_lengkap,
            ],
        ], 200);
    }

    public function monthlyReportForChatbot(Request $request)
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'requester_phone' => ['required', 'string'],
            'requester_phone_candidates' => ['nullable', 'array'],
            'requester_phone_candidates.*' => ['string'],
            'month' => ['nullable', 'regex:/^\d{4}\-\d{2}$/'],
            'module_paths' => ['nullable', 'array'],
            'module_paths.*' => ['string', 'in:'.implode(',', self::MODULES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $phoneCandidates = array_merge(
            [(string) $validated['requester_phone']],
            (array) ($validated['requester_phone_candidates'] ?? [])
        );
        $requester = $this->resolveUserByPhoneCandidates($phoneCandidates);

        if (!$requester || !$this->isAdminOrSuper($requester)) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak. Laporan reportorium bulanan hanya untuk Admin/Super Admin.',
            ], 403);
        }

        $month = trim((string) ($validated['month'] ?? ''));
        if ($month === '') {
            $month = now()->format('Y-m');
        }
        $modulePaths = collect((array) ($validated['module_paths'] ?? []))
            ->map(function ($item) {
                return trim((string) $item);
            })
            ->filter()
            ->unique()
            ->values();
        $limit = (int) ($validated['limit'] ?? 200);

        $rows = $this->collectRows($month)
            ->sortByDesc(function ($row) {
                return strtotime((string) ($row->tanggal ?? $row->created_at ?? '')) ?: 0;
            })
            ->values();

        if ($modulePaths->isNotEmpty()) {
            $rows = $rows->filter(function ($row) use ($modulePaths) {
                return $modulePaths->contains(trim((string) ($row->module_path ?? '')));
            })->values();
        }

        $moduleSummary = $rows
            ->groupBy('module_path')
            ->map(function ($items, $modulePath) {
                return [
                    'module_path' => (string) $modulePath,
                    'module_label' => $this->moduleLabel((string) $modulePath),
                    'total' => $items->count(),
                ];
            })
            ->values()
            ->all();

        $assigneeSummary = $rows
            ->groupBy(function ($row) {
                return trim((string) ($row->assignee_id ?? ''));
            })
            ->map(function ($items, $assigneeId) {
                $first = $items->first();
                return [
                    'assignee_id' => (string) $assigneeId,
                    'assignee_name' => (string) ($first->assignee_name ?? '-'),
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        $detailRows = $rows
            ->take($limit)
            ->map(function ($row) {
                return [
                    'module_path' => (string) ($row->module_path ?? ''),
                    'module_label' => $this->moduleLabel((string) ($row->module_path ?? '')),
                    'record_id' => (string) ($row->record_id ?? ''),
                    'nomor' => (string) ($row->nomor ?? ''),
                    'judul' => (string) ($row->judul ?? ''),
                    'assignee_id' => (string) ($row->assignee_id ?? ''),
                    'assignee_name' => (string) ($row->assignee_name ?? ''),
                    'tanggal' => (string) ($row->tanggal ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat laporan reportorium bulanan.',
            'data' => [
                'month' => $month,
                'selected_module_paths' => $modulePaths->values()->all(),
                'total_rows' => $rows->count(),
                'returned_rows' => count($detailRows),
                'module_summary' => $moduleSummary,
                'assignee_summary' => $assigneeSummary,
                'rows' => $detailRows,
            ],
        ], 200);
    }
}
