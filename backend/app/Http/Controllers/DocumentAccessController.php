<?php

namespace App\Http\Controllers;

use App\Models\DocumentDownloadRequest;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

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

    private function appendExtensionIfMissing(string $name, string $storedFileName): string
    {
        $displayName = trim($name);
        if ($displayName === '') {
            return basename($storedFileName);
        }

        $extension = strtolower((string) pathinfo(basename($storedFileName), PATHINFO_EXTENSION));
        if ($extension !== '' && strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION)) !== $extension) {
            $displayName .= '.'.$extension;
        }

        return $displayName;
    }

    private function safeDownloadName(string $name, string $storedFileName): string
    {
        $displayName = $this->appendExtensionIfMissing($name, $storedFileName);
        $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));
        $nameOnly = pathinfo($displayName, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^\pL\pN\s._-]+/u', '-', $nameOnly) ?: '';
        $safeName = preg_replace('/\s+/', ' ', $safeName) ?: '';
        $safeName = trim($safeName, " ._-");

        if ($safeName === '') {
            $safeName = pathinfo(basename($storedFileName), PATHINFO_FILENAME) ?: 'dokumen';
        }

        return $safeName.($extension ? '.'.$extension : '');
    }

    private function inferDisplayName(DocumentDownloadRequest $record): string
    {
        $storedFileName = (string) $record->file_name;
        $displayName = trim((string) ($record->display_name ?? ''));
        if ($displayName !== '') {
            return $this->safeDownloadName($displayName, $storedFileName);
        }

        if ((string) $record->file_category === 'client_document') {
            $parts = explode(':', (string) $record->row_id);
            $berkasId = trim((string) end($parts));
            if ($berkasId !== '') {
                $namaDokumen = DB::table('tb_berkas')
                    ->where('id_berkas', $berkasId)
                    ->value('nama_dokumen');
                if (trim((string) $namaDokumen) !== '') {
                    return $this->safeDownloadName((string) $namaDokumen, $storedFileName);
                }
            }
        }

        return $this->safeDownloadName(basename($storedFileName), $storedFileName);
    }

    private function notifyAdminsAboutRequest(DocumentDownloadRequest $record): void
    {
        $this->notifyAdminsAboutRequests(collect([$record]));
    }

    private function notifyAdminsAboutRequests($records): void
    {
        $records = collect($records)->filter();
        if ($records->isEmpty()) {
            return;
        }

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

        /** @var DocumentDownloadRequest $first */
        $first = $records->first();
        $ids = $records->pluck('id')->filter()->values()->all();
        $idText = implode(',', $ids);

        $message = "*REQUEST AKSES FILE*\n\n";
        $message .= "*ID Request:* {$idText}\n";
        $message .= "*Peminta:* {$first->requested_by_name} ({$first->requested_by_id_user})\n";
        if (!empty($first->batch_id)) {
            $message .= "*Batch:* {$first->batch_id}\n";
        }
        $message .= "*Jumlah File:* ".$records->count()."\n";
        $message .= "*Daftar File:*\n";
        foreach ($records->take(12) as $row) {
            $message .= "- #{$row->id} ".$this->inferDisplayName($row)."\n";
        }
        if ($records->count() > 12) {
            $message .= "- ...dan ".($records->count() - 12)." file lainnya\n";
        }
        if (!empty($first->note)) {
            $message .= "*Catatan:* {$first->note}\n";
        }
        $message .= "\nBalas salah satu format berikut:\n";
        $message .= "- `ya {$idText}` untuk setujui semua\n";
        $message .= "- `tidak {$idText}` untuk tolak semua";

        foreach ($admins as $admin) {
            try {
                $this->wahaClient->sendMessage((string) $admin->phone, $message, [
                    'source' => 'document_access.request',
                    'request_ids' => $ids,
                    'batch_id' => $first->batch_id,
                    'admin_user_id' => $admin->id,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function notifyRequesterAboutCreatedRequests($records): void
    {
        $records = collect($records)->filter();
        if ($records->isEmpty()) {
            return;
        }

        if ($records->count() === 1) {
            $this->notifyRequesterAboutCreatedRequest($records->first());
            return;
        }

        /** @var DocumentDownloadRequest $first */
        $first = $records->first();
        $requester = User::query()
            ->where('id_user', (string) $first->requested_by_id_user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->first();

        if (!$requester) {
            return;
        }

        $message = "*REQUEST AKSES FILE DITERIMA*\n\n";
        $message .= "*Batch:* {$first->batch_id}\n";
        $message .= "*Jumlah File:* ".$records->count()."\n";
        $message .= "*Status:* ".strtoupper((string) $first->status)."\n";
        foreach ($records->take(12) as $row) {
            $message .= "- #{$row->id} ".$this->inferDisplayName($row)."\n";
        }
        if ($records->count() > 12) {
            $message .= "- ...dan ".($records->count() - 12)." file lainnya\n";
        }
        $message .= $first->status === 'approved'
            ? "\nPermintaan langsung disetujui. Silakan unduh file di aplikasi."
            : "\nPermintaan Anda sedang menunggu persetujuan admin.";

        try {
            $this->wahaClient->sendMessage((string) $requester->phone, $message, [
                'source' => 'document_access.requester_created',
                'request_ids' => $records->pluck('id')->values()->all(),
                'batch_id' => $first->batch_id,
                'requester_user_id' => $requester->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
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
        $message .= "*File:* ".$this->inferDisplayName($record)."\n";
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
        $message .= "*File:* ".$this->inferDisplayName($record)."\n";
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

    private function notifyRequesterAboutDecisionRequests($records, string $decision, User $actor, ?string $note): void
    {
        $records = collect($records)->filter();
        if ($records->isEmpty()) {
            return;
        }

        if ($records->count() === 1) {
            $this->notifyRequesterAboutDecision($records->first());
            return;
        }

        /** @var DocumentDownloadRequest $first */
        $first = $records->first();
        $requester = User::query()
            ->where('id_user', (string) $first->requested_by_id_user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->first();

        if (!$requester) {
            return;
        }

        $statusText = $decision === 'approved' ? 'DISETUJUI' : 'DITOLAK';
        $message = "*UPDATE REQUEST AKSES FILE*\n\n";
        $message .= "*ID Request:* ".implode(',', $records->pluck('id')->values()->all())."\n";
        $message .= "*Status:* {$statusText}\n";
        $message .= "*Jumlah File:* ".$records->count()."\n";
        $message .= "*Diproses oleh:* ".($actor->nama_lengkap ?: 'Admin')."\n";
        if (trim((string) $note) !== '') {
            $message .= "*Catatan:* ".trim((string) $note)."\n";
        }
        $message .= "*Daftar File:*\n";
        foreach ($records->take(12) as $row) {
            $message .= "- #{$row->id} ".$this->inferDisplayName($row)."\n";
        }
        if ($records->count() > 12) {
            $message .= "- ...dan ".($records->count() - 12)." file lainnya\n";
        }
        if ($decision === 'approved') {
            $message .= "\nSilakan buka aplikasi untuk unduh file.";
        }

        try {
            $this->wahaClient->sendMessage((string) $requester->phone, $message, [
                'source' => 'document_access.decision',
                'request_ids' => $records->pluck('id')->values()->all(),
                'batch_id' => $first->batch_id,
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

    private function applyDecisionMany($records, string $decision, ?string $note, User $actor)
    {
        $updated = collect($records)->map(function (DocumentDownloadRequest $record) use ($decision, $note, $actor) {
            $record->status = $decision;
            $record->note = trim((string) $note) ?: null;
            $record->approved_by_id_user = (string) $actor->id_user;
            $record->approved_by_name = (string) $actor->nama_lengkap;
            $record->approved_at = now();
            $record->save();

            return $record->fresh() ?: $record;
        })->values();

        $updated
            ->groupBy(static fn (DocumentDownloadRequest $record) => (string) $record->requested_by_id_user)
            ->each(function ($requesterRecords) use ($decision, $actor, $note) {
                $this->notifyRequesterAboutDecisionRequests($requesterRecords, $decision, $actor, $note);
            });

        return $updated;
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

    private function normalizeDocumentPayload(array $payload): array
    {
        $modulePath = trim((string) ($payload['module_path'] ?? ''));
        $rowId = trim((string) ($payload['row_id'] ?? ''));
        $fileName = trim((string) ($payload['file_name'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $category = trim((string) ($payload['file_category'] ?? ''));
        if ($category === '') {
            $category = (string) ($this->inferCategory($modulePath) ?? '');
        }

        return [
            'module_path' => $modulePath,
            'row_id' => $rowId,
            'file_name' => $fileName,
            'display_name' => $displayName,
            'file_category' => $category,
        ];
    }

    private function validateDocumentTarget(array $document): array
    {
        if ($document['module_path'] === '' || $document['row_id'] === '' || $document['file_name'] === '') {
            throw new \InvalidArgumentException('Data dokumen tidak lengkap.');
        }

        if ($document['file_category'] === '') {
            throw new \InvalidArgumentException('Kategori file tidak dikenal.');
        }

        $storedFileName = $this->sanitizeStoredFileName($document['file_category'], $document['file_name']);
        if (!$storedFileName) {
            throw new \InvalidArgumentException('Nama file tidak valid.');
        }

        $relativePath = $this->resolveRelativePath($document['file_category'], $storedFileName);
        if (!$relativePath) {
            throw new \InvalidArgumentException('Nama file tidak valid.');
        }

        $absolutePath = public_path($relativePath);
        if (!\App\Services\DocumentStorage::exists($absolutePath)) {
            throw new \RuntimeException('File tidak ditemukan: '.basename($storedFileName));
        }

        $document['file_name'] = $storedFileName;
        $document['display_name'] = trim((string) ($document['display_name'] ?? ''));
        return $document;
    }

    private function firstOrCreateDownloadRequest(array $document, User $authUser, bool $isAdmin, ?string $note, ?string $batchId, ?string $batchLabel): array
    {
        $requesterIdUser = (string) $authUser->id_user;

        $existing = DocumentDownloadRequest::query()
            ->where('module_path', $document['module_path'])
            ->where('row_id', $document['row_id'])
            ->where('file_name', $document['file_name'])
            ->where('requested_by_id_user', $requesterIdUser)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id')
            ->first();

        if ($existing && $existing->status === 'pending' && $isAdmin) {
            $existing->status = 'approved';
            $existing->approved_by_id_user = (string) $authUser->id_user;
            $existing->approved_by_name = (string) $authUser->nama_lengkap;
            $existing->approved_at = now();
            if (!$existing->batch_id && $batchId) {
                $existing->batch_id = $batchId;
                $existing->batch_label = $batchLabel;
            }
            if (trim((string) $existing->display_name) === '' && trim((string) ($document['display_name'] ?? '')) !== '') {
                $existing->display_name = $this->safeDownloadName((string) $document['display_name'], (string) $document['file_name']);
            }
            $existing->save();

            return ['record' => $existing, 'created' => false, 'reused' => true];
        }

        if ($existing) {
            if (trim((string) $existing->display_name) === '' && trim((string) ($document['display_name'] ?? '')) !== '') {
                $existing->display_name = $this->safeDownloadName((string) $document['display_name'], (string) $document['file_name']);
                $existing->save();
            }
            return ['record' => $existing, 'created' => false, 'reused' => true];
        }

        $payload = [
            'batch_id' => $batchId,
            'batch_label' => $batchLabel,
            'module_path' => $document['module_path'],
            'row_id' => $document['row_id'],
            'file_name' => $document['file_name'],
            'display_name' => trim((string) ($document['display_name'] ?? '')) !== ''
                ? $this->safeDownloadName((string) $document['display_name'], (string) $document['file_name'])
                : null,
            'file_category' => $document['file_category'],
            'requested_by_id_user' => $requesterIdUser,
            'requested_by_name' => (string) $authUser->nama_lengkap,
            'status' => $isAdmin ? 'approved' : 'pending',
            'note' => trim((string) $note) ?: null,
        ];

        if ($isAdmin) {
            $payload['approved_by_id_user'] = (string) $authUser->id_user;
            $payload['approved_by_name'] = (string) $authUser->nama_lengkap;
            $payload['approved_at'] = now();
        }

        return [
            'record' => DocumentDownloadRequest::query()->create($payload),
            'created' => true,
            'reused' => false,
        ];
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
            'documents' => ['nullable', 'array', 'max:100'],
            'documents.*.module_path' => ['required_with:documents', 'string'],
            'documents.*.row_id' => ['required_with:documents', 'string'],
            'documents.*.file_name' => ['required_with:documents', 'string'],
            'documents.*.display_name' => ['nullable', 'string', 'max:255'],
            'documents.*.file_category' => ['nullable', 'string'],
            'module_path' => ['required_without:documents', 'string'],
            'row_id' => ['required_without:documents', 'string'],
            'file_name' => ['required_without:documents', 'string'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'file_category' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'batch_label' => ['nullable', 'string', 'max:255'],
        ]);

        $rawDocuments = !empty($validated['documents'])
            ? (array) $validated['documents']
            : [[
                'module_path' => $validated['module_path'] ?? '',
                'row_id' => $validated['row_id'] ?? '',
                'file_name' => $validated['file_name'] ?? '',
                'display_name' => $validated['display_name'] ?? '',
                'file_category' => $validated['file_category'] ?? '',
            ]];

        $documents = [];
        try {
            foreach ($rawDocuments as $rawDocument) {
                $documents[] = $this->validateDocumentTarget($this->normalizeDocumentPayload((array) $rawDocument));
            }
        } catch (\InvalidArgumentException $error) {
            return response()->json([
                'status' => false,
                'message' => $error->getMessage(),
                'data' => [],
            ], 422);
        } catch (\RuntimeException $error) {
            return response()->json([
                'status' => false,
                'message' => $error->getMessage(),
                'data' => [],
            ], 404);
        }

        $isAdmin = $this->isAdminOrSuper($authUser);
        $isBatch = count($documents) > 1;
        $batchId = $isBatch ? (string) Str::uuid() : null;
        $batchLabel = trim((string) ($validated['batch_label'] ?? '')) ?: null;

        $records = collect();
        $createdRecords = collect();
        foreach ($documents as $document) {
            $result = $this->firstOrCreateDownloadRequest(
                $document,
                $authUser,
                $isAdmin,
                (string) ($validated['note'] ?? ''),
                $batchId,
                $batchLabel
            );
            $records->push($result['record']);
            if ($result['created']) {
                $createdRecords->push($result['record']);
            }
        }

        if ($createdRecords->isNotEmpty()) {
            $this->notifyRequesterAboutCreatedRequests($createdRecords);
        }

        $pendingRecords = $records->filter(fn ($row) => $row->status === 'pending');
        if (!$isAdmin && $pendingRecords->isNotEmpty()) {
            $this->notifyAdminsAboutRequests($pendingRecords);
        }

        $approvedCount = $records->filter(fn ($row) => $row->status === 'approved')->count();
        $pendingCount = $records->filter(fn ($row) => $row->status === 'pending')->count();
        $requestIds = $records->pluck('id')->values()->all();

        return response()->json([
            'status' => true,
            'message' => $isAdmin
                ? 'Download langsung disetujui untuk Admin/Super Admin.'
                : ($pendingCount > 0
                    ? 'Permintaan download berhasil dikirim. Tunggu persetujuan admin.'
                    : 'Download sudah disetujui. Silakan unduh.'),
            'data' => [
                'request_id' => $requestIds[0] ?? null,
                'request_ids' => $requestIds,
                'batch_id' => $batchId ?: ($records->first()->batch_id ?? null),
                'approved' => $pendingCount === 0 && $approvedCount > 0,
                'request_status' => $pendingCount > 0 ? 'pending' : 'approved',
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
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
            'batch_id' => ['nullable', 'string'],
        ]);

        $isAdmin = $this->isAdminOrSuper($authUser);
        $mine = (bool) ($validated['mine'] ?? false);
        $status = strtolower(trim((string) ($validated['status'] ?? ($isAdmin && !$mine ? 'pending' : 'all'))));
        $modulePath = trim((string) ($validated['module_path'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));
        $batchId = trim((string) ($validated['batch_id'] ?? ''));
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
        if ($batchId !== '') {
            $query->where('batch_id', $batchId);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('file_name', 'like', '%'.$search.'%')
                    ->orWhere('display_name', 'like', '%'.$search.'%')
                    ->orWhere('requested_by_name', 'like', '%'.$search.'%')
                    ->orWhere('requested_by_id_user', 'like', '%'.$search.'%')
                    ->orWhere('row_id', 'like', '%'.$search.'%');
            });
        }

        $rows = $query->limit($limit)->get();
        $rows->each(function (DocumentDownloadRequest $row) {
            $row->display_name = $this->inferDisplayName($row);
        });

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat data persetujuan download.',
            'data' => $rows,
        ], 200);
    }

    public function decideBulk(Request $request)
    {
        $guard = $this->denyIfNotAdmin($request);
        if ($guard) {
            return $guard;
        }

        /** @var User|null $authUser */
        $authUser = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ids = collect($validated['ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $records = DocumentDownloadRequest::query()
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada request pending yang bisa diproses.',
                'data' => [],
            ], 404);
        }

        $updated = $this->applyDecisionMany(
            $records,
            (string) $validated['decision'],
            (string) ($validated['note'] ?? ''),
            $authUser
        );

        return response()->json([
            'status' => true,
            'message' => $validated['decision'] === 'approved'
                ? $updated->count().' permintaan download disetujui.'
                : $updated->count().' permintaan download ditolak.',
            'data' => $updated,
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
        if ($request->header('X-API-Key') !== config('services.internal_api_key')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'admin_phone' => ['required', 'string'],
            'admin_phone_candidates' => ['nullable', 'array'],
            'admin_phone_candidates.*' => ['string'],
            'request_id' => ['nullable', 'integer', 'min:1'],
            'request_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'request_ids.*' => ['integer', 'min:1'],
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

        $ids = collect((array) ($validated['request_ids'] ?? []))
            ->push($validated['request_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'ID request tidak ditemukan.',
            ], 404);
        }

        $records = DocumentDownloadRequest::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($records->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'ID request tidak ditemukan.',
            ], 404);
        }

        $notPending = $records->first(fn ($record) => $record->status !== 'pending');
        if ($notPending) {
            return response()->json([
                'status' => false,
                'message' => "Request #{$notPending->id} sudah berstatus {$notPending->status}.",
                'data' => $notPending,
            ], 409);
        }

        $updated = $this->applyDecisionMany(
            $records,
            (string) $validated['decision'],
            (string) ($validated['note'] ?? ''),
            $admin
        );

        return response()->json([
            'status' => true,
            'message' => $validated['decision'] === 'approved'
                ? $updated->count().' request disetujui.'
                : $updated->count().' request ditolak.',
            'data' => $updated,
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
        if (!\App\Services\DocumentStorage::exists($absolutePath)) {
            return response()->json([
                'status' => false,
                'message' => 'File tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        return \App\Services\DocumentStorage::response($absolutePath, $this->inferDisplayName($record), true);
    }

    public function downloadBulk(Request $request)
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
            'ids' => ['required', 'string'],
        ]);

        $ids = collect(explode(',', (string) $validated['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'ID request download tidak valid.',
                'data' => [],
            ], 422);
        }

        $isAdmin = $this->isAdminOrSuper($authUser);
        $records = DocumentDownloadRequest::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($records->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Permintaan download tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        foreach ($records as $record) {
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
                    'message' => 'Ada file yang belum disetujui admin.',
                    'data' => [],
                ], 403);
            }
        }

        if ($records->count() === 1) {
            return $this->download($request, (int) $records->first()->id);
        }

        if (!class_exists(ZipArchive::class)) {
            return response()->json([
                'status' => false,
                'message' => 'Ekstensi ZIP belum aktif di server.',
                'data' => [],
            ], 500);
        }

        $zipPath = storage_path('app/document-downloads/request-'.now()->format('YmdHis').'-'.Str::random(6).'.zip');
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal membuat file ZIP.',
                'data' => [],
            ], 500);
        }

        $usedNames = [];
        foreach ($records as $record) {
            $relativePath = $this->resolveRelativePath((string) $record->file_category, (string) $record->file_name);
            $absolutePath = $relativePath ? public_path($relativePath) : '';
            if (!$absolutePath || !\App\Services\DocumentStorage::exists($absolutePath)) {
                continue;
            }

            $baseName = $this->inferDisplayName($record);
            $zipName = $baseName;
            $counter = 2;
            while (isset($usedNames[strtolower($zipName)])) {
                $extension = pathinfo($baseName, PATHINFO_EXTENSION);
                $nameOnly = pathinfo($baseName, PATHINFO_FILENAME);
                $zipName = $extension
                    ? "{$nameOnly}-{$counter}.{$extension}"
                    : "{$baseName}-{$counter}";
                $counter++;
            }
            $usedNames[strtolower($zipName)] = true;
            $zip->addFile(\App\Services\DocumentStorage::temporaryFile($absolutePath), $zipName);
        }
        $zip->close();

        if (!is_file($zipPath) || filesize($zipPath) <= 0) {
            @unlink($zipPath);
            return response()->json([
                'status' => false,
                'message' => 'File untuk ZIP tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        return response()->download($zipPath, 'dokumen-simanis-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend(true);
    }
}
