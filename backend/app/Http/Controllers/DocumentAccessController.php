<?php

namespace App\Http\Controllers;

use App\Models\DocumentDownloadRequest;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Http\Request;

class DocumentAccessController extends Controller
{
    private WahaClient $wahaClient;

    public function __construct(WahaClient $wahaClient)
    {
        $this->wahaClient = $wahaClient;
    }

    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));
        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private function phoneCandidates(string $phone): array
    {
        $digits = $this->normalizePhone($phone);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];
        if (str_starts_with($digits, '62')) {
            $local = '0'.substr($digits, 2);
            if (strlen($local) > 1) {
                $variants[] = $local;
                $variants[] = '+62'.substr($digits, 2);
            }
        } elseif (str_starts_with($digits, '0')) {
            $intl = '62'.substr($digits, 1);
            $variants[] = $intl;
            $variants[] = '+'.$intl;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function resolveUserByPhoneCandidates(array $phones): ?User
    {
        $normalized = collect($phones)
            ->flatMap(function ($phone) {
                return $this->phoneCandidates((string) $phone);
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

    private function notifyAdminsAboutRequest(DocumentDownloadRequest $record): void
    {
        $admins = User::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get()
            ->filter(function (User $user) {
                return $this->isAdminOrSuper($user);
            })
            ->values();

        if ($admins->isEmpty()) {
            return;
        }

        $message = "*REQUEST AKSES FILE*\n\n";
        $message .= "*ID Request:* {$record->id}\n";
        $message .= "*Peminta:* {$record->requested_by_name} ({$record->requested_by_id_user})\n";
        $message .= "*Modul:* {$record->module_path}\n";
        $message .= "*File:* {$record->file_name}\n";
        if (!empty($record->note)) {
            $message .= "*Catatan:* {$record->note}\n";
        }
        $message .= "\nBalas salah satu format berikut:\n";
        $message .= "- `ya {$record->id}` untuk setujui\n";
        $message .= "- `tidak {$record->id}` untuk tolak";

        foreach ($admins as $admin) {
            try {
                $this->wahaClient->sendMessage((string) $admin->phone, $message, [
                    'source' => 'document_access.request',
                    'request_id' => $record->id,
                    'admin_user_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function notifyRequesterAboutCreatedRequest(DocumentDownloadRequest $record): void
    {
        $requester = User::query()
            ->where('id_user', (string) $record->requested_by_id_user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->first();

        if (!$requester) {
            return;
        }

        $message = "*REQUEST AKSES FILE DITERIMA*\n\n";
        $message .= "*ID Request:* {$record->id}\n";
        $message .= "*File:* {$record->file_name}\n";
        $message .= "*Status:* ".strtoupper((string) $record->status)."\n";
        if (!empty($record->note)) {
            $message .= "*Catatan:* {$record->note}\n";
        }
        if ($record->status === 'pending') {
            $message .= "\nPermintaan Anda sedang menunggu persetujuan admin.";
        } elseif ($record->status === 'approved') {
            $message .= "\nPermintaan Anda langsung disetujui. Silakan unduh file di aplikasi.";
        }

        try {
            $this->wahaClient->sendMessage((string) $requester->phone, $message, [
                'source' => 'document_access.requester_created',
                'request_id' => $record->id,
                'requester_user_id' => $requester->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyRequesterAboutDecision(DocumentDownloadRequest $record): void
    {
        $requester = User::query()
            ->where('id_user', (string) $record->requested_by_id_user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->first();

        if (!$requester) {
            return;
        }

        $statusText = $record->status === 'approved' ? 'DISETUJUI' : 'DITOLAK';
        $message = "*UPDATE REQUEST AKSES FILE*\n\n";
        $message .= "*ID Request:* {$record->id}\n";
        $message .= "*Status:* {$statusText}\n";
        $message .= "*File:* {$record->file_name}\n";
        $message .= "*Diproses oleh:* ".($record->approved_by_name ?: 'Admin')."\n";
        if (!empty($record->note)) {
            $message .= "*Catatan:* {$record->note}\n";
        }
        if ($record->status === 'approved') {
            $message .= "\nSilakan buka aplikasi untuk unduh file.";
        }

        try {
            $this->wahaClient->sendMessage((string) $requester->phone, $message, [
                'source' => 'document_access.decision',
                'request_id' => $record->id,
                'requester_user_id' => $requester->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function applyDecision(DocumentDownloadRequest $record, string $decision, ?string $note, User $actor): DocumentDownloadRequest
    {
        $record->status = $decision;
        $record->note = trim((string) $note) ?: null;
        $record->approved_by_id_user = (string) $actor->id_user;
        $record->approved_by_name = (string) $actor->nama_lengkap;
        $record->approved_at = now();
        $record->save();

        $this->notifyRequesterAboutDecision($record);

        return $record;
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

    private function inferCategory(string $modulePath): ?string
    {
        return match ($modulePath) {
            '/buku_akta' => 'standard_notaris',
            '/buku_legalisasi' => 'standard_legalisasi',
            '/buku_waarmerking' => 'standard_waarmerking',
            '/buku_ppat' => 'standard_ppat',
            '/buku_surat_notaris' => 'surat_notaris',
            '/buku_surat_ppat' => 'surat_ppat',
            '/tanda_terima', '/tanda_terima_masuk' => 'tanda_terima',
            default => null,
        };
    }

    private function sanitizeStoredFileName(string $category, string $fileName): ?string
    {
        $raw = trim((string) $fileName);
        if ($raw === '') {
            return null;
        }

        if ($category === 'client_document') {
            $parts = preg_split('/[\/\\\\]+/', $raw) ?: [];
            $parts = array_values(array_filter(array_map(static fn ($part) => trim((string) $part), $parts)));

            if (count($parts) < 2) {
                return null;
            }

            $folder = basename((string) $parts[0]);
            $name = basename((string) end($parts));
            if ($folder === '' || $name === '') {
                return null;
            }

            return $folder.DIRECTORY_SEPARATOR.$name;
        }

        $safeFileName = trim((string) basename($raw));
        return $safeFileName === '' ? null : $safeFileName;
    }

    private function resolveRelativePath(string $category, string $fileName): ?string
    {
        $storedName = $this->sanitizeStoredFileName($category, $fileName);
        if (!$storedName) {
            return null;
        }

        if ($category === 'client_document') {
            $segments = preg_split('/[\/\\\\]+/', $storedName) ?: [];
            if (count($segments) < 2) {
                return null;
            }
            $folder = basename((string) $segments[0]);
            $name = basename((string) end($segments));
            if ($folder === '' || $name === '') {
                return null;
            }

            return 'berkasclient'.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.$name;
        }

        $safeFileName = basename($storedName);

        return match ($category) {
            'standard_notaris' => 'berkasnotaris'.DIRECTORY_SEPARATOR.$safeFileName,
            'standard_legalisasi' => 'berkaslegalisasis'.DIRECTORY_SEPARATOR.$safeFileName,
            'standard_waarmerking' => 'berkaswarmerkings'.DIRECTORY_SEPARATOR.$safeFileName,
            'standard_ppat' => 'berkasppat'.DIRECTORY_SEPARATOR.$safeFileName,
            'surat_notaris' => 'suratnotaris'.DIRECTORY_SEPARATOR.$safeFileName,
            'surat_ppat' => 'suratppats'.DIRECTORY_SEPARATOR.$safeFileName,
            'tanda_terima' => 'tandaterima'.DIRECTORY_SEPARATOR.$safeFileName,
            default => null,
        };
    }

    public function requestDownload(Request $request)
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

        $validated = $request->validate([
            'module_path' => ['required', 'string'],
            'row_id' => ['required', 'string'],
            'file_name' => ['required', 'string'],
            'file_category' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $modulePath = trim((string) $validated['module_path']);
        $rowId = trim((string) $validated['row_id']);
        $fileName = trim((string) $validated['file_name']);
        $category = trim((string) ($validated['file_category'] ?? ''));
        if ($category === '') {
            $category = (string) ($this->inferCategory($modulePath) ?? '');
        }

        if ($category === '') {
            return response()->json([
                'status' => false,
                'message' => 'Kategori file tidak dikenal.',
                'data' => [],
            ], 422);
        }

        $storedFileName = $this->sanitizeStoredFileName($category, $fileName);
        if (!$storedFileName) {
            return response()->json([
                'status' => false,
                'message' => 'Nama file tidak valid.',
                'data' => [],
            ], 422);
        }

        $relativePath = $this->resolveRelativePath($category, $storedFileName);
        if (!$relativePath) {
            return response()->json([
                'status' => false,
                'message' => 'Nama file tidak valid.',
                'data' => [],
            ], 422);
        }

        $absolutePath = public_path($relativePath);
        if (!is_file($absolutePath)) {
            return response()->json([
                'status' => false,
                'message' => 'File tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $requesterIdUser = (string) $authUser->id_user;
        $isAdmin = $this->isAdminOrSuper($authUser);

        $existing = DocumentDownloadRequest::query()
            ->where('module_path', $modulePath)
            ->where('row_id', $rowId)
            ->where('file_name', $storedFileName)
            ->where('requested_by_id_user', $requesterIdUser)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id')
            ->first();

        if ($existing && $existing->status === 'pending' && $isAdmin) {
            $existing->status = 'approved';
            $existing->approved_by_id_user = (string) $authUser->id_user;
            $existing->approved_by_name = (string) $authUser->nama_lengkap;
            $existing->approved_at = now();
            $existing->save();

            return response()->json([
                'status' => true,
                'message' => 'Download langsung disetujui untuk Admin/Super Admin.',
                'data' => [
                    'request_id' => $existing->id,
                    'approved' => true,
                    'request_status' => 'approved',
                ],
            ], 200);
        }

        if ($existing && $existing->status === 'pending') {
            return response()->json([
                'status' => true,
                'message' => 'Permintaan download sedang menunggu persetujuan admin.',
                'data' => [
                    'request_id' => $existing->id,
                    'approved' => false,
                    'request_status' => 'pending',
                ],
            ], 200);
        }

        if ($existing && $existing->status === 'approved') {
            return response()->json([
                'status' => true,
                'message' => 'Download sudah disetujui. Silakan unduh.',
                'data' => [
                    'request_id' => $existing->id,
                    'approved' => true,
                    'request_status' => 'approved',
                ],
            ], 200);
        }

        $payload = [
            'module_path' => $modulePath,
            'row_id' => $rowId,
            'file_name' => $storedFileName,
            'file_category' => $category,
            'requested_by_id_user' => $requesterIdUser,
            'requested_by_name' => (string) $authUser->nama_lengkap,
            'status' => $isAdmin ? 'approved' : 'pending',
            'note' => trim((string) ($validated['note'] ?? '')) ?: null,
        ];

        if ($isAdmin) {
            $payload['approved_by_id_user'] = (string) $authUser->id_user;
            $payload['approved_by_name'] = (string) $authUser->nama_lengkap;
            $payload['approved_at'] = now();
        }

        $newRequest = DocumentDownloadRequest::query()->create($payload);

        $this->notifyRequesterAboutCreatedRequest($newRequest);

        if (!$isAdmin && $newRequest->status === 'pending') {
            $this->notifyAdminsAboutRequest($newRequest);
        }

        return response()->json([
            'status' => true,
            'message' => $isAdmin
                ? 'Download langsung disetujui untuk Admin/Super Admin.'
                : 'Permintaan download berhasil dikirim. Tunggu persetujuan admin.',
            'data' => [
                'request_id' => $newRequest->id,
                'approved' => $newRequest->status === 'approved',
                'request_status' => $newRequest->status,
            ],
        ], 200);
    }

    public function listRequests(Request $request)
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

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'module_path' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $isAdmin = $this->isAdminOrSuper($authUser);
        $mine = (bool) ($validated['mine'] ?? false);
        $status = strtolower(trim((string) ($validated['status'] ?? ($isAdmin && !$mine ? 'pending' : 'all'))));
        $modulePath = trim((string) ($validated['module_path'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 200);

        $query = DocumentDownloadRequest::query()->orderByDesc('id');

        if (!$isAdmin || $mine) {
            $query->where('requested_by_id_user', (string) $authUser->id_user);
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($modulePath !== '' && strtolower($modulePath) !== 'all') {
            $query->where('module_path', $modulePath);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('file_name', 'like', '%'.$search.'%')
                    ->orWhere('requested_by_name', 'like', '%'.$search.'%')
                    ->orWhere('requested_by_id_user', 'like', '%'.$search.'%')
                    ->orWhere('row_id', 'like', '%'.$search.'%');
            });
        }

        $rows = $query->limit($limit)->get();

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat data persetujuan download.',
            'data' => $rows,
        ], 200);
    }

    public function decide(Request $request, int $id)
    {
        $guard = $this->denyIfNotAdmin($request);
        if ($guard) {
            return $guard;
        }

        /** @var User|null $authUser */
        $authUser = $request->user();

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DocumentDownloadRequest::query()->find($id);
        if (!$record) {
            return response()->json([
                'status' => false,
                'message' => 'Permintaan tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $record = $this->applyDecision(
            $record,
            (string) $validated['decision'],
            (string) ($validated['note'] ?? ''),
            $authUser
        );

        return response()->json([
            'status' => true,
            'message' => $record->status === 'approved'
                ? 'Permintaan download disetujui.'
                : 'Permintaan download ditolak.',
            'data' => $record,
        ], 200);
    }

    public function decideFromChatbot(Request $request)
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'admin_phone' => ['required', 'string'],
            'admin_phone_candidates' => ['nullable', 'array'],
            'admin_phone_candidates.*' => ['string'],
            'request_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $phoneCandidates = array_merge(
            [(string) $validated['admin_phone']],
            (array) ($validated['admin_phone_candidates'] ?? [])
        );
        $admin = $this->resolveUserByPhoneCandidates($phoneCandidates);

        if (!$admin || !$this->isAdminOrSuper($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Nomor WA ini bukan admin yang berwenang memproses request file.',
            ], 403);
        }

        $record = DocumentDownloadRequest::query()->find((int) $validated['request_id']);
        if (!$record) {
            return response()->json([
                'status' => false,
                'message' => 'ID request tidak ditemukan.',
            ], 404);
        }

        if ($record->status !== 'pending') {
            return response()->json([
                'status' => false,
                'message' => "Request #{$record->id} sudah berstatus {$record->status}.",
                'data' => $record,
            ], 409);
        }

        $record = $this->applyDecision(
            $record,
            (string) $validated['decision'],
            (string) ($validated['note'] ?? ''),
            $admin
        );

        return response()->json([
            'status' => true,
            'message' => $record->status === 'approved'
                ? "Request #{$record->id} disetujui."
                : "Request #{$record->id} ditolak.",
            'data' => $record,
        ], 200);
    }

    public function download(Request $request, int $id)
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

        $record = DocumentDownloadRequest::query()->find($id);
        if (!$record) {
            return response()->json([
                'status' => false,
                'message' => 'Permintaan download tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $isAdmin = $this->isAdminOrSuper($authUser);
        if (!$isAdmin && (string) $record->requested_by_id_user !== (string) $authUser->id_user) {
            return response()->json([
                'status' => false,
                'message' => 'Akses download ditolak.',
                'data' => [],
            ], 403);
        }

        if (!$isAdmin && $record->status !== 'approved') {
            return response()->json([
                'status' => false,
                'message' => 'Download belum disetujui admin.',
                'data' => [],
            ], 403);
        }

        $relativePath = $this->resolveRelativePath((string) $record->file_category, (string) $record->file_name);
        if (!$relativePath) {
            return response()->json([
                'status' => false,
                'message' => 'Konfigurasi file tidak valid.',
                'data' => [],
            ], 422);
        }

        $absolutePath = public_path($relativePath);
        if (!is_file($absolutePath)) {
            return response()->json([
                'status' => false,
                'message' => 'File tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        return response()->download($absolutePath, basename((string) $record->file_name));
    }
}
