<?php

namespace App\Http\Controllers;

use App\Models\DataClient;
use App\Models\PenyimpananBantek;
use App\Models\tb_berkas;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ClientController extends ApiController
{
    private function assertInternalApiKey(Request $request)
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            abort(response()->json(['status' => false, 'message' => 'Unauthorized.'], 401));
        }
    }

    public function searchForChatbot(Request $request)
    {
        $this->assertInternalApiKey($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'jenis_client' => ['nullable', 'string', 'in:Perorangan,Badan Hukum'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $keyword = trim((string) $validated['q']);
        $jenisClient = trim((string) ($validated['jenis_client'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 10);

        $query = DataClient::query()
            ->leftJoin('users', 'data_clients.pembuat_client', '=', 'users.id_user')
            ->select(
                'data_clients.id_client',
                'data_clients.no_identitas',
                'data_clients.nama_client',
                'data_clients.jenis_client',
                'data_clients.alamat_client',
                'data_clients.contact_number',
                'data_clients.email',
                'users.nama_lengkap as pembuat_client'
            )
            ->where(function ($q) use ($keyword) {
                $q->where('data_clients.nama_client', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.no_identitas', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.alamat_client', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.contact_number', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.email', 'like', '%'.$keyword.'%');
            });

        if ($jenisClient !== '') {
            $query->where('data_clients.jenis_client', $jenisClient);
        }

        $rows = $query
            ->orderBy('data_clients.id_client', 'desc')
            ->limit($limit)
            ->get();

        $clientIds = $rows->pluck('id_client')->filter()->unique()->values();
        $bantekRows = collect();
        if ($clientIds->isNotEmpty()) {
            $bantekRows = PenyimpananBantek::query()
                ->whereIn('id_client', $clientIds->all())
                ->get(['id_client', 'no_bantek', 'lokasi_bantek']);
        }

        $bantekByClient = $bantekRows
            ->groupBy('id_client')
            ->map(function ($items) {
                return $items
                    ->map(function ($row) {
                        return [
                            'no_bantek' => (string) $row->no_bantek,
                            'lokasi_bantek' => (string) ($row->lokasi_bantek ?? ''),
                        ];
                    })
                    ->unique(function ($item) {
                        return ($item['no_bantek'] ?? '').'|'.($item['lokasi_bantek'] ?? '');
                    })
                    ->values()
                    ->all();
            });

        $enrichedRows = $rows->map(function ($row) use ($bantekByClient) {
            $key = (string) ($row->id_client ?? '');
            $dataBantek = $key !== '' ? ($bantekByClient->get($key, [])) : [];

            return [
                'id_client' => $row->id_client,
                'no_identitas' => $row->no_identitas,
                'nama_client' => $row->nama_client,
                'jenis_client' => $row->jenis_client,
                'alamat_client' => $row->alamat_client,
                'contact_number' => $row->contact_number,
                'email' => $row->email,
                'pembuat_client' => $row->pembuat_client,
                'data_bantek' => $dataBantek,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => $enrichedRows->isEmpty()
                ? 'Data client tidak ditemukan.'
                : 'Berhasil menemukan data client.',
            'data' => $enrichedRows,
        ], 200);
    }

    public function getDataClient(Request $request)
    {
        $validated = $request->validate([
            'jenis_client' => ['required', 'string', 'in:Perorangan,Badan Hukum'],
            'server_side' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'search' => ['nullable', 'string', 'max:150'],
            'search_field' => ['nullable', 'string', 'in:all,name,identity,creator,contact'],
            'sort_by' => ['nullable', 'string', 'in:id_client,nama_client,no_identitas,pembuat_client'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = DataClient::query()
            ->where('data_clients.jenis_client', $validated['jenis_client'])
            ->leftJoin('users', 'data_clients.pembuat_client', '=', 'users.id_user')
            ->select(
                'data_clients.id_client',
                'data_clients.no_identitas',
                'data_clients.nama_client',
                'data_clients.jenis_client',
                'data_clients.alamat_client',
                'users.nama_lengkap as pembuat_client',
                'data_clients.nama_folder',
                'data_clients.contact_number',
                'data_clients.email'
            );

        if (!($validated['server_side'] ?? false)) {
            $data = $query->orderBy('data_clients.id_client', 'desc')->get();

            return $this->successResponse(
                $this->withClientBantek($data),
                'Berhasil mengambil data client'
            );
        }

        $total = (clone $query)->count('data_clients.id_client');
        $keyword = trim((string) ($validated['search'] ?? ''));
        $searchField = $validated['search_field'] ?? 'all';

        if ($keyword !== '') {
            $query->where(function ($searchQuery) use ($keyword, $searchField) {
                $columns = [
                    'name' => ['data_clients.nama_client'],
                    'identity' => ['data_clients.no_identitas'],
                    'creator' => ['users.nama_lengkap'],
                    'contact' => ['data_clients.email', 'data_clients.contact_number'],
                    'all' => [
                        'data_clients.nama_client',
                        'data_clients.no_identitas',
                        'users.nama_lengkap',
                        'data_clients.email',
                        'data_clients.contact_number',
                    ],
                ][$searchField];

                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $searchQuery->{$method}($column, 'like', '%'.$keyword.'%');
                }
            });
        }

        $filtered = (clone $query)->count('data_clients.id_client');
        $sortColumns = [
            'id_client' => 'data_clients.id_client',
            'nama_client' => 'data_clients.nama_client',
            'no_identitas' => 'data_clients.no_identitas',
            'pembuat_client' => 'users.nama_lengkap',
        ];
        $sortBy = $validated['sort_by'] ?? 'id_client';
        $sortDirection = $validated['sort_direction'] ?? 'desc';
        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);
        $lastPage = max(1, (int) ceil($filtered / $perPage));
        $page = min($page, $lastPage);

        $data = $query
            ->orderBy($sortColumns[$sortBy], $sortDirection)
            ->orderBy('data_clients.id_client', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Berhasil mengambil data client',
            'data' => [
                'rows' => $this->withClientBantek($data),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'filtered' => $filtered,
                    'last_page' => $lastPage,
                ],
            ],
        ], 200);
    }

    private function withClientBantek($data): array
    {
        $clientIds = $data->pluck('id_client')->filter()->unique()->values();
        $bantekByClient = $clientIds->isEmpty()
            ? collect()
            : PenyimpananBantek::query()
                ->whereIn('id_client', $clientIds->all())
                ->get()
                ->groupBy('id_client');

        return $data->map(function ($row) use ($bantekByClient) {
            return [
                'id_client' => $row->id_client,
                'no_identitas' => $row->no_identitas,
                'nama_client' => $row->nama_client,
                'jenis_client' => $row->jenis_client,
                'alamat_client' => $row->alamat_client,
                'pembuat_client' => $row->pembuat_client,
                'nama_folder' => $row->nama_folder,
                'contact_number' => $row->contact_number,
                'email' => $row->email,
                'data_bantek' => $bantekByClient->get($row->id_client, collect())->values()->toArray(),
            ];
        })->values()->all();
    }

    public function checkClientIdentity(Request $request)
    {
        $validated = $request->validate([
            'no_identitas' => ['required', 'string', 'max:100'],
            'jenis_client' => ['nullable', 'string', 'in:Perorangan,Badan Hukum'],
            'id_client' => ['nullable', 'string', 'max:20'],
        ]);

        $query = DataClient::query()
            ->leftJoin('users', 'data_clients.pembuat_client', '=', 'users.id_user')
            ->where('data_clients.no_identitas', $validated['no_identitas']);

        if (!empty($validated['jenis_client'])) {
            $query->where('data_clients.jenis_client', $validated['jenis_client']);
        }

        if (!empty($validated['id_client'])) {
            $query->where('data_clients.id_client', '!=', $validated['id_client']);
        }

        $client = $query
            ->select(
                'data_clients.id_client',
                'data_clients.no_identitas',
                'data_clients.nama_client',
                'data_clients.jenis_client',
                'data_clients.alamat_client',
                'data_clients.contact_number',
                'data_clients.email',
                'users.nama_lengkap as pembuat_client'
            )
            ->first();

        return response()->json([
            'status' => true,
            'message' => $client ? 'Nomor identitas sudah terdaftar.' : 'Nomor identitas tersedia.',
            'data' => [
                'exists' => (bool) $client,
                'client' => $client,
            ],
        ], 200);
    }

    public function SimpanClientBaru(Request $request)
    {
        if ($request->post('id_client')) {
            $request->validate([
                'no_identitas' => 'required',
                'nama_client' => 'required|',
            ]);

            $duplicateClient = DataClient::query()
                ->where('no_identitas', $request->post('no_identitas'))
                ->where('id_client', '!=', $request->post('id_client'))
                ->first(['id_client', 'nama_client']);

            if ($duplicateClient) {
                return $this->errorResponse(
                    [
                        'id_client' => $duplicateClient->id_client,
                        'nama_client' => $duplicateClient->nama_client,
                    ],
                    'Nomor identitas sudah digunakan oleh client lain.',
                    422
                );
            }

            $data = [
            'nama_client' => $request->post('nama_client'),
            'no_identitas' => $request->post('no_identitas'),
            'jenis_client' => $request->post('jenis_client'),
            'alamat_client' => $request->post('alamat_client'),
            'pembuat_client' => auth()->user()->id_user,
            'email' => $request->post('email'),
            'contact_number' => $request->post('contact_number'),
            ];

            DataClient::where('id_client', $request->post('id_client'))->update($data);

            $response = [
                'status' => true,
                'message' => 'Update Client Berhasil',
                'data' => [],
            ];

            return response($response, 200);
        } else {
            $request->validate([
                'no_identitas' => 'required',
                'nama_client' => 'required|',
            ]);

            $duplicateClient = DataClient::query()
                ->where('no_identitas', $request->post('no_identitas'))
                ->first(['id_client', 'nama_client']);

            if ($duplicateClient) {
                return $this->errorResponse(
                    [
                        'id_client' => $duplicateClient->id_client,
                        'nama_client' => $duplicateClient->nama_client,
                    ],
                    'Nomor identitas sudah terdaftar.',
                    422
                );
            }

            DB::transaction(function () use ($request) {
                $id_client = $this->nextClientId();

                if (!file_exists('berkasclient/Dok'.$id_client)) {
                    mkdir('berkasclient/Dok'.$id_client, 0777);
                }

                $data = [
                'id_client' => $id_client,
                'nama_client' => $request->post('nama_client'),
                'no_identitas' => $request->post('no_identitas'),
                'jenis_client' => $request->post('jenis_client'),
                'alamat_client' => $request->post('alamat_client'),
                'pembuat_client' => auth()->user()->id_user,
                'email' => $request->post('email'),
                'nama_folder' => 'Dok'.$id_client,
                'contact_number' => $request->post('contact_number'),
                ];

                DataClient::create($data);
            });
            $response = [
               'status' => true,
               'message' => 'Add New Client Successfully',
               'data' => [],
    ];

            return response($response, 200);
        }
    }

    public function confirmKtpOcrFromChatbot(Request $request)
    {
        $this->assertInternalApiKey($request);

        $validated = $request->validate([
            'ktp_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:10240'],
            'ocr_result' => ['required', 'string'],
            'source' => ['nullable', 'string', 'max:50'],
            'sender_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $ocrResult = json_decode($validated['ocr_result'], true);
        if (!is_array($ocrResult)) {
            return response()->json(['status' => false, 'message' => 'Payload OCR tidak valid.'], 422);
        }

        $fields = is_array($ocrResult['fields'] ?? null) ? $ocrResult['fields'] : [];
        $nik = $this->fieldValue($fields, 'nik');
        $nama = $this->fieldValue($fields, 'nama');

        if (!preg_match('/^\d{16}$/', $nik)) {
            return response()->json(['status' => false, 'message' => 'NIK hasil OCR wajib 16 digit sebelum disimpan.'], 422);
        }

        if ($nama === '') {
            return response()->json(['status' => false, 'message' => 'Nama client belum terbaca dari OCR.'], 422);
        }

        $alamat = $this->buildKtpAddress($fields);
        $userId = $this->resolveChatbotUserId();
        $file = $request->file('ktp_image');

        $result = DB::transaction(function () use ($nik, $nama, $alamat, $userId, $file) {
            $client = DataClient::query()->where('no_identitas', $nik)->lockForUpdate()->first();
            $created = false;

            if (!$client) {
                $idClient = $this->nextClientId();
                $client = DataClient::create([
                    'id_client' => $idClient,
                    'nama_client' => $nama,
                    'no_identitas' => $nik,
                    'jenis_client' => 'Perorangan',
                    'alamat_client' => $alamat,
                    'pembuat_client' => $userId,
                    'email' => null,
                    'nama_folder' => 'Dok'.$idClient,
                    'contact_number' => null,
                ]);
                $created = true;
            } else {
                $updates = [];
                if (!$client->nama_client) {
                    $updates['nama_client'] = $nama;
                }
                if (!$client->jenis_client) {
                    $updates['jenis_client'] = 'Perorangan';
                }
                if (!$client->alamat_client && $alamat !== '') {
                    $updates['alamat_client'] = $alamat;
                }
                if (!$client->nama_folder) {
                    $updates['nama_folder'] = 'Dok'.$client->id_client;
                }
                if ($updates) {
                    $client->fill($updates);
                    $client->save();
                }
            }

            $berkas = $this->attachKtpFileToClient($client, $file, $userId);

            return [
                'result' => $created ? 'created_new' : 'attached_existing',
                'client' => $client->fresh(),
                'berkas' => $berkas,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => $result['result'] === 'created_new'
                ? 'Client baru berhasil dibuat dan KTP berhasil disimpan.'
                : 'Client sudah ada. KTP berhasil ditambahkan.',
            'data' => [
                'result' => $result['result'],
                'client' => [
                    'id_client' => $result['client']->id_client,
                    'no_identitas' => $result['client']->no_identitas,
                    'nama_client' => $result['client']->nama_client,
                    'jenis_client' => $result['client']->jenis_client,
                    'alamat_client' => $result['client']->alamat_client,
                ],
                'berkas' => $result['berkas'],
            ],
        ], 200);
    }

    public function extractKtpOcr(Request $request)
    {
        $validated = $request->validate([
            'ktp_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:10240'],
        ]);

        $file = $validated['ktp_image'];
        $baseUrl = rtrim((string) env('KTP_OCR_BASE_URL', 'http://127.0.0.1:8765'), '/');

        try {
            $response = Http::timeout(max(15, (int) ceil(((int) env('KTP_OCR_TIMEOUT_MS', 90000)) / 1000)))
                ->attach('image', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post($baseUrl.'/api/ocr', [
                    'config_name' => (string) env('KTP_OCR_CONFIG', 'auto-best'),
                    'engine' => (string) env('KTP_OCR_ENGINE', 'paddleocr'),
                ]);
        } catch (\Throwable $error) {
            return response()->json([
                'status' => false,
                'message' => 'OCR service tidak dapat dihubungi: '.$error->getMessage(),
            ], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'status' => false,
                'message' => (string) ($response->json('detail') ?: 'OCR service gagal memproses KTP.'),
            ], 502);
        }

        return response()->json([
            'status' => true,
            'message' => 'KTP berhasil dibaca. Periksa kembali seluruh field sebelum disimpan.',
            'data' => $response->json(),
        ]);
    }

    public function saveKtpOcrClient(Request $request)
    {
        $validated = $request->validate([
            'ktp_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:10240'],
            'no_identitas' => ['required', 'digits:16'],
            'nama_client' => ['required', 'string', 'max:255'],
            'jenis_client' => ['required', 'string', 'in:Perorangan,Badan Hukum'],
            'alamat_client' => ['nullable', 'string'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $file = $validated['ktp_image'];
        $userId = (string) auth()->user()->id_user;

        $result = DB::transaction(function () use ($validated, $file, $userId) {
            $client = DataClient::query()->where('no_identitas', $validated['no_identitas'])->lockForUpdate()->first();
            $created = false;

            if (!$client) {
                $idClient = $this->nextClientId();
                $client = DataClient::create([
                    'id_client' => $idClient,
                    'nama_client' => trim($validated['nama_client']),
                    'no_identitas' => $validated['no_identitas'],
                    'jenis_client' => $validated['jenis_client'],
                    'alamat_client' => trim((string) ($validated['alamat_client'] ?? '')),
                    'pembuat_client' => $userId,
                    'email' => $validated['email'] ?? null,
                    'nama_folder' => 'Dok'.$idClient,
                    'contact_number' => $validated['contact_number'] ?? null,
                ]);
                $created = true;
            }

            $berkas = $this->attachKtpFileToClient($client, $file, $userId);

            return ['created' => $created, 'client' => $client->fresh(), 'berkas' => $berkas];
        });

        return response()->json([
            'status' => true,
            'message' => $result['created']
                ? 'Client baru dan dokumen KTP berhasil disimpan.'
                : 'NIK sudah terdaftar. KTP berhasil ditambahkan ke client yang ada.',
            'data' => [
                'result' => $result['created'] ? 'created_new' : 'attached_existing',
                'client' => $result['client'],
                'berkas' => $result['berkas'],
            ],
        ]);
    }

    private function fieldValue(array $fields, string $key): string
    {
        return trim((string) ($fields[$key]['value'] ?? ''));
    }

    private function buildKtpAddress(array $fields): string
    {
        $parts = [];
        foreach (['alamat', 'rt_rw', 'kel_desa', 'kecamatan'] as $key) {
            $value = $this->fieldValue($fields, $key);
            if ($value !== '') {
                $parts[] = $key === 'rt_rw' ? 'RT/RW '.$value : $value;
            }
        }

        return implode(', ', $parts);
    }

    private function resolveChatbotUserId(): string
    {
        $configured = trim((string) env('CHATBOT_DEFAULT_USER_ID', ''));
        if ($configured !== '' && User::query()->where('id_user', $configured)->exists()) {
            return $configured;
        }

        $user = User::query()->orderBy('id')->first(['id_user']);
        if ($user) {
            return $user->id_user;
        }

        abort(response()->json(['status' => false, 'message' => 'User default untuk chatbot belum tersedia.'], 422));
    }

    private function nextClientId(): string
    {
        $counter = DB::table('number_counters')
            ->where('key_name', 'client')
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            $lastNumber = $this->maxClientNumber();
            DB::table('number_counters')->insert([
                'key_name' => 'client',
                'last_number' => $lastNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $counter = (object) ['last_number' => $lastNumber];
        }

        $urutan = max((int) $counter->last_number, $this->maxClientNumber()) + 1;

        DB::table('number_counters')
            ->where('key_name', 'client')
            ->update([
                'last_number' => $urutan,
                'updated_at' => now(),
            ]);

        return 'C'.str_pad($urutan, 6, '0', STR_PAD_LEFT);
    }

    private function maxClientNumber(): int
    {
        return (int) (DB::table('data_clients')
            ->selectRaw("MAX(CAST(SUBSTRING(id_client, 2) AS UNSIGNED)) as max_number")
            ->value('max_number') ?? 0);
    }

    private function nextBerkasId(): string
    {
        $berkas = DB::table('tb_berkas')->orderBy('id_berkas', 'desc')->lockForUpdate()->first();
        $urutan = isset($berkas->id_berkas) ? ((int) substr($berkas->id_berkas, 10) + 1) : 1;

        return 'BK'.date('Ymd').str_pad($urutan, 10, '0', STR_PAD_LEFT);
    }

    private function attachKtpFileToClient(DataClient $client, $file, string $userId): array
    {
        $folder = $client->nama_folder ?: 'Dok'.$client->id_client;
        $targetDirectory = public_path('berkasclient/'.$folder);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'jpg';
        $safeName = 'KTP-'.$client->no_identitas.'-'.date('Ymd-His').'-'.Str::random(6).'.'.$extension;
        $file->move($targetDirectory, $safeName);

        $berkas = tb_berkas::create([
            'id_berkas' => $this->nextBerkasId(),
            'id_client' => $client->id_client,
            'id_dokumen' => null,
            'id_user' => $userId,
            'nama_berkas' => $safeName,
            'nama_dokumen' => 'KTP - '.$client->nama_client,
        ]);

        return [
            'id_berkas' => $berkas->id_berkas,
            'nama_berkas' => $berkas->nama_berkas,
            'nama_dokumen' => $berkas->nama_dokumen,
        ];
    }
}
