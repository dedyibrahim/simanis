<?php

namespace App\Services;

use App\Http\Controllers\PembuatanNomor;
use App\Models\DataClient;
use App\Models\PenghadapLegalisasi;
use App\Models\PenghadapNotaris;
use App\Models\PenghadapPPATS;
use App\Models\PenghadapWarmerking;
use App\Models\tb_berkas;
use App\Support\NumericValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class SearchService
{
    public function searchDocuments(?string $query = null): array
    {
        $clientDocuments = tb_berkas::query()
            ->leftJoin('data_clients', 'tb_berkas.id_client', '=', 'data_clients.id_client')
            ->when($query, function ($builder, string $query): void {
                $builder->where(function ($builder) use ($query): void {
                    $like = '%' . $query . '%';
                    $builder->where('tb_berkas.nama_dokumen', 'LIKE', $like)
                        ->orWhere('tb_berkas.nama_berkas', 'LIKE', $like)
                        ->orWhere('data_clients.nama_client', 'LIKE', $like)
                        ->orWhere('data_clients.no_identitas', 'LIKE', $like);
                });
            })
            ->orderByDesc('tb_berkas.created_at')->limit(500)
            ->get([
                DB::raw('tb_berkas.id_berkas as row_id'),
                'tb_berkas.id_client',
                'tb_berkas.nama_dokumen',
                'tb_berkas.nama_berkas',
                DB::raw("COALESCE(data_clients.nama_folder, CONCAT('Dok', tb_berkas.id_client)) AS nama_folder"),
                'tb_berkas.created_at',
                'data_clients.nama_client',
                'data_clients.jenis_client',
                'data_clients.no_identitas',
                DB::raw("'Dokumen Client' as document_category"),
                DB::raw("'/pencarian-dokumen' as module_path"),
                DB::raw("'client_document' as file_category"),
            ]);

        $documents = collect($clientDocuments);
        $documents = $documents->merge($this->standardBookDocuments('notaris', $query));
        $documents = $documents->merge($this->standardBookDocuments('legalisasi', $query));
        $documents = $documents->merge($this->standardBookDocuments('waarmerking', $query));
        $documents = $documents->merge($this->standardBookDocuments('ppat', $query));
        $documents = $documents->merge($this->letterDocuments('notaris', $query));
        $documents = $documents->merge($this->letterDocuments('ppat', $query));

        $availableKeys = $this->availableDocumentKeys();

        return $documents
            ->filter(static function ($document) use ($availableKeys): bool {
                $folder = trim((string) ($document->storage_folder ?? $document->nama_folder));
                $file = trim((string) $document->nama_berkas);
                $key = ($document->file_category ?? '') === 'client_document'
                    ? trim('berkasclient/'.$folder.'/'.$file, '/')
                    : trim($folder.'/'.$file, '/');

                return $folder !== '' && $file !== '' && isset($availableKeys[$key]);
            })
            ->sortByDesc('created_at')
            ->take(240)
            ->values()
            ->toArray();
    }

    private function standardBookDocuments(string $type, ?string $query)
    {
        $configs = [
            'notaris' => ['tb_dokumen_notaris', 'id_dokumen_notaris', 'id_buku_notaris', 'buku_notaris', 'penghadap_notaris', 'berkasnotaris', 'Akta Notaris', '/buku_akta', 'standard_notaris'],
            'legalisasi' => ['tb_dokumen_legalisasis', 'id_dokumen_legalisasi', 'id_buku_legalisasi', 'buku_legalisasis', 'penghadap_legalisasis', 'berkaslegalisasis', 'Legalisasi', '/buku_legalisasi', 'standard_legalisasi'],
            'waarmerking' => ['tb_dokumen_warmerkings', 'id_dokumen_warmerking', 'id_buku_warmerking', 'buku_warmerkings', 'penghadap_warmerkings', 'berkaswarmerkings', 'Waarmerking', '/buku_waarmerking', 'standard_waarmerking'],
            'ppat' => ['tb_dokumen_ppat', 'id_dokumen_ppat', 'id_buku_ppat', 'buku_ppats', 'penghadap_ppats', 'berkasppat', 'Akta PPAT', '/buku_ppat', 'standard_ppat'],
        ];
        [$table, $id, $bookId, $bookTable, $partyTable, $folder, $category, $module, $fileCategory] = $configs[$type];

        return DB::table($table)
            ->leftJoin($bookTable, "$table.$bookId", '=', "$bookTable.$bookId")
            ->leftJoin($partyTable, "$bookTable.$bookId", '=', "$partyTable.$bookId")
            ->leftJoin('data_clients', function ($join) use ($partyTable): void {
                $join->on("$partyTable.id_client", '=', 'data_clients.id_client')
                    ->orOn("$partyTable.id_mewakili", '=', 'data_clients.id_client');
            })
            ->when($query, function ($builder, string $query) use ($table): void {
                $like = '%'.$query.'%';
                $builder->where(function ($builder) use ($table, $like): void {
                    $builder->where("$table.nama_dokumen", 'LIKE', $like)
                        ->orWhere("$table.nama_berkas", 'LIKE', $like)
                        ->orWhere('data_clients.nama_client', 'LIKE', $like)
                        ->orWhere('data_clients.no_identitas', 'LIKE', $like);
                });
            })
            ->whereNotNull("$table.nama_berkas")
            ->select([
                DB::raw("$table.$id as row_id"), "$table.nama_dokumen", "$table.nama_berkas",
                "$table.created_at", 'data_clients.id_client', 'data_clients.nama_client',
                'data_clients.jenis_client', 'data_clients.no_identitas',
                DB::raw("'$folder' as storage_folder"), DB::raw("'$category' as document_category"),
                DB::raw("'$module' as module_path"), DB::raw("'$fileCategory' as file_category"),
            ])->distinct()->orderByDesc("$table.created_at")->limit(500)->get();
    }

    private function letterDocuments(string $type, ?string $query)
    {
        $table = $type === 'notaris' ? 'buku_surat_notaris' : 'buku_surat_ppats';
        $id = $type === 'notaris' ? 'id_surat_notaris' : 'id_surat_ppat';
        $folder = $type === 'notaris' ? 'suratnotaris' : 'suratppats';
        $category = $type === 'notaris' ? 'Surat Notaris' : 'Surat PPAT';
        $module = $type === 'notaris' ? '/buku_surat_notaris' : '/buku_surat_ppat';
        $fileCategory = $type === 'notaris' ? 'surat_notaris' : 'surat_ppat';

        return DB::table($table)->leftJoin('data_clients', "$table.id_client", '=', 'data_clients.id_client')
            ->when($query, function ($builder, string $query) use ($table): void {
                $like = '%'.$query.'%';
                $builder->where(function ($builder) use ($table, $like): void {
                    $builder->where("$table.file", 'LIKE', $like)->orWhere("$table.keterangan", 'LIKE', $like)
                        ->orWhere("$table.no_surat", 'LIKE', $like)->orWhere('data_clients.nama_client', 'LIKE', $like)
                        ->orWhere('data_clients.no_identitas', 'LIKE', $like);
                });
            })->whereNotNull("$table.file")->where("$table.file", '<>', '')
            ->select([
                DB::raw("$table.$id as row_id"), DB::raw("COALESCE($table.keterangan, 'Surat') as nama_dokumen"),
                DB::raw("$table.file as nama_berkas"), "$table.created_at", 'data_clients.id_client',
                'data_clients.nama_client', 'data_clients.jenis_client', 'data_clients.no_identitas',
                DB::raw("'$folder' as storage_folder"), DB::raw("'$category' as document_category"),
                DB::raw("'$module' as module_path"), DB::raw("'$fileCategory' as file_category"),
            ])->orderByDesc("$table.created_at")->limit(500)->get();
    }

    private function availableDocumentKeys(): array
    {
        return Cache::remember('search.available-document-keys.v2', now()->addMinutes(5), static function (): array {
            $diskName = config('filesystems.documents_disk', 'documents_local');
            $diskConfig = config('filesystems.disks.'.$diskName);
            $folders = ['berkasclient', 'berkasnotaris', 'berkaslegalisasis', 'berkaswarmerkings', 'berkasppat', 'suratnotaris', 'suratppats'];

            if (is_array($diskConfig) && !empty($diskConfig['driver'])) {
                $keys = [];
                foreach ($folders as $folder) {
                    $keys += array_fill_keys(Storage::disk($diskName)->allFiles($folder), true);
                }
                return $keys;
            }

            $keys = [];
            foreach ($folders as $folder) {
                $root = public_path($folder);
                if (!is_dir($root)) continue;
                foreach (File::allFiles($root) as $file) {
                    $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                    $keys[$folder.'/'.$relativePath] = true;
                }
            }

            return $keys;
        });
    }

    public function searchDataClient(?string $query = null): array
    {
        if ($query) {
            $dataClient = DataClient::where('nama_client', 'LIKE', '%' . $query . '%')
                ->select('nama_client', 'id_client', 'jenis_client', 'no_identitas')
                ->limit(15)
                ->get();
        } else {
            $dataClient = DataClient::orderBy('id_client', 'desc')
                ->limit(15)
                ->select('nama_client', 'id_client', 'jenis_client', 'no_identitas')
                ->get();
        }

        $result = [];
        foreach ($dataClient as $client) {
            $result[] = [
                'nama_client' => ucwords($client->nama_client),
                'id_client' => $client->id_client,
                'jenis_client' => $client->jenis_client,
                'no_identitas' => $client->no_identitas,
                'akta_notaris' => PenghadapNotaris::where('id_client', $client->id_client)->orWhere('id_mewakili', $client->id_client)->count(),
                'legalisasi' => PenghadapLegalisasi::where('id_client', $client->id_client)->orWhere('id_mewakili', $client->id_client)->count(),
                'warmerking' => PenghadapWarmerking::where('id_client', $client->id_client)->orWhere('id_mewakili', $client->id_client)->count(),
                'akta_ppat' => PenghadapPPATS::where('id_client', $client->id_client)->orWhere('id_mewakili', $client->id_client)->count(),
            ];
        }

        return $result;
    }

    public function getPencarianBukuNotaris(string $idClient): array
    {
        $query = DB::table('buku_notaris')
            ->leftJoin('daftar_aktas', 'buku_notaris.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftJoin('penghadap_notaris', 'buku_notaris.id_buku_notaris', '=', 'penghadap_notaris.id_buku_notaris')
            ->leftJoin('users', 'buku_notaris.id_user', '=', 'users.id_user')
            ->select(
                'buku_notaris.id_buku_notaris',
                'buku_notaris.nama_client',
                'buku_notaris.judul_pekerjaan',
                'buku_notaris.status_akta',
                'users.nama_lengkap',
                'users.id_user',
                'daftar_aktas.nama_akta',
                'buku_notaris.tgl_akta',
                'buku_notaris.no_akta'
            )
            ->orderByDesc('buku_notaris.id_buku_notaris')
            ->where('penghadap_notaris.id_client', $idClient)
            ->orWhere('penghadap_notaris.id_mewakili', $idClient)
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_notaris' => $r->id_buku_notaris,
                'nama_akta' => $r->nama_akta,
                'judul_pekerjaan' => $r->judul_pekerjaan,
                'tgl_akta' => $r->tgl_akta,
                'no_akta' => $r->no_akta,
                'status_akta' => $r->status_akta,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $p->getDataPenghadapNotaris($r->id_buku_notaris),
            ];
        }

        return $data;
    }

    public function getPencarianBukuWarmerking(string $idClient): array
    {
        $query = DB::table('buku_warmerkings')
            ->leftJoin('users', 'buku_warmerkings.id_user', '=', 'users.id_user')
            ->leftJoin('penghadap_warmerkings', 'buku_warmerkings.id_buku_warmerking', '=', 'penghadap_warmerkings.id_buku_warmerking')
            ->select(
                'buku_warmerkings.id_buku_warmerking',
                'buku_warmerkings.status_warmerking',
                'buku_warmerkings.keterangan_surat',
                'buku_warmerkings.no_warmerking',
                'users.nama_lengkap',
                'users.id_user',
                'buku_warmerkings.tgl_didaftarkan',
                'buku_warmerkings.judul_surat'
            )
            ->orderByDesc('buku_warmerkings.id_buku_warmerking')
            ->where('penghadap_warmerkings.id_client', $idClient)
            ->orWhere('penghadap_warmerkings.id_mewakili', $idClient)
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $keterangan = $r->keterangan_surat ?: null;
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_warmerking' => $r->id_buku_warmerking,
                'judul_surat' => $r->judul_surat,
                'tgl_didaftarkan' => $r->tgl_didaftarkan,
                'no_warmerking' => $p->ProsesNomorWarmerking($r->no_warmerking, $r->tgl_didaftarkan, $keterangan),
                'status_warmerking' => $r->status_warmerking,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $p->getDataPenghadapWarmerking($r->id_buku_warmerking),
            ];
        }

        return $data;
    }

    public function getPencarianBukuLegalisasi(string $idClient): array
    {
        $query = DB::table('buku_legalisasis')
            ->leftJoin('users', 'buku_legalisasis.id_user', '=', 'users.id_user')
            ->leftJoin('penghadap_legalisasis', 'buku_legalisasis.id_buku_legalisasi', '=', 'penghadap_legalisasis.id_buku_legalisasi')
            ->select(
                'buku_legalisasis.id_buku_legalisasi',
                'buku_legalisasis.status_legalisasi',
                'buku_legalisasis.keterangan_surat',
                'buku_legalisasis.no_legalisasi',
                'users.nama_lengkap',
                'users.id_user',
                'buku_legalisasis.tgl_surat',
                'buku_legalisasis.judul_surat'
            )
            ->where('id_client', $idClient)
            ->orWhere('id_mewakili', $idClient)
            ->orderByDesc('buku_legalisasis.id_buku_legalisasi')
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $keterangan = $r->keterangan_surat ?: null;
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_legalisasi' => $r->id_buku_legalisasi,
                'judul_surat' => $r->judul_surat,
                'tgl_surat' => $r->tgl_surat,
                'no_legalisasi' => $p->ProsesNomorLegalisasi($r->no_legalisasi, $r->tgl_surat, $keterangan),
                'status_legalisasi' => $r->status_legalisasi,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $p->getDataPenghadapLegalisasi($r->id_buku_legalisasi),
            ];
        }

        return $data;
    }

    public function getPencarianBukuPPAT(string $idClient): array
    {
        $query = DB::table('buku_ppats')
            ->leftJoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftJoin('penghadap_ppats', 'buku_ppats.id_buku_ppat', '=', 'penghadap_ppats.id_buku_ppat')
            ->leftJoin('users', 'buku_ppats.id_user', '=', 'users.id_user')
            ->where('penghadap_ppats.id_client', $idClient)
            ->orWhere('penghadap_ppats.id_mewakili', $idClient)
            ->select(
                'buku_ppats.id_buku_ppat',
                'buku_ppats.status_akta',
                'users.nama_lengkap',
                'users.id_user',
                'daftar_aktas.nama_akta',
                'buku_ppats.tanggal_akta',
                'buku_ppats.no_akta',
                'buku_ppats.no_hak_milik',
                'buku_ppats.luas_tanah_bangunan',
                'buku_ppats.luas_tanah',
                'buku_ppats.luas_bangunan',
                'buku_ppats.harga_transaksi',
                'buku_ppats.nop',
                'buku_ppats.harga_njop',
                'buku_ppats.tgl_bphtb',
                'buku_ppats.harga_bphtb',
                'buku_ppats.tgl_pph',
                'buku_ppats.harga_pph',
                'buku_ppats.keterangan'
            )
            ->orderByDesc('buku_ppats.id_buku_ppat')
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $barcode = 'Dewantari Handayani, S.H.,MPA ' . $r->nama_akta . ' Akta No.' . $r->no_akta . ' Tanggal.' . $r->tanggal_akta;
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_ppat' => $r->id_buku_ppat,
                'nama_akta' => $r->nama_akta,
                'tanggal_akta' => $r->tanggal_akta,
                'no_akta' => $r->no_akta,
                'status_akta' => $r->status_akta,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $p->getDataPenghadapPPAT($r->id_buku_ppat),
                'no_hak_milik' => $r->no_hak_milik,
                'luas_tanah' => number_format(NumericValue::fromMixed($r->luas_tanah)),
                'luas_bangunan' => number_format(NumericValue::fromMixed($r->luas_bangunan)),
                'harga_transaksi' => 'Rp. ' . number_format(NumericValue::fromMixed($r->harga_transaksi)),
                'nop' => $r->nop,
                'harga_njop' => 'Rp. ' . number_format(NumericValue::fromMixed($r->harga_njop)),
                'tgl_bphtb' => $r->tgl_bphtb,
                'harga_bphtb' => 'Rp. ' . number_format(NumericValue::fromMixed($r->harga_bphtb)),
                'tgl_pph' => $r->tgl_pph,
                'harga_pph' => 'Rp. ' . number_format(NumericValue::fromMixed($r->harga_pph)),
                'keterangan' => $r->keterangan,
                'barcode' => base64_encode(QrCode::format('svg')->margin(3)->size(250)->generate($barcode)),
            ];
        }

        return $data;
    }

    public function getDokumenClient(string $idClient): array
    {
        $data = tb_berkas::where('tb_berkas.id_client', $idClient)
            ->orderBy('tb_berkas.id_berkas', 'DESC')
            ->leftJoin('data_clients', 'tb_berkas.id_client', '=', 'data_clients.id_client')
            ->get()
            ->toArray();

        return [
            'data_client' => DataClient::where('id_client', $idClient)->select('nama_client')->first(),
            'data' => $data,
        ];
    }
}
