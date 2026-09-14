<?php

namespace App\Http\Controllers;

use App\Models\ScannedDocument;
use App\Models\ScanSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScannedDocumentController extends Controller
{
    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));
        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function assistantQuery()
    {
        return User::query()
            ->whereRaw("UPPER(TRIM(COALESCE(level_user, ''))) NOT IN ('PETUGAS LUAR', 'ARSIP')")
            ->whereRaw("LOWER(COALESCE(status, 'aktif')) NOT IN ('nonaktif', 'non aktif', 'inactive', 'disabled', '0')")
            ->orderBy('nama_lengkap');
    }

    private function documentPayload(ScannedDocument $document): array
    {
        $document->loadMissing('assistant:id,id_user,nama_lengkap,level_user');

        return [
            'id' => $document->id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'file_name' => $document->file_name,
            'file_path' => $document->file_path,
            'mime_type' => $document->mime_type,
            'extension' => $document->extension,
            'size_bytes' => $document->size_bytes,
            'status' => $document->status,
            'posted_module' => $document->posted_module,
            'posted_record_id' => $document->posted_record_id,
            'posted_document_id' => $document->posted_document_id,
            'posted_file_path' => $document->posted_file_path,
            'posted_at' => optional($document->posted_at)->toIso8601String(),
            'note' => $document->note,
            'created_at' => optional($document->created_at)->toIso8601String(),
            'assistant' => $document->assistant ? [
                'id' => $document->assistant->id,
                'id_user' => $document->assistant->id_user,
                'nama_lengkap' => $document->assistant->nama_lengkap,
                'level_user' => $document->assistant->level_user,
            ] : null,
        ];
    }

    public function publicAssistants()
    {
        $assistants = $this->assistantQuery()
            ->get(['id', 'id_user', 'nama_lengkap', 'level_user'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'id_user' => $user->id_user,
                'nama_lengkap' => $user->nama_lengkap,
                'level_user' => $user->level_user,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Daftar asisten aktif berhasil dimuat.',
            'data' => $assistants,
        ]);
    }

    public function createPublicSession(Request $request)
    {
        $validated = $request->validate([
            'assistant_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'output_type' => ['nullable', Rule::in(['pdf', 'jpg'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $assistant = $this->assistantQuery()
            ->where('id', (int) $validated['assistant_user_id'])
            ->first();

        if (!$assistant) {
            return response()->json([
                'status' => false,
                'message' => 'Asisten tidak aktif atau tidak tersedia untuk scan publik.',
                'data' => [],
            ], 422);
        }

        $session = ScanSession::create([
            'token' => (string) Str::uuid(),
            'assistant_user_id' => $assistant->id,
            'status' => 'waiting',
            'output_type' => (string) ($validated['output_type'] ?? 'pdf'),
            'note' => $validated['note'] ?? null,
            'created_ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'expires_at' => now()->addMinutes(15),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Sesi scan dibuat. Silakan scan dokumen dari PC scanner.',
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'output_type' => $session->output_type,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
                'assistant' => [
                    'id' => $assistant->id,
                    'id_user' => $assistant->id_user,
                    'nama_lengkap' => $assistant->nama_lengkap,
                ],
            ],
        ], 201);
    }

    public function showPublicSession(string $token)
    {
        $session = ScanSession::with(['assistant:id,id_user,nama_lengkap', 'documents' => fn ($query) => $query->latest()])
            ->where('token', $token)
            ->first();

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Sesi scan tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        if ($session->status === 'waiting' && $session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired']);
            $session->refresh();
        }

        return response()->json([
            'status' => true,
            'message' => 'Status sesi scan berhasil dimuat.',
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'output_type' => $session->output_type,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
                'uploaded_at' => optional($session->uploaded_at)->toIso8601String(),
                'assistant' => $session->assistant,
                'documents' => $session->documents->map(fn (ScannedDocument $document) => $this->documentPayload($document))->values(),
                'document' => $session->documents->first() ? $this->documentPayload($session->documents->first()) : null,
            ],
        ]);
    }

    public function latestWaitingSession()
    {
        ScanSession::query()
            ->where('status', 'waiting')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $session = ScanSession::with('assistant:id,id_user,nama_lengkap')
            ->where('status', 'waiting')
            ->where('expires_at', '>=', now())
            ->latest()
            ->first();

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada sesi scan aktif. Pilih asisten dari halaman scan publik terlebih dahulu.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Sesi scan aktif ditemukan.',
            'data' => [
                'token' => $session->token,
                'status' => $session->status,
                'output_type' => $session->output_type,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
                'assistant' => $session->assistant,
            ],
        ]);
    }

    public function uploadFromAgent(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg', 'max:51200'],
            'uploaded_by_agent' => ['nullable', 'string', 'max:255'],
        ]);

        $session = ScanSession::with('assistant')
            ->where('token', (string) $validated['token'])
            ->first();

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Token sesi scan tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        if (!in_array((string) $session->status, ['waiting', 'uploaded'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Sesi scan sudah tidak menerima upload.',
                'data' => [],
            ], 422);
        }

        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired']);

            return response()->json([
                'status' => false,
                'message' => 'Sesi scan sudah kedaluwarsa. Buat sesi scan baru dari halaman publik.',
                'data' => [],
            ], 422);
        }

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $requestedOriginalName = trim((string) $request->input('original_name', ''));
        $originalName = $this->safeDisplayFileName($requestedOriginalName !== '' ? $requestedOriginalName : (string) $file->getClientOriginalName(), $extension);
        $mimeType = (string) $file->getClientMimeType();
        $sizeBytes = (int) $file->getSize();
        $directory = 'scanned-documents/' . now()->format('Y/m');
        $fileName = now()->format('YmdHis') . '-' . Str::random(16) . '.' . $extension;

        \App\Services\DocumentStorage::upload($file, $directory, $fileName);

        $document = DB::transaction(function () use ($session, $directory, $fileName, $extension, $originalName, $mimeType, $sizeBytes, $validated) {
            $document = ScannedDocument::create([
                'scan_session_id' => $session->id,
                'assistant_user_id' => $session->assistant_user_id,
                'title' => pathinfo($originalName, PATHINFO_FILENAME),
                'original_name' => $originalName,
                'file_name' => $fileName,
                'file_path' => $directory . '/' . $fileName,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size_bytes' => $sizeBytes,
                'status' => 'uploaded',
                'note' => $session->note,
                'uploaded_by_agent' => $validated['uploaded_by_agent'] ?? null,
            ]);

            $session->update([
                'status' => 'waiting',
                'uploaded_at' => now(),
            ]);

            return $document;
        });

        return response()->json([
            'status' => true,
            'message' => 'Dokumen scan berhasil diunggah.',
            'data' => $this->documentPayload($document),
        ], 201);
    }

    private function safeDisplayFileName(string $name, ?string $fallbackExtension = null): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' && $fallbackExtension) {
            $extension = strtolower($fallbackExtension);
        }

        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = $name;
        }

        $base = preg_replace('/[^\pL\pN\s._-]+/u', '-', $base) ?: 'dokumen';
        $base = preg_replace('/\s+/', ' ', $base) ?: 'dokumen';
        $base = trim($base, " ._-");
        if ($base === '') {
            $base = 'dokumen';
        }

        return $base . ($extension ? '.' . ltrim($extension, '.') : '');
    }

    private function nextDocumentId(string $table, string $column, int $prefixLength = 6): string
    {
        $last = DB::table($table)->orderBy($column, 'desc')->value($column);
        $next = $last ? ((int) substr((string) $last, $prefixLength) + 1) : 1;

        return 'Doc' . str_pad((string) $next, 7, '0', STR_PAD_LEFT);
    }

    private function nextClientDocumentId(): string
    {
        $last = DB::table('tb_berkas')->orderBy('id_berkas', 'desc')->value('id_berkas');
        $next = $last ? ((int) substr((string) $last, 10) + 1) : 1;

        return 'BK' . date('Ymd') . str_pad((string) $next, 10, '0', STR_PAD_LEFT);
    }

    private function uniquePublicFileName(string $directory, string $displayName): string
    {
        $safeName = $this->safeDisplayFileName($displayName);
        $extension = pathinfo($safeName, PATHINFO_EXTENSION);
        $base = pathinfo($safeName, PATHINFO_FILENAME) ?: 'dokumen';

        $candidate = $safeName;
        $counter = 2;
        while (\App\Services\DocumentStorage::exists($directory.'/'.$candidate)) {
            $candidate = $base . '-' . $counter . ($extension ? '.' . $extension : '');
            $counter++;
        }

        return $candidate;
    }

    private function postingConfig(string $module): ?array
    {
        return [
            'client' => ['directory' => null],
            'buku_notaris' => ['table' => 'tb_dokumen_notaris', 'id_column' => 'id_dokumen_notaris', 'target_column' => 'id_buku_notaris', 'directory' => 'berkasnotaris'],
            'buku_ppat' => ['table' => 'tb_dokumen_ppat', 'id_column' => 'id_dokumen_ppat', 'target_column' => 'id_buku_ppat', 'directory' => 'berkasppat'],
            'buku_legalisasi' => ['table' => 'tb_dokumen_legalisasis', 'id_column' => 'id_dokumen_legalisasi', 'target_column' => 'id_buku_legalisasi', 'directory' => 'berkaslegalisasis'],
            'buku_warmerking' => ['table' => 'tb_dokumen_warmerkings', 'id_column' => 'id_dokumen_warmerking', 'target_column' => 'id_buku_warmerking', 'directory' => 'berkaswarmerkings'],
            'surat_notaris' => ['table' => 'buku_surat_notaris', 'id_column' => 'id_surat_notaris', 'file_column' => 'file', 'directory' => 'suratnotaris'],
            'surat_ppat' => ['table' => 'buku_surat_ppats', 'id_column' => 'id_surat_ppat', 'file_column' => 'file', 'directory' => 'suratppats'],
            'tanda_terima' => ['table' => 'tanda_terima', 'id_column' => 'id', 'file_column' => 'file', 'directory' => 'tandaterima'],
        ][$module] ?? null;
    }

    private function targetSearchQuery(string $module, string $search)
    {
        $like = '%' . $search . '%';

        return match ($module) {
            'client' => DB::table('data_clients')
                ->selectRaw("id_client as id, CONCAT(id_client, ' - ', nama_client) as title, COALESCE(no_identitas, '-') as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id_client', 'like', $like)
                    ->orWhere('nama_client', 'like', $like)
                    ->orWhere('no_identitas', 'like', $like)))
                ->orderBy('id_client', 'desc'),
            'buku_notaris' => DB::table('buku_notaris')
                ->selectRaw("id_buku_notaris as id, CONCAT(COALESCE(no_akta, '-'), ' - ', COALESCE(judul_pekerjaan, '-')) as title, COALESCE(nama_client, '-') as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id_buku_notaris', 'like', $like)
                    ->orWhere('no_akta', 'like', $like)
                    ->orWhere('judul_pekerjaan', 'like', $like)
                    ->orWhere('nama_client', 'like', $like)))
                ->orderBy('id_buku_notaris', 'desc'),
            'buku_ppat' => DB::table('buku_ppats')
                ->leftJoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
                ->selectRaw("buku_ppats.id_buku_ppat as id, CONCAT(COALESCE(buku_ppats.no_akta, '-'), ' - ', COALESCE(daftar_aktas.nama_akta, buku_ppats.keterangan, '-')) as title, CONCAT(COALESCE(buku_ppats.pihak_mengalihkan, '-'), ' -> ', COALESCE(buku_ppats.pihak_menerima, '-')) as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('buku_ppats.id_buku_ppat', 'like', $like)
                    ->orWhere('buku_ppats.no_akta', 'like', $like)
                    ->orWhere('daftar_aktas.nama_akta', 'like', $like)
                    ->orWhere('buku_ppats.keterangan', 'like', $like)
                    ->orWhere('buku_ppats.pihak_mengalihkan', 'like', $like)
                    ->orWhere('buku_ppats.pihak_menerima', 'like', $like)))
                ->orderBy('buku_ppats.id_buku_ppat', 'desc'),
            'buku_legalisasi' => DB::table('buku_legalisasis')
                ->selectRaw("id_buku_legalisasi as id, CONCAT(COALESCE(no_legalisasi, '-'), ' - ', COALESCE(judul_surat, '-')) as title, COALESCE(keterangan_surat, '-') as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id_buku_legalisasi', 'like', $like)
                    ->orWhere('no_legalisasi', 'like', $like)
                    ->orWhere('judul_surat', 'like', $like)
                    ->orWhere('keterangan_surat', 'like', $like)))
                ->orderBy('id_buku_legalisasi', 'desc'),
            'buku_warmerking' => DB::table('buku_warmerkings')
                ->selectRaw("id_buku_warmerking as id, CONCAT(COALESCE(no_warmerking, '-'), ' - ', COALESCE(judul_surat, '-')) as title, COALESCE(keterangan_surat, '-') as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id_buku_warmerking', 'like', $like)
                    ->orWhere('no_warmerking', 'like', $like)
                    ->orWhere('judul_surat', 'like', $like)
                    ->orWhere('keterangan_surat', 'like', $like)))
                ->orderBy('id_buku_warmerking', 'desc'),
            'surat_notaris' => DB::table('buku_surat_notaris')
                ->selectRaw("id_surat_notaris as id, COALESCE(no_surat, '-') as title, CONCAT(COALESCE(pengirim, '-'), ' -> ', COALESCE(keterangan, '-')) as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id_surat_notaris', 'like', $like)
                    ->orWhere('no_surat', 'like', $like)
                    ->orWhere('pengirim', 'like', $like)
                    ->orWhere('keterangan', 'like', $like)))
                ->orderBy('id_surat_notaris', 'desc'),
            'surat_ppat' => DB::table('buku_surat_ppats')
                ->leftJoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_ppats.id_client')
                ->selectRaw("buku_surat_ppats.id_surat_ppat as id, COALESCE(buku_surat_ppats.no_surat, '-') as title, CONCAT(COALESCE(data_clients.nama_client, '-'), ' - ', COALESCE(buku_surat_ppats.keterangan, '-')) as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('buku_surat_ppats.id_surat_ppat', 'like', $like)
                    ->orWhere('buku_surat_ppats.no_surat', 'like', $like)
                    ->orWhere('data_clients.nama_client', 'like', $like)
                    ->orWhere('buku_surat_ppats.keterangan', 'like', $like)))
                ->orderBy('buku_surat_ppats.id_surat_ppat', 'desc'),
            'tanda_terima' => DB::table('tanda_terima')
                ->selectRaw("id as id, COALESCE(nomor_tanda_terima, '-') as title, CONCAT(COALESCE(nama_pengirim, '-'), ' -> ', COALESCE(nama_penerima, '-')) as subtitle")
                ->when($search !== '', fn ($q) => $q->where(fn ($b) => $b
                    ->where('id', 'like', $like)
                    ->orWhere('nomor_tanda_terima', 'like', $like)
                    ->orWhere('nama_pengirim', 'like', $like)
                    ->orWhere('nama_penerima', 'like', $like)))
                ->orderBy('id', 'desc'),
            default => null,
        };
    }

    public function searchPostingTargets(Request $request)
    {
        $validated = $request->validate([
            'module' => ['required', Rule::in(['client', 'buku_notaris', 'buku_ppat', 'buku_legalisasi', 'buku_warmerking', 'surat_notaris', 'surat_ppat', 'tanda_terima'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $this->targetSearchQuery((string) $validated['module'], trim((string) ($validated['search'] ?? '')));
        if (!$query) {
            return response()->json(['status' => false, 'message' => 'Modul tujuan tidak didukung.', 'data' => []], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Target posting berhasil dimuat.',
            'data' => $query->limit(25)->get(),
        ]);
    }

    public function publicSessionDocuments(string $token)
    {
        $session = ScanSession::query()->where('token', $token)->first();
        if (!$session) {
            return response()->json(['status' => false, 'message' => 'Sesi scan tidak ditemukan.', 'data' => []], 404);
        }

        $rows = ScannedDocument::query()
            ->where('scan_session_id', $session->id)
            ->latest()
            ->get()
            ->map(fn (ScannedDocument $document) => $this->documentPayload($document))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Dokumen scan sesi berhasil dimuat.',
            'data' => $rows,
        ]);
    }

    public function publicSearchPostingTargets(Request $request)
    {
        return $this->searchPostingTargets($request);
    }

    private function validatePublicDocumentToken(ScannedDocument $document, string $token): ?ScanSession
    {
        $session = ScanSession::query()
            ->where('token', $token)
            ->where('id', $document->scan_session_id)
            ->first();

        return $session;
    }

    public function publicDownload(Request $request, int $id)
    {
        $token = trim((string) $request->query('token', ''));
        $document = ScannedDocument::query()->findOrFail($id);

        if ($token === '' || !$this->validatePublicDocumentToken($document, $token)) {
            return response()->json(['status' => false, 'message' => 'Token sesi scan tidak valid untuk dokumen ini.', 'data' => []], 403);
        }

        $path = public_path($document->posted_file_path ?: $document->file_path);
        if (!\App\Services\DocumentStorage::exists($path)) {
            return response()->json(['status' => false, 'message' => 'File scan tidak ditemukan di server.', 'data' => []], 404);
        }

        return \App\Services\DocumentStorage::response($path, $document->original_name);
    }

    private function persistPostToModule(ScannedDocument $document, array $validated, ?User $user)
    {
        $document->loadMissing('assistant');

        if ($document->status === 'posted') {
            return response()->json(['status' => false, 'message' => 'Dokumen scan sudah diposting.', 'data' => $this->documentPayload($document)], 422);
        }

        $sourcePath = public_path($document->file_path);
        if (!\App\Services\DocumentStorage::exists($sourcePath)) {
            return response()->json(['status' => false, 'message' => 'File scan tidak ditemukan di server.', 'data' => []], 404);
        }

        $module = (string) $validated['module'];
        $config = $this->postingConfig($module);
        if (!$config) {
            return response()->json(['status' => false, 'message' => 'Modul tujuan tidak didukung.', 'data' => []], 422);
        }

        $recordId = (string) $validated['record_id'];
        $displayName = $this->safeDisplayFileName((string) ($validated['file_name'] ?: $document->original_name), $document->extension);
        $documentName = trim((string) $validated['document_name']);
        $idUser = optional($user)->id_user ?: optional($document->assistant)->id_user;

        try {
            $result = DB::transaction(function () use ($module, $config, $recordId, $displayName, $documentName, $document, $sourcePath, $user, $idUser) {
                if ($module === 'client') {
                    $client = DB::table('data_clients')->where('id_client', $recordId)->first();
                    if (!$client) {
                        throw new \RuntimeException('Client tujuan tidak ditemukan.');
                    }

                    $folder = trim((string) ($client->nama_folder ?: ('Dok' . $client->id_client)));
                    $directory = 'berkasclient/' . $folder;
                    $newFileName = $this->uniquePublicFileName($directory, $displayName);
                    $targetPath = public_path($directory . '/' . $newFileName);
                    if (!\App\Services\DocumentStorage::copy($sourcePath, $targetPath)) {
                        throw new \RuntimeException('Gagal memindahkan file scan ke folder client.');
                    }

                    $postedId = $this->nextClientDocumentId();
                    DB::table('tb_berkas')->insert([
                        'id_berkas' => $postedId,
                        'id_client' => $recordId,
                        'id_dokumen' => null,
                        'id_user' => $idUser,
                        'nama_berkas' => $newFileName,
                        'nama_dokumen' => $documentName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return [$postedId, $directory . '/' . $newFileName];
                }

                $table = $config['table'];
                $targetColumn = $config['target_column'] ?? null;
                $recordColumn = $config['id_column'];
                $targetExists = DB::table($table)
                    ->when($targetColumn, fn ($q) => $q->where($targetColumn, $recordId), fn ($q) => $q->where($recordColumn, $recordId))
                    ->exists();

                if (!$targetColumn && !$targetExists) {
                    throw new \RuntimeException('Record tujuan tidak ditemukan.');
                }

                if ($targetColumn) {
                    $targetTables = [
                        'id_buku_notaris' => 'buku_notaris',
                        'id_buku_ppat' => 'buku_ppats',
                        'id_buku_legalisasi' => 'buku_legalisasis',
                        'id_buku_warmerking' => 'buku_warmerkings',
                    ];
                    if (!DB::table($targetTables[$targetColumn] ?? $table)->where($targetColumn, $recordId)->exists()) {
                        throw new \RuntimeException('Record tujuan tidak ditemukan.');
                    }
                }

                $directory = $config['directory'];
                $newFileName = $this->uniquePublicFileName($directory, $displayName);
                $targetPath = public_path($directory . '/' . $newFileName);
                if (!\App\Services\DocumentStorage::copy($sourcePath, $targetPath)) {
                    throw new \RuntimeException('Gagal memindahkan file scan ke folder modul tujuan.');
                }

                if ($targetColumn) {
                    $postedId = $this->nextDocumentId($table, $recordColumn);
                    DB::table($table)->insert([
                        $recordColumn => $postedId,
                        $targetColumn => $recordId,
                        'id_user' => $idUser,
                        'nama_berkas' => $newFileName,
                        'nama_dokumen' => $documentName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return [$postedId, $directory . '/' . $newFileName];
                }

                DB::table($table)->where($recordColumn, $recordId)->update([
                    $config['file_column'] => $newFileName,
                    'updated_at' => now(),
                ]);

                return [$recordId, $directory . '/' . $newFileName];
            });
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage() ?: 'Gagal posting dokumen scan.',
                'data' => [],
            ], 422);
        }

        [$postedId, $postedPath] = $result;

        $document->update([
            'status' => 'posted',
            'posted_module' => $module,
            'posted_record_id' => $recordId,
            'posted_document_id' => $postedId,
            'posted_file_path' => $postedPath,
            'posted_by' => optional($user)->id,
            'posted_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Dokumen scan berhasil diposting ke modul tujuan.',
            'data' => $this->documentPayload($document->fresh()),
        ]);
    }

    public function publicPostToModule(Request $request, int $id)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'module' => ['required', Rule::in(['client', 'buku_notaris', 'buku_ppat', 'buku_legalisasi', 'buku_warmerking', 'surat_notaris', 'surat_ppat', 'tanda_terima'])],
            'record_id' => ['required', 'string', 'max:80'],
            'document_name' => ['required', 'string', 'max:255'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);

        $document = ScannedDocument::query()->findOrFail($id);
        if (!$this->validatePublicDocumentToken($document, (string) $validated['token'])) {
            return response()->json(['status' => false, 'message' => 'Token sesi scan tidak valid untuk dokumen ini.', 'data' => []], 403);
        }

        return $this->persistPostToModule($document, $validated, null);
    }

    public function postToModule(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = $request->user();
        $document = ScannedDocument::query()->findOrFail($id);

        if (!$this->isAdminOrSuper($user) && (int) $document->assistant_user_id !== (int) optional($user)->id) {
            return response()->json(['status' => false, 'message' => 'Akses ditolak untuk posting dokumen scan ini.', 'data' => []], 403);
        }

        if ($document->status === 'posted') {
            return response()->json(['status' => false, 'message' => 'Dokumen scan sudah diposting.', 'data' => $this->documentPayload($document)], 422);
        }

        $validated = $request->validate([
            'module' => ['required', Rule::in(['client', 'buku_notaris', 'buku_ppat', 'buku_legalisasi', 'buku_warmerking', 'surat_notaris', 'surat_ppat', 'tanda_terima'])],
            'record_id' => ['required', 'string', 'max:80'],
            'document_name' => ['required', 'string', 'max:255'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->persistPostToModule($document, $validated, $user);
    }

    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        $query = ScannedDocument::query()
            ->with('assistant:id,id_user,nama_lengkap,level_user')
            ->latest();

        if (!$this->isAdminOrSuper($user)) {
            $query->where('assistant_user_id', $user?->id);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('original_name', 'like', '%' . $search . '%')
                    ->orWhere('note', 'like', '%' . $search . '%')
                    ->orWhereHas('assistant', function ($assistantQuery) use ($search) {
                        $assistantQuery->where('nama_lengkap', 'like', '%' . $search . '%')
                            ->orWhere('id_user', 'like', '%' . $search . '%');
                    });
            });
        }

        $limit = max(1, min(200, (int) $request->query('limit', 100)));
        $rows = $query->limit($limit)->get()->map(fn (ScannedDocument $document) => $this->documentPayload($document));

        return response()->json([
            'status' => true,
            'message' => 'Dokumen scan berhasil dimuat.',
            'data' => $rows,
        ]);
    }

    public function download(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = $request->user();
        $document = ScannedDocument::query()->findOrFail($id);

        if (!$this->isAdminOrSuper($user) && (int) $document->assistant_user_id !== (int) optional($user)->id) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak untuk dokumen scan ini.',
                'data' => [],
            ], 403);
        }

        $path = public_path($document->posted_file_path ?: $document->file_path);
        if (!\App\Services\DocumentStorage::exists($path)) {
            return response()->json([
                'status' => false,
                'message' => 'File scan tidak ditemukan di server.',
                'data' => [],
            ], 404);
        }

        return \App\Services\DocumentStorage::response($path, $document->original_name, true);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = $request->user();
        $document = ScannedDocument::query()->findOrFail($id);

        if (!$this->isAdminOrSuper($user) && (int) $document->assistant_user_id !== (int) optional($user)->id) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak untuk menghapus dokumen scan ini.',
                'data' => [],
            ], 403);
        }

        if ($document->status === 'posted') {
            return response()->json([
                'status' => false,
                'message' => 'Dokumen scan yang sudah diposting tidak bisa dihapus dari menu scan.',
                'data' => [],
            ], 422);
        }

        $path = public_path($document->file_path);
        $document->delete();

        if (\App\Services\DocumentStorage::exists($path)) {
            \App\Services\DocumentStorage::delete($path);
        }

        return response()->json([
            'status' => true,
            'message' => 'Dokumen scan berhasil dihapus.',
            'data' => [],
        ]);
    }
}
