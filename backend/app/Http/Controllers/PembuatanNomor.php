<?php

namespace App\Http\Controllers;

use App\Imports\ImportPPAT;
use App\Models\BukuLegalisasi;
use App\Models\BukuNotaris;
use App\Models\BukuPPATS;
use App\Models\BukuSuratNotaris;
use App\Models\BukuSuratPPAT;
use App\Models\BukuWarmerking;
use App\Models\DaftarAktas;
use App\Models\DataClient;
use App\Models\detail_pesanan;
use App\Models\invoice_non_taxs;
use App\Models\invoice_taxs;
use App\Models\orders;
use App\Models\PenghadapLegalisasi;
use App\Models\PenghadapNotaris;
use App\Models\PenghadapPPATS;
use App\Models\PenghadapWarmerking;
use App\Models\User;
use App\Models\tb_dokumen_ppat;
use App\Models\PenyimpananBantek;
use App\Services\Waha\WahaClient;
use App\Support\NumericValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PembuatanNomor extends ApiController
{
    public function getDaftarAkta(Request $request)
    {
        $data = DaftarAktas::where('nama_akta', 'like', '%'.$request->post('judul_akta').'%')->where('pekerjaan_milik', $request->post('tipe'))->limit(15)->get();
        $result = [];

        foreach ($data as $r) {
            $result[] = [
                'nama_akta' => $r->nama_akta,
                'id_akta' => $r->id_akta,
                'apht' => $r->apht,
            ];
        }

        return $this->successResponse($result, 'Berhasil menampilkan daftar akta');
    }

    public function getDaftarClient(Request $request)
    {
        if (strlen($request->post('nama_penghadap')) > 3) {
            $data = DataClient::where('nama_client', 'LIKE', '%'.$request->post('nama_penghadap').'%')->limit(15)->get();
            $result = [];
            foreach ($data as $r) {
                $result[] = [
                    'nama_pencarian' => $r->nama_client.' - '.$r->no_identitas,
                    'nama_client' => $r->nama_client,
                    'jenis_client' => $r->jenis_client,
                    'no_identitas' => $r->no_identitas,
                    'id_client' => $r->id_client,
                    'status_kedudukan' => '',
                    'mewakili' => '',
                ];
            }
        } else {
            $result = [];
        }

        return $this->successResponse($result, 'Berhasil menampilkan daftar client');
    }

    public function SimpanNomorNotaris(Request $request)
    {
        $b = $request->input('data_buku');
        $request->validate([
            'data_buku.jenis_akta' => ['required', 'exists:daftar_aktas,id_akta'],
            'data_buku.judul_pekerjaan' => ['required'],
            'data_buku.tgl_akta' => ['required', 'date'],
        ]);

        $levelUser = auth()->user()->level_user;

        if (in_array($levelUser, ['Admin', 'Super Admin'])) {
            $id_user_input = (string) ($b['nama_asisten'] ?? '');

            if ($id_user_input === '') {
                return $this->errorResponse(null, 'Nama asisten wajib dipilih.', 422);
            }

            $asistenExists = DB::table('users')
                ->where('id_user', $id_user_input)
                ->exists();

            if (!$asistenExists) {
                return $this->errorResponse(null, 'Asisten yang dipilih tidak valid.', 422);
            }
        } else {
            $id_user_input = auth()->user()->id_user;
        }


        if (!array_key_exists('id_buku_notaris', $b)) {
            $latestTanggalAkta = DB::table('buku_notaris')->max('tgl_akta');
            if (!empty($latestTanggalAkta)) {
                $tanggalInput = strtotime((string) $b['tgl_akta']);
                $tanggalTerakhir = strtotime((string) $latestTanggalAkta);

                if ($tanggalInput !== false && $tanggalTerakhir !== false && $tanggalInput < $tanggalTerakhir) {
                    return $this->errorResponse(
                        null,
                        'Tanggal akta tidak boleh mundur dari tanggal terakhir: '.$latestTanggalAkta,
                        422
                    );
                }
            }

            $request->validate([
                'data_buku.sudah_tanda_tangan' => ['accepted'],
            ], [
                'data_buku.sudah_tanda_tangan.accepted' => 'Dokumen harus ditandatangani terlebih dahulu sebelum mengambil nomor.',
            ]);

            if (auth()->user()->level_user == 'Arsip') {
                return $this->errorResponse(null, 'Your User Canot Create Akta', 502);
            } else {
                $buku = DB::table('buku_notaris')
                    ->orderBy('id_buku_notaris', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($buku->id_buku_notaris)) {
                    $urutan = (int) substr($buku->id_buku_notaris, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_buku_notaris = 'BKN'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $data_buku = [
                    'id_buku_notaris' => $id_buku_notaris,
                    'id_akta' => $b['jenis_akta'],
                    'judul_pekerjaan' => $b['judul_pekerjaan'],
                    'id_user' =>  $id_user_input,
                    'tgl_akta' => $b['tgl_akta'],
                    'no_akta' => $this->PembuatanNomorNotaris($b['tgl_akta']),
                    'status_akta' => 'Proses',
                    'tgl_signing' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                    'created_by' => auth()->user()->id_user,
                ];

                BukuNotaris::create($data_buku);

                foreach ($request->input('penghadap') as $a) {
                    $daftarpenghadap = DB::table('penghadap_notaris')
                        ->orderBy('id_penghadap_notaris', 'desc')
                        ->limit(1)
                        ->first();

                    if (isset($daftarpenghadap->id_penghadap_notaris)) {
                        $number = preg_replace('/\D/', '', $daftarpenghadap->id_penghadap_notaris);
                        // $urutan = (int) substr($daftarpenghadap->id_penghadap_notaris, 7) + 1;
                        $urutan = intval($number) + 1;
                    } else {
                        $urutan = 1;
                    }

                    $id_penghadap = 'PHN'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                    if (isset($a['id_mewakili'])) {
                        $id_mewakili = $a['id_mewakili'];
                    } else {
                        $id_mewakili = null;
                    }

                    $penghadap = [
                        'id_penghadap_notaris' => $id_penghadap,
                        'id_buku_notaris' => $id_buku_notaris,
                        'id_client' => $a['id_client'],
                        'kedudukan' => $a['status_kedudukan'],
                        'id_mewakili' => $id_mewakili,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    PenghadapNotaris::create($penghadap);
                }

                $this->notifyNumberTaken(
                    'Buku Akta (Notaris)',
                    (string) $data_buku['no_akta'],
                    (string) $id_buku_notaris,
                    (string) $data_buku['tgl_akta'],
                    (string) $data_buku['judul_pekerjaan'],
                    (string) $data_buku['id_user'],
                    (array) $request->input('penghadap', [])
                );

                return $this->successResponse(null, 'Berhasil membuat nomor akta'.$urutan);
            }
        } else {
            $tanggalSebelumnya = DB::table('buku_notaris')
                ->where('id_buku_notaris', $b['id_buku_notaris'])
                ->value('tgl_akta');

            if (empty($tanggalSebelumnya)) {
                return $this->errorResponse(null, 'Data buku notaris tidak ditemukan.', 404);
            }

            $data_buku = [
                'id_akta' => $b['jenis_akta'],
                'judul_pekerjaan' => $b['judul_pekerjaan'],
                'tgl_akta' => $b['tgl_akta'],
                'id_user' => $id_user_input,
                'updated_at' => now(),
            ];

            BukuNotaris::where('id_buku_notaris', $b['id_buku_notaris'])->update($data_buku);
            PenghadapNotaris::where('id_buku_notaris', $b['id_buku_notaris'])->delete();

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_notaris')
                    ->orderBy('id_penghadap_notaris', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_notaris)) {
                    $number = preg_replace('/\D/', '', $daftarpenghadap->id_penghadap_notaris);
                    // $urutan = (int) substr($daftarpenghadap->id_penghadap_notaris, 7) + 1;
                    $urutan = intval($number) + 1;
                } else {
                    $urutan = 1;
                }

                $id_penghadap = 'PHN'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                if (isset($a['id_mewakili'])) {
                    $id_mewakili = $a['id_mewakili'];
                } else {
                    $id_mewakili = null;
                }

                $penghadap1 = [
                    'id_penghadap_notaris' => $id_penghadap,
                    'id_buku_notaris' => $b['id_buku_notaris'],
                    'id_client' => $a['id_client'],
                    'kedudukan' => $a['status_kedudukan'],
                    'id_mewakili' => $id_mewakili,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapNotaris::create($penghadap1);
            }

            return $this->successResponse(null, 'Berhasil Mengupdate Data');
        }
    }

    public function PembuatanNomorNotaris($tgl)
    {
        $tgl = explode('-', $tgl);

        $tanggalterakhir = DB::table('buku_notaris')
            ->select('buku_notaris.tgl_akta', 'buku_notaris.no_akta')
            ->whereYear('tgl_akta', $tgl[0])
            ->whereMonth('tgl_akta', $tgl[1])
            ->orderBy('buku_notaris.id_buku_notaris', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_akta)) {
            return 1;
        } else {
            return $tanggalterakhir->no_akta + 1;
        }
    }

    public function PreviewAktaNotarisMassal(Request $request)
    {
        if (!$this->isSuperAdminUser($request)) {
            return $this->errorResponse(null, 'Akses ditolak. Hanya Super Admin yang dapat membuat akta massal.', 403);
        }

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'tanggal_akta' => ['required', 'date_format:Y-m-d'],
            'judul_pekerjaan' => ['required', 'string', 'max:500'],
            'jumlah' => ['required', 'integer', 'min:1', 'max:1000'],
            'nomor_mulai' => ['nullable', 'integer', 'min:1'],
            'gunakan_nomor_di_judul' => ['nullable', 'boolean'],
        ]);

        $period = (string) $validated['period'];
        $tanggalAkta = (string) $validated['tanggal_akta'];
        if (!$this->dateInPeriod($tanggalAkta, $period)) {
            return $this->errorResponse(null, 'Tanggal akta harus berada di bulan periode yang dipilih.', 422);
        }

        $preview = $this->buildAktaNotarisMassalPreview($validated);

        return $this->successResponse($preview, 'Preview akta massal berhasil dibuat.');
    }

    public function SimpanAktaNotarisMassal(Request $request)
    {
        if (!$this->isSuperAdminUser($request)) {
            return $this->errorResponse(null, 'Akses ditolak. Hanya Super Admin yang dapat membuat akta massal.', 403);
        }

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'tanggal_akta' => ['required', 'date_format:Y-m-d'],
            'judul_pekerjaan' => ['required', 'string', 'max:500'],
            'jumlah' => ['required', 'integer', 'min:1', 'max:1000'],
            'nomor_mulai' => ['nullable', 'integer', 'min:1'],
            'gunakan_nomor_di_judul' => ['nullable', 'boolean'],
        ]);

        $period = (string) $validated['period'];
        $tanggalAkta = (string) $validated['tanggal_akta'];
        if (!$this->dateInPeriod($tanggalAkta, $period)) {
            return $this->errorResponse(null, 'Tanggal akta harus berada di bulan periode yang dipilih.', 422);
        }

        try {
            $created = DB::transaction(function () use ($validated, $request) {
                $preview = $this->buildAktaNotarisMassalPreview($validated, true);
                if (!empty($preview['duplicate_numbers'])) {
                    throw new \RuntimeException('Nomor akta duplikat ditemukan: '.implode(', ', $preview['duplicate_numbers']));
                }

                $lastBuku = DB::table('buku_notaris')
                    ->lockForUpdate()
                    ->orderBy('id_buku_notaris', 'desc')
                    ->first();
                $idSequence = $lastBuku && isset($lastBuku->id_buku_notaris)
                    ? ((int) preg_replace('/\D+/', '', (string) $lastBuku->id_buku_notaris) + 1)
                    : 1;

                $now = now();
                $rows = [];
                foreach ($preview['rows'] as $row) {
                    $rows[] = [
                        'id_buku_notaris' => 'BKN'.str_pad((string) $idSequence, 7, '0', STR_PAD_LEFT),
                        'id_akta' => null,
                        'id_user' => (string) $request->user()->id_user,
                        'status_akta' => 'Lama',
                        'judul_pekerjaan' => (string) $row['judul_pekerjaan'],
                        'no_akta' => (string) $row['no_akta'],
                        'nama_client' => null,
                        'tgl_akta' => (string) $validated['tanggal_akta'],
                        'tgl_signing' => null,
                        'created_by' => (string) $request->user()->id_user,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $idSequence++;
                }

                BukuNotaris::insert($rows);

                return [
                    'period' => $preview['period'],
                    'tanggal_akta' => $preview['tanggal_akta'],
                    'jumlah' => count($rows),
                    'nomor_mulai' => $preview['nomor_mulai'],
                    'nomor_selesai' => $preview['nomor_selesai'],
                ];
            });
        } catch (\RuntimeException $exception) {
            return $this->errorResponse(null, $exception->getMessage(), 422);
        }

        return $this->successResponse($created, 'Akta massal berhasil dibuat.');
    }

    private function isSuperAdminUser(Request $request): bool
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));

        return in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function dateInPeriod(string $date, string $period): bool
    {
        return substr($date, 0, 7) === $period;
    }

    private function buildAktaNotarisMassalPreview(array $payload, bool $lock = false): array
    {
        [$year, $month] = explode('-', (string) $payload['period']);
        $query = DB::table('buku_notaris')
            ->whereYear('tgl_akta', (int) $year)
            ->whereMonth('tgl_akta', (int) $month);

        if ($lock) {
            $query->lockForUpdate();
        }

        $existingNumbers = $query
            ->pluck('no_akta')
            ->map(fn ($number) => (int) $number)
            ->filter(fn ($number) => $number > 0)
            ->values();

        $lastNumber = (int) ($existingNumbers->max() ?? 0);
        $startNumber = isset($payload['nomor_mulai']) && $payload['nomor_mulai']
            ? (int) $payload['nomor_mulai']
            : $lastNumber + 1;
        $count = (int) $payload['jumlah'];
        $endNumber = $startNumber + $count - 1;
        $range = range($startNumber, $endNumber);
        $existingLookup = array_flip($existingNumbers->all());
        $duplicates = array_values(array_filter($range, fn ($number) => isset($existingLookup[$number])));
        $baseTitle = trim((string) $payload['judul_pekerjaan']);
        $appendNumber = (bool) ($payload['gunakan_nomor_di_judul'] ?? false);

        $rows = array_map(function ($number) use ($baseTitle, $appendNumber, $payload) {
            return [
                'no_akta' => $number,
                'tgl_akta' => (string) $payload['tanggal_akta'],
                'judul_pekerjaan' => $appendNumber ? $baseTitle.' No '.$number : $baseTitle,
            ];
        }, $range);

        return [
            'period' => (string) $payload['period'],
            'tanggal_akta' => (string) $payload['tanggal_akta'],
            'judul_pekerjaan' => $baseTitle,
            'jumlah' => $count,
            'nomor_terakhir' => $lastNumber,
            'nomor_mulai' => $startNumber,
            'nomor_selesai' => $endNumber,
            'duplicate_numbers' => $duplicates,
            'can_save' => empty($duplicates),
            'rows' => $rows,
            'preview_rows' => array_slice($rows, 0, 25),
        ];
    }

    public function DeleteNomorNotaris(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $idBukuNotaris = $request->input('id_buku_notaris');

        $buku = BukuNotaris::where('id_buku_notaris', $idBukuNotaris)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data buku akta tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->id_user !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus akta yang Anda buat sendiri.', 403);
            }

            $tanggalAkta = strtotime((string) $buku->tgl_akta);
            if ($tanggalAkta === false) {
                return $this->errorResponse(null, 'Tanggal akta tidak valid.', 422);
            }

            $latestNoAkta = BukuNotaris::whereYear('tgl_akta', date('Y', $tanggalAkta))
                ->whereMonth('tgl_akta', date('m', $tanggalAkta))
                ->max('no_akta');

            if ((int) $buku->no_akta !== (int) $latestNoAkta) {
                return $this->errorResponse(null, 'Hanya nomor akta terakhir yang bisa dihapus.', 403);
            }
        }

        DB::transaction(function () use ($idBukuNotaris) {
            DB::table('tb_dokumen_notaris')
                ->where('id_buku_notaris', $idBukuNotaris)
                ->delete();
            PenghadapNotaris::where('id_buku_notaris', $idBukuNotaris)->delete();
            BukuNotaris::where('id_buku_notaris', $idBukuNotaris)->delete();
        });

        return $this->successResponse(null, 'Berhasil menghapus Akta No '.$buku->no_akta);
    }
    public function DeleteNomorPPAT(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $id = $request->input('id_buku_ppat');

        $buku = BukuPPATS::where('id_buku_ppat', $id)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data buku PPAT tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->id_user !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus data yang Anda buat sendiri.', 403);
            }

            $tanggalAkta = strtotime((string) $buku->tanggal_akta);
            if ($tanggalAkta === false) {
                return $this->errorResponse(null, 'Tanggal akta tidak valid.', 422);
            }

            $latestNoAkta = BukuPPATS::whereYear('tanggal_akta', date('Y', $tanggalAkta))->max('no_akta');
            if ((int) $buku->no_akta !== (int) $latestNoAkta) {
                return $this->errorResponse(null, 'Hanya nomor terakhir yang bisa dihapus.', 403);
            }
        }

        DB::transaction(function () use ($id) {
            tb_dokumen_ppat::where('id_buku_ppat', $id)->delete();
            PenghadapPPATS::where('id_buku_ppat', $id)->delete();
            BukuPPATS::where('id_buku_ppat', $id)->delete();
        });

        return $this->successResponse(null, 'Berhasil menghapus Akta PPAT No '.$buku->no_akta);
    }

    public function DeleteNomorLegalisasi(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $id = $request->input('id_buku_legalisasi');

        $buku = BukuLegalisasi::where('id_buku_legalisasi', $id)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data buku legalisasi tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->id_user !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus data yang Anda buat sendiri.', 403);
            }

            $latestNo = BukuLegalisasi::max('no_legalisasi');
            if ((int) $buku->no_legalisasi !== (int) $latestNo) {
                return $this->errorResponse(null, 'Hanya nomor terakhir yang bisa dihapus.', 403);
            }
        }

        DB::transaction(function () use ($id) {
            DB::table('tb_dokumen_legalisasis')
                ->where('id_buku_legalisasi', $id)
                ->delete();
            PenghadapLegalisasi::where('id_buku_legalisasi', $id)->delete();
            BukuLegalisasi::where('id_buku_legalisasi', $id)->delete();
        });

        return $this->successResponse(null, 'Berhasil menghapus Legalisasi No '.$buku->no_legalisasi);
    }

    public function DeleteNomorWarmerking(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $id = $request->input('id_buku_warmerking');

        $buku = BukuWarmerking::where('id_buku_warmerking', $id)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data buku waarmerking tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->id_user !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus data yang Anda buat sendiri.', 403);
            }

            $latestNo = BukuWarmerking::max('no_warmerking');
            if ((int) $buku->no_warmerking !== (int) $latestNo) {
                return $this->errorResponse(null, 'Hanya nomor terakhir yang bisa dihapus.', 403);
            }
        }

        DB::transaction(function () use ($id) {
            DB::table('tb_dokumen_warmerkings')
                ->where('id_buku_warmerking', $id)
                ->delete();
            PenghadapWarmerking::where('id_buku_warmerking', $id)->delete();
            BukuWarmerking::where('id_buku_warmerking', $id)->delete();
        });

        return $this->successResponse(null, 'Berhasil menghapus Waarmerking No '.$buku->no_warmerking);
    }

    public function DeleteNomorSuratNotaris(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $id = $request->input('id_surat_notaris');

        $buku = BukuSuratNotaris::where('id_surat_notaris', $id)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data surat notaris tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->pengirim !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus data yang Anda buat sendiri.', 403);
            }

            $tanggalSurat = strtotime((string) $buku->created_at);
            if ($tanggalSurat === false) {
                return $this->errorResponse(null, 'Tanggal surat tidak valid.', 422);
            }

            $latestNo = BukuSuratNotaris::whereYear('created_at', date('Y', $tanggalSurat))->max('no_surat');
            if ((int) $buku->no_surat !== (int) $latestNo) {
                return $this->errorResponse(null, 'Hanya nomor terakhir yang bisa dihapus.', 403);
            }
        }

        $path = public_path('suratnotaris/'.$buku->file);
        DB::transaction(function () use ($id) {
            BukuSuratNotaris::where('id_surat_notaris', $id)->delete();
        });
        if ($buku->file && is_file($path)) {
            @unlink($path);
        }

        return $this->successResponse(null, 'Berhasil menghapus Surat Notaris No '.$buku->no_surat);
    }

    public function DeleteNomorSuratPPAT(Request $request)
    {
        $level = strtoupper(trim((string) optional($request->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);
        $id = $request->input('id_surat_ppat');

        $buku = BukuSuratPPAT::where('id_surat_ppat', $id)->first();
        if (!$buku) {
            return $this->errorResponse(null, 'Data surat PPAT tidak ditemukan.', 404);
        }

        if (!$isSuperAdmin) {
            if ((string) $buku->pengirim !== (string) optional($request->user())->id_user) {
                return $this->errorResponse(null, 'Anda hanya bisa menghapus data yang Anda buat sendiri.', 403);
            }

            $tanggalSurat = strtotime((string) $buku->created_at);
            if ($tanggalSurat === false) {
                return $this->errorResponse(null, 'Tanggal surat tidak valid.', 422);
            }

            $latestNo = BukuSuratPPAT::whereYear('created_at', date('Y', $tanggalSurat))->max('no_surat');
            if ((int) $buku->no_surat !== (int) $latestNo) {
                return $this->errorResponse(null, 'Hanya nomor terakhir yang bisa dihapus.', 403);
            }
        }

        $path = public_path('suratppats/'.$buku->file);
        DB::transaction(function () use ($id) {
            BukuSuratPPAT::where('id_surat_ppat', $id)->delete();
        });
        if ($buku->file && is_file($path)) {
            @unlink($path);
        }

        return $this->successResponse(null, 'Berhasil menghapus Surat PPAT No '.$buku->no_surat);
    }



    public function PembuatanNomorSuratNotaris()
    {
        $tanggalterakhir = DB::table('buku_surat_notaris')
            ->select('buku_surat_notaris.created_at', 'buku_surat_notaris.no_surat')
            ->whereYear('created_at', date('Y'))
            ->orderBy('buku_surat_notaris.id_surat_notaris', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_surat)) {
            return 1;
        } else {
            return $tanggalterakhir->no_surat + 1;
        }
    }

    public function PembuatanNomorSuratPPAT()
    {
        $tanggalterakhir = DB::table('buku_surat_ppats')
            ->select('buku_surat_ppats.created_at', 'buku_surat_ppats.no_surat')
            ->whereYear('created_at', date('Y'))
            ->orderBy('buku_surat_ppats.id_surat_ppat', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_surat)) {
            return 1;
        } else {
            return $tanggalterakhir->no_surat + 1;
        }
    }

    public function getBukuNotaris(Request $request)
    {
        $tgl = explode('-', $request->post('date'));
        $query = DB::table('buku_notaris')
            ->whereYear('buku_notaris.tgl_akta', $tgl[0])
            ->whereMonth('buku_notaris.tgl_akta', $tgl[1])
            ->leftJoin('daftar_aktas', 'buku_notaris.id_akta', '=', 'daftar_aktas.id_akta')
            ->join('users', 'buku_notaris.id_user', '=', 'users.id_user')
            ->select('buku_notaris.id_buku_notaris', 'buku_notaris.nama_client', 'buku_notaris.status_akta', 'users.nama_lengkap', 'users.id_user', 'daftar_aktas.nama_akta', 'buku_notaris.judul_pekerjaan', 'buku_notaris.tgl_akta', 'buku_notaris.no_akta')
            ->orderByDesc('buku_notaris.id_buku_notaris')
            ->get();

        $data = [];

        foreach ($query as $r) {
            $daftar = $this->getDataPenghadapNotaris($r->id_buku_notaris);
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_notaris' => $r->id_buku_notaris,
                'nama_akta' => $r->nama_akta,
                'judul_pekerjaan' => $r->judul_pekerjaan,
                'tgl_akta' => $r->tgl_akta,
                'nama_client' => $r->nama_client,
                'no_akta' => $r->no_akta,
                'status_akta' => $r->status_akta,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getBukuPPAT(Request $request)
    {
        $tgl = explode('-', $request->post('date'));

        $query = DB::table('buku_ppats')
            ->leftjoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftjoin('users', 'buku_ppats.id_user', '=', 'users.id_user')
            ->leftjoin('ppat_rekanans', 'buku_ppats.ppat_rekanan_keluar_id', '=', 'ppat_rekanans.id')
            ->whereYear('buku_ppats.tanggal_akta', $tgl[0])
            ->whereMonth('buku_ppats.tanggal_akta', $tgl[1])
            ->select(
                'buku_ppats.id_buku_ppat',
                'buku_ppats.status_akta',
                'users.nama_lengkap',
                'users.id_user',
                'daftar_aktas.nama_akta',
                'buku_ppats.tanggal_akta',
                'daftar_aktas.apht',
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
                'buku_ppats.keterangan',
                'buku_ppats.ppat_rekanan_keluar_id',
                'buku_ppats.rekanan_keluar_catatan',
                'buku_ppats.rekanan_keluar_at',
                'ppat_rekanans.nama_ppat as nama_ppat_rekanan',
            )
            ->orderByDesc('buku_ppats.id_buku_ppat')
            ->get();

        $data = [];

        foreach ($query as $r) {
            $daftar = $this->getDataPenghadapPPAT($r->id_buku_ppat);

            // $barcode  ='Dewantari Handayani,S.H.,M.P.A "%0A"
            // "'.$r->nama_akta.'%0A"
            // "Akta No '.$r->no_akta.'%0A"';

            $q = $r->nama_akta;
            $n = $r->no_akta;
            $barcode = 'Dewantari Handayani, S.H.,MPA '.$q.' Akta No.'.$n.' Tanggal.'.$r->tanggal_akta;

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_ppat' => $r->id_buku_ppat,
                'apht' => $r->apht,
                'nama_akta' => $r->nama_akta,
                'tanggal_akta' => $r->tanggal_akta,
                'no_akta' => $r->no_akta,
                'status_akta' => $r->status_akta,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
                'no_hak_milik' => $r->no_hak_milik,
                'luas_tanah' => number_format(NumericValue::fromMixed($r->luas_tanah)),
                'luas_bangunan' => number_format(NumericValue::fromMixed($r->luas_bangunan)),
                'harga_transaksi' => 'Rp. '.number_format(NumericValue::fromMixed($r->harga_transaksi)),
                'nop' => $r->nop,
                'harga_njop' => 'Rp. '.number_format(NumericValue::fromMixed($r->harga_njop)),
                'tgl_bphtb' => $r->tgl_bphtb,
                'harga_bphtb' => 'Rp. '.number_format(NumericValue::fromMixed($r->harga_bphtb)),
                'tgl_pph' => $r->tgl_pph,
                'harga_pph' => 'Rp. '.number_format(NumericValue::fromMixed($r->harga_pph)),
                'keterangan' => $r->keterangan,
                'rekanan_keluar' => (bool) $r->ppat_rekanan_keluar_id,
                'ppat_rekanan_keluar_id' => $r->ppat_rekanan_keluar_id,
                'nama_ppat_rekanan' => $r->nama_ppat_rekanan,
                'rekanan_keluar_catatan' => $r->rekanan_keluar_catatan,
                'rekanan_keluar_at' => $r->rekanan_keluar_at,
                'barcode' => base64_encode(QrCode::format('svg')->margin(3)->size(250)->generate($barcode)),
            ];
        }

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getBukuLegalisasi(Request $request)
    {
        $tgl = explode('-', $request->post('date'));

        $query = DB::table('buku_legalisasis')
            ->join('users', 'buku_legalisasis.id_user', '=', 'users.id_user')
            ->whereYear('buku_legalisasis.tgl_surat', $tgl[0])
            ->whereMonth('buku_legalisasis.tgl_surat', $tgl[1])
            ->select('buku_legalisasis.id_buku_legalisasi', 'buku_legalisasis.status_legalisasi', 'buku_legalisasis.keterangan_surat', 'buku_legalisasis.no_legalisasi', 'users.nama_lengkap', 'users.id_user', 'buku_legalisasis.tgl_surat', 'buku_legalisasis.judul_surat')
            ->orderByDesc('buku_legalisasis.id_buku_legalisasi')
            ->get();

        $data = [];

        foreach ($query as $r) {
            $daftar = $this->getDataPenghadapLegalisasi($r->id_buku_legalisasi);

            if ($r->keterangan_surat != null) {
                $keterangan = $r->keterangan_surat;
            } else {
                $keterangan = null;
            }

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_legalisasi' => $r->id_buku_legalisasi,
                'judul_surat' => $r->judul_surat,
                'tgl_surat' => $r->tgl_surat,
                'no_legalisasi' => $this->ProsesNomorLegalisasi($r->no_legalisasi, $r->tgl_surat, $keterangan),
                'status_legalisasi' => $r->status_legalisasi,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getBukuWarmerking(Request $request)
    {
        $tgl = explode('-', $request->post('date'));

        $query = DB::table('buku_warmerkings')
            ->join('users', 'buku_warmerkings.id_user', '=', 'users.id_user')
            ->whereYear('buku_warmerkings.tgl_surat', $tgl[0])
            ->whereMonth('buku_warmerkings.tgl_surat', $tgl[1])
            ->select('buku_warmerkings.id_buku_warmerking', 'buku_warmerkings.status_warmerking', 'buku_warmerkings.keterangan_surat', 'buku_warmerkings.no_warmerking', 'users.nama_lengkap', 'users.id_user', 'buku_warmerkings.tgl_didaftarkan', 'buku_warmerkings.judul_surat')
            ->orderByDesc('buku_warmerkings.id_buku_warmerking')
            ->get();

        $data = [];

        foreach ($query as $r) {
            $daftar = $this->getDataPenghadapWarmerking($r->id_buku_warmerking);

            if ($r->keterangan_surat != null) {
                $keterangan = $r->keterangan_surat;
            } else {
                $keterangan = null;
            }

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_warmerking' => $r->id_buku_warmerking,
                'judul_surat' => $r->judul_surat,
                'tgl_didaftarkan' => $r->tgl_didaftarkan,
                'no_warmerking' => $this->ProsesNomorWarmerking($r->no_warmerking, $r->tgl_didaftarkan, $keterangan),
                'status_warmerking' => $r->status_warmerking,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getBukuInvoiceTax()
    {
        $query = DB::table('buku_invoice_taxs')
            ->join('users', 'buku_invoice_taxs.id_user', '=', 'users.id_user')
            ->join('data_clients', 'buku_invoice_taxs.id_client', '=', 'data_clients.id_client')
            ->select('data_clients.nama_client', 'users.nama_lengkap', 'buku_invoice_taxs.tanggal_permintaan', 'buku_invoice_taxs.keterangan_nomor', 'buku_invoice_taxs.no_invoice', 'buku_invoice_taxs.id_buku_invoice_tax')
            ->orderByDesc('buku_invoice_taxs.id_buku_invoice_tax')
            ->get();

        $data = [];

        foreach ($query as $r) {
            // $daftar = $this->getIsiInvoiceTax($r->id_buku_invoice_tax);

            if ($r->keterangan_nomor != null) {
                $keterangan = $r->keterangan_nomor;
            } else {
                $keterangan = null;
            }

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_warmerking' => $r->id_buku_warmerking,
                'judul_surat' => $r->judul_surat,
                'tanggal_permintaan' => $r->tanggal_permintaan,
                'no_akta' => $this->ProsesNomorWarmerking($r->no_akta, $r->tanggal_permintaan, $keterangan),
                'pengambil' => $r->nama_lengkap,
                //      'daftarpenghadap'       => $daftar
            ];
        }

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getRomawi($bln)
    {
        switch ($bln) {
            case 1:
                return 'I';
                break;
            case 2:
                return 'II';
                break;
            case 3:
                return 'III';
                break;
            case 4:
                return 'IV';
                break;
            case 5:
                return 'V';
                break;
            case 6:
                return 'VI';
                break;
            case 7:
                return 'VII';
                break;
            case 8:
                return 'VIII';
                break;
            case 9:
                return 'IX';
                break;
            case 10:
                return 'X';
                break;
            case 11:
                return 'XI';
                break;
            case 12:
                return 'XII';
                break;
        }
    }

    public function ProsesNomorLegalisasi($no_legalisasi, $tgl_legalisasi, $keterangan_surat)
    {
        $dt = explode('-', $tgl_legalisasi);
        $romawibulan = $this->getRomawi($dt[1]);

        if ($keterangan_surat != null) {
            return 'Leg/'.$no_legalisasi.'/'.$keterangan_surat.'/'.$romawibulan.'/'.$dt[0];
        } else {
            return 'Leg/'.$no_legalisasi.'/'.$romawibulan.'/'.$dt[0];
        }
    }

    public function ProsesNomorWarmerking($no_akta, $tanggal_akta, $keterangan_surat)
    {
        $dt = explode('-', $tanggal_akta);
        $romawibulan = $this->getRomawi($dt[1]);

        if ($keterangan_surat != null) {
            return $no_akta.'/Pen/'.$keterangan_surat.'/'.$romawibulan.'/'.$dt[0];
        } else {
            return $no_akta.'/Pen/'.$romawibulan.'/'.$dt[0];
        }
    }

    public function ProsesNomorSuratNotaris($no_surat, $tgl_surat, $keterangan_surat)
    {
        $dt = explode('-', $tgl_surat);
        $romawibulan = $this->getRomawi($dt[1]);

        if ($keterangan_surat != null) {
            return 'Not/'.$no_surat.'/'.$keterangan_surat.'/'.$romawibulan.'/'.$dt[0];
        } else {
            return 'Not/'.$no_surat.'/'.$romawibulan.'/'.$dt[0];
        }
    }

    public function ProsesNomorSuratPPAT($no_surat, $tgl_surat, $keterangan_surat)
    {
        $dt = explode('-', $tgl_surat);
        $romawibulan = $this->getRomawi($dt[1]);

        if ($keterangan_surat != null) {
            return $no_surat.'/PPAT/'.$keterangan_surat.'/'.$romawibulan.'/'.$dt[0];
        } else {
            return $no_surat.'/PPAT/'.$romawibulan.'/'.$dt[0];
        }
    }

    public function getDataPenghadapNotaris($id_buku_notaris)
    {
        $daftarpenghadapnotaris = DB::table('penghadap_notaris')
            ->join('data_clients', 'penghadap_notaris.id_client', '=', 'data_clients.id_client')
            ->leftjoin('data_clients as mewakili', 'penghadap_notaris.id_mewakili', '=', 'mewakili.id_client')
            ->select('data_clients.nama_client', 'penghadap_notaris.id_mewakili', 'mewakili.nama_client as nama_mewakili', 'data_clients.id_client', 'data_clients.no_identitas', 'data_clients.jenis_client', 'penghadap_notaris.kedudukan', 'penghadap_notaris.id_penghadap_notaris')
            ->where('penghadap_notaris.id_buku_notaris', $id_buku_notaris)
            ->get();

        $daftar = [];
        foreach ($daftarpenghadapnotaris as $p) {
            $daftar[] = [
                'id_penghadap_notaris' => $p->id_penghadap_notaris,
                'nama_client' => $p->nama_client,
                'id_client' => $p->id_client,
                'mewakili' => $p->nama_mewakili,
                'id_mewakili' => $p->id_mewakili,
                'no_identitas' => $p->no_identitas,
                'jenis_client' => $p->jenis_client,
                'status_kedudukan' => $p->kedudukan,
            ];
        }

        return $daftar;
    }

    public function EditAktaNotaris(Request $request)
    {
        $buku = DB::table('buku_notaris')
            ->leftJoin('daftar_aktas', 'buku_notaris.id_akta', '=', 'daftar_aktas.id_akta')
            ->orderBy('id_buku_notaris', 'desc')
            ->where('buku_notaris.id_buku_notaris', $request->post('id_buku_notaris'))
            ->first();

        $data = [
            'id_buku_notaris' => $request->post('id_buku_notaris'),
            'jenis_akta' => $buku->id_akta,
            'nama_akta' => $buku->nama_akta,
            'nama_asisten' => $buku->id_user,
            'no_akta' => $buku->no_akta,
            'tgl_akta' => $buku->tgl_akta,
            'nama_client' => $buku->nama_client,
            'judul_pekerjaan' => $buku->judul_pekerjaan,
            'penghadap_notaris' => $this->getDataPenghadapNotaris($request->post('id_buku_notaris')),
        ];

        return $this->successResponse($data, 'Berhasil menampilkan data yang akan diedit');
    }

    public function EditSuratNotaris(Request $request)
    {
        $query = DB::table('buku_surat_notaris')
        ->Leftjoin('users', 'buku_surat_notaris.pengirim', '=', 'users.id_user')
        ->Leftjoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_notaris.id_client')
        ->select('buku_surat_notaris.id_surat_notaris',
            'data_clients.nama_client',
            'data_clients.id_client',
            'buku_surat_notaris.no_surat',
            'buku_surat_notaris.keterangan',
            'buku_surat_notaris.created_at',
            'users.id_user',
            'users.nama_lengkap')
        ->where('buku_surat_notaris.id_surat_notaris', $request->post('id_surat_notaris'))
        ->orderByDesc('buku_surat_notaris.id_surat_notaris')
        ->first();

        $response = [
            'status' => true,
            'message' => 'Berhasil menampilkan',
            'data' => $query,
        ];

        return response($response, 200);
    }

    public function EditSuratPPAT(Request $request)
    {
        $query = DB::table('buku_surat_ppats')
        ->Leftjoin('users', 'buku_surat_ppats.pengirim', '=', 'users.id_user')
        ->Leftjoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_ppats.id_client')
        ->select('buku_surat_ppats.id_surat_ppat',
            'data_clients.nama_client',
            'data_clients.id_client',
            'buku_surat_ppats.no_surat',
            'buku_surat_ppats.keterangan',
            'buku_surat_ppats.created_at',
            'users.id_user',
            'users.nama_lengkap')
        ->where('buku_surat_ppats.id_surat_ppat', $request->post('id_surat_ppat'))
        ->orderByDesc('buku_surat_ppats.id_surat_ppat')
        ->first();

        $response = [
            'status' => true,
            'message' => 'Berhasil menampilkan',
            'data' => $query,
        ];

        return response($response, 200);
    }

    public function EditAktaPPAT(Request $request)
    {
        $buku = DB::table('buku_ppats')
            ->join('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
            ->orderBy('id_buku_ppat', 'desc')
            ->where('buku_ppats.id_buku_ppat', $request->post('id_buku_ppat'))
            ->first();

        $data = [
            'apht'         =>$buku->apht,
            'id_buku_ppat' => $request->post('id_buku_ppat'),
            'no_akta' => $buku->no_akta,
            'tanggal_akta' => $buku->tanggal_akta,
            'id_akta' => $buku->id_akta,
            'jenis_akta' => $buku->id_akta,
            'nama_akta' => $buku->nama_akta,
            'status_akta' => $buku->status_akta,
            'no_hak_milik' => $buku->no_hak_milik,
            'luas_tanah' => $buku->luas_tanah,
            'luas_bangunan' => $buku->luas_bangunan,
            'harga_transaksi' => $buku->harga_transaksi,
            'nop' => $buku->nop,
            'harga_njop' => $buku->harga_njop,
            'tgl_bphtb' => $buku->tgl_bphtb,
            'harga_bphtb' => $buku->harga_bphtb,
            'tgl_pph' => $buku->tgl_pph,
            'harga_pph' => $buku->harga_pph,
            'keterangan' => $buku->keterangan,
            'penghadap' => $this->getDataPenghadapPPAT($request->post('id_buku_ppat')),
        ];

        return $this->successResponse($data, 'Berhasil menampilkan data yang akan diedit');
    }

    public function EditLegalisasi(Request $request)
    {
        $buku = DB::table('buku_legalisasis')
            ->orderBy('id_buku_legalisasi', 'desc')
            ->where('buku_legalisasis.id_buku_legalisasi', $request->post('id_buku_legalisasi'))
            ->first();

        $data = [
            'id_buku_legalisasi' => $request->post('id_buku_legalisasi'),
            'judul_surat' => $buku->judul_surat,
            'nama_client' => $buku->nama_client,
            'keterangan_surat' => $buku->keterangan_surat,
            'penghadap_legalisasi' => $this->getDataPenghadapLegalisasi($request->post('id_buku_legalisasi')),
        ];

        return $this->successResponse($data, 'Berhasil menampilkan data yang akan diedit');
    }

    public function EditWarmerking(Request $request)
    {
        $buku = DB::table('buku_warmerkings')
            ->orderBy('id_buku_warmerking', 'desc')
            ->where('buku_warmerkings.id_buku_warmerking', $request->post('id_buku_warmerking'))
            ->first();

        $data = [
            'id_buku_warmerking' => $request->post('id_buku_warmerking'),
            'judul_surat' => $buku->judul_surat,
            'nama_client' => $buku->nama_client,
            'keterangan_surat' => $buku->keterangan_surat,
            'penghadap_warmerking' => $this->getDataPenghadapWarmerking($request->post('id_buku_warmerking')),
        ];

        return $this->successResponse($data, 'Berhasil menampilkan data yang akan diedit');
    }

    public function SimpanNomorPPAT(Request $request)
    {
        if (!$request->post('id_buku_ppat')) {
            if ($request->post('apht') == 'TRUE') {
                $request->validate([
                    'jenis_akta' => ['required', 'exists:daftar_aktas,id_akta'],
                    'sudah_tanda_tangan' => ['accepted'],
                    'no_hak_milik' => 'required',
                    'luas_tanah' => 'required|numeric',
                    'luas_bangunan' => 'required|numeric',
                    'harga_transaksi' => 'required|numeric',
                ], [
                    'sudah_tanda_tangan.accepted' => 'Dokumen harus ditandatangani terlebih dahulu sebelum mengambil nomor.',
                ]);
            } else {
                $request->validate([
                    'jenis_akta' => ['required', 'exists:daftar_aktas,id_akta'],
                    'sudah_tanda_tangan' => ['accepted'],
                    'no_hak_milik' => 'required',
                    'luas_tanah' => 'required|numeric',
                    'luas_bangunan' => 'required|numeric',
                    'harga_transaksi' => 'required|numeric',
                    'nop' => 'required',
                    'harga_njop' => 'required|numeric',
                    'tgl_bphtb' => 'required',
                    'harga_bphtb' => 'required|numeric',
                    'tgl_pph' => 'required',
                    'harga_pph' => 'required|numeric',
                ], [
                    'sudah_tanda_tangan.accepted' => 'Dokumen harus ditandatangani terlebih dahulu sebelum mengambil nomor.',
                ]);
            }

            $buku = DB::table('buku_ppats')
                ->orderBy('id_buku_ppat', 'desc')
                ->limit(1)
                ->first();

            if (isset($buku->id_buku_ppat)) {
                $urutan = (int) substr($buku->id_buku_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_buku_ppat = 'BKP'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $data_buku = [
                'id_buku_ppat' => $id_buku_ppat,
                'id_akta' => $request->post('jenis_akta'),
                'id_user' => auth()->user()->id_user,
                'tanggal_akta' => date('Y-m-d'),
                'no_akta' => $this->PembuatanNomorPPAT(),
                'status_akta' => 'Proses',
                'no_hak_milik' => $request->post('no_hak_milik'),
                'luas_tanah' => $request->post('luas_tanah'),
                'luas_bangunan' => $request->post('luas_bangunan'),
                'harga_transaksi' => $request->post('harga_transaksi'),
                'nop' => $request->post('nop'),
                'harga_njop' => $request->post('harga_njop'),
                'tgl_bphtb' => $request->post('tgl_bphtb'),
                'harga_bphtb' => $request->post('harga_bphtb'),
                'tgl_pph' => $request->post('tgl_pph'),
                'harga_pph' => $request->post('harga_pph'),
                'keterangan' => $request->post('keterangan'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuPPATS::create($data_buku);

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_ppats')
                    ->orderBy('id_penghadap_ppat', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_ppat)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_ppat, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_penghadap = 'PHP'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $penghadap = [
                    'id_penghadap_ppat' => $id_penghadap,
                    'id_buku_ppat' => $id_buku_ppat,
                    'id_client' => $a['id_client'],
                    'status_kedudukan' => $a['status_kedudukan'],
                    'id_mewakili' => $a['id_mewakili'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapPPATS::create($penghadap);
            }

            $namaAkta = (string) (DB::table('daftar_aktas')
                ->where('id_akta', $request->post('jenis_akta'))
                ->value('nama_akta') ?? '');

            $this->notifyNumberTaken(
                'Buku PPAT',
                (string) $data_buku['no_akta'],
                (string) $id_buku_ppat,
                (string) $data_buku['tanggal_akta'],
                $namaAkta,
                (string) $data_buku['id_user'],
                (array) $request->input('penghadap', [])
            );

            return $this->successResponse(null, 'Berhasil membuat nomor PPAT');
        } else {
            // METODE UPDATE

            $data_buku = [
                'id_buku_ppat' => $request->post('id_buku_ppat'),
                'id_akta' => $request->post('id_akta'),
                'id_user' => auth()->user()->id_user,
                'status_akta' => 'Proses',
                'no_hak_milik' => $request->post('no_hak_milik'),
                'luas_tanah' => $request->post('luas_tanah'),
                'luas_bangunan' => $request->post('luas_bangunan'),
                'harga_transaksi' => $request->post('harga_transaksi'),
                'nop' => $request->post('nop'),
                'harga_njop' => $request->post('harga_njop'),
                'tgl_bphtb' => $request->post('tgl_bphtb'),
                'harga_bphtb' => $request->post('harga_bphtb'),
                'tgl_pph' => $request->post('tgl_pph'),
                'harga_pph' => $request->post('harga_pph'),
                'keterangan' => $request->post('keterangan'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuPPATS::where('id_buku_ppat', $request->post('id_buku_ppat'))->update($data_buku);

            PenghadapPPATS::where('id_buku_ppat', $request->post('id_buku_ppat'))->delete();

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_ppats')
                    ->orderBy('id_penghadap_ppat', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_ppat)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_ppat, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_penghadap = 'PHP'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $penghadap = [
                    'id_penghadap_ppat' => $id_penghadap,
                    'id_buku_ppat' => $request->post('id_buku_ppat'),
                    'id_client' => $a['id_client'],
                    'status_kedudukan' => $a['status_kedudukan'],
                    'id_mewakili' => $a['id_mewakili'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapPPATS::create($penghadap);
            }

            return $this->successResponse(null, 'Berhasil Mengupdate Data');
        }
        /* $penghadap = $request->input('penghadap');
       for($i=0; $i<count($penghadap); $i++){

            $daftarpenghadap = DB::table('penghadap_ppats')
                ->orderBy('id_penghadap_ppat', 'desc')
                ->limit(1)
                ->first();

            if (isset($daftarpenghadap->id_penghadap_ppat)) {
                $urutan = (int) substr($daftarpenghadap->id_penghadap_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }
            $id_penghadap    =  "PHP" . str_pad($urutan, 7, "0", STR_PAD_LEFT);

            if(isset($penghadap[$i]['id_mewakili'])){
                $id_mewakili = $penghadap[$i]['id_mewakili'];
            }else{
                $id_mewakili = NULL;
            }

            echo print_r($id_mewakili);

             $peng = array(
              //  'id_penghadap_ppat'     =>$id_penghadap,
                'id_buku_ppat'          =>$id_buku_ppat,
                'id_client'             =>$penghadap[$i]['id_client'],
                'kedudukan'             =>$penghadap[$i]['status_kedudukan'],
               // 'id_mewakili'           =>$id_mewakili,
                'created_at'            =>now(),
                'updated_at'            =>now()
            );

            echo print_r($peng);
       } */

        //   echo print_r($id_penghadap);

        /*  $daftarpenghadap = DB::table('penghadap_ppats')
                ->orderBy('id_penghadap_ppat', 'desc')
                ->limit(1)
                ->first();

            if (isset($daftarpenghadap->id_penghadap_ppat)) {
                $urutan = (int) substr($daftarpenghadap->id_penghadap_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }
            $id_penghadap    =  "PHP" . str_pad($urutan, 7, "0", STR_PAD_LEFT);*/

        //  PenghadapPPATS::create($penghadap); */

        //  return $this->successResponse(null, "Berhasil membuat nomor akta");

        /* }else{

        $data_buku = array(
            'id_akta'             =>$b['jenis_akta'],
            'id_user'             =>auth()->user()->id_user,
            'created_at'          =>now(),
            'updated_at'          =>now()
          );

         BukuPPATS::where('id_buku_ppat',$b['id_buku_ppat'])->update($data_buku);
         PenghadapPPATS::where('id_buku_ppat',$b['id_buku_ppat'])->delete();

         foreach ($request->input('penghadap') as $a) {
            $daftarpenghadap = DB::table('penghadap_ppats')
                ->orderBy('id_penghadap_ppat', 'desc')
                ->limit(1)
                ->first();

            if (isset($daftarpenghadap->id_penghadap_ppat)) {
                $urutan = (int) substr($daftarpenghadap->id_penghadap_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }
            $id_penghadap    =  "PHP" . str_pad($urutan, 7, "0", STR_PAD_LEFT);

            $penghadap = array(
                'id_penghadap_ppat'     => $id_penghadap,
                'id_buku_ppat'         => $b['id_buku_ppat'],
                'id_client'             => $a['id_client'],
                'kedudukan'             => $a['status_kedudukan'],
                'created_at'            =>now(),
                'updated_at'            =>now()
            );

         PenghadapPPATS::create($penghadap);
        } */

        //  return $this->successResponse(null, "Berhasil Mengupdate Data");
    }

    public function PembuatanNomorPPAT()
    {
        $tanggalterakhir = DB::table('buku_ppats')
            ->select('buku_ppats.tanggal_akta', 'buku_ppats.no_akta')
            ->whereYear('tanggal_akta', date('Y'))
            ->orderBy('buku_ppats.id_buku_ppat', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_akta)) {
            return 1;
        } else {
            return $tanggalterakhir->no_akta + 1;
        }
    }

    public function getDataPenghadapPPAT($id_buku_ppat)
    {
        $daftarpenghadapppat = DB::table('penghadap_ppats')
            ->join('data_clients', 'penghadap_ppats.id_client', '=', 'data_clients.id_client')
            ->leftjoin('data_clients as mewakili', 'penghadap_ppats.id_mewakili', '=', 'mewakili.id_client')
            ->select('penghadap_ppats.id_mewakili', 'mewakili.nama_client as nama_mewakili', 'data_clients.nama_client', 'data_clients.id_client', 'data_clients.no_identitas', 'data_clients.jenis_client', 'penghadap_ppats.status_kedudukan', 'penghadap_ppats.id_penghadap_ppat')
            ->where('penghadap_ppats.id_buku_ppat', $id_buku_ppat)
            ->get();

        $daftar = [];
        foreach ($daftarpenghadapppat as $p) {
            $daftar[] = [
                'id_penghadap_ppat' => $p->id_penghadap_ppat,
                'nama_client' => $p->nama_client,
                'id_client' => $p->id_client,
                'mewakili' => $p->nama_mewakili,
                'id_mewakili' => $p->id_mewakili,
                'no_identitas' => $p->no_identitas,
                'jenis_client' => $p->jenis_client,
                'status_kedudukan' => $p->status_kedudukan,
            ];
        }

        return $daftar;
    }

    public function SimpanNomorLegalisasi(Request $request)
    {
        $b = $request->input('data_buku');
        $request->validate([
            'data_buku.judul_surat' => ['required'],
        ]);

        if (!array_key_exists('id_buku_legalisasi', $b)) {
            $buku = DB::table('buku_legalisasis')
                ->orderBy('id_buku_legalisasi', 'desc')
                ->limit(1)
                ->first();

            if (isset($buku->id_buku_legalisasi)) {
                $urutan = (int) substr($buku->id_buku_legalisasi, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_buku_legalisasi = 'BKL'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            if (array_key_exists('keterangan_surat', $b)) {
                $keterangan = $b['keterangan_surat'];
            } else {
                $keterangan = null;
            }
            $data_buku = [
                'id_buku_legalisasi' => $id_buku_legalisasi,
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $keterangan,
                'id_user' => auth()->user()->id_user,
                'status_legalisasi' => 'Proses',
                'tgl_surat' => date('Y-m-d'),
                'no_legalisasi' => $this->PembuatanNomorLegalisasi(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuLegalisasi::create($data_buku);

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_legalisasis')
                    ->orderBy('id_penghadap_legalisasi', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_legalisasi)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_legalisasi, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHL'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                if (isset($a['id_mewakili'])) {
                    $id_mewakili = $a['id_mewakili'];
                } else {
                    $id_mewakili = null;
                }

                $penghadap = [
                    'id_penghadap_legalisasi' => $id_penghadap,
                    'id_buku_legalisasi' => $id_buku_legalisasi,
                    'id_client' => $a['id_client'],
                    'id_mewakili' => $id_mewakili,
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                PenghadapLegalisasi::create($penghadap);
            }

            $this->notifyNumberTaken(
                'Buku Legalisasi',
                (string) $data_buku['no_legalisasi'],
                (string) $id_buku_legalisasi,
                (string) $data_buku['tgl_surat'],
                (string) $data_buku['judul_surat'],
                (string) $data_buku['id_user'],
                (array) $request->input('penghadap', [])
            );

            return $this->successResponse(null, 'Berhasil membuat nomor akta');
        } else {
            $data_buku = [
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $b['keterangan_surat'],
                'id_user' => auth()->user()->id_user,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuLegalisasi::where('id_buku_legalisasi', $b['id_buku_legalisasi'])->update($data_buku);
            PenghadapLegalisasi::where('id_buku_legalisasi', $b['id_buku_legalisasi'])->delete();

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_legalisasis')
                    ->orderBy('id_penghadap_legalisasi', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_legalisasi)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_legalisasi, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHL'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                if (isset($a['id_mewakili'])) {
                    $id_mewakili = $a['id_mewakili'];
                } else {
                    $id_mewakili = null;
                }

                $penghadap = [
                    'id_penghadap_legalisasi' => $id_penghadap,
                    'id_buku_legalisasi' => $b['id_buku_legalisasi'],
                    'id_client' => $a['id_client'],
                    'id_mewakili' => $id_mewakili,
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapLegalisasi::create($penghadap);
            }

            return $this->successResponse(null, 'Berhasil Mengupdate Data');
        }
    }

    public function PembuatanNomorLegalisasi()
    {
        $tanggalterakhir = DB::table('buku_legalisasis')
            ->select('buku_legalisasis.tgl_surat', 'buku_legalisasis.no_legalisasi')
            ->orderBy('buku_legalisasis.id_buku_legalisasi', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_legalisasi)) {
            return 1;
        } else {
            return $tanggalterakhir->no_legalisasi + 1;
        }
    }

    public function getDataPenghadapLegalisasi($id_buku_legalisasi)
    {
        $daftarpenghadaplegaliasisi = DB::table('penghadap_legalisasis')
            ->join('data_clients', 'penghadap_legalisasis.id_client', '=', 'data_clients.id_client')
            ->leftjoin('data_clients as mewakili', 'penghadap_legalisasis.id_mewakili', '=', 'mewakili.id_client')
            ->select('data_clients.nama_client', 'data_clients.id_client', 'data_clients.no_identitas', 'data_clients.jenis_client', 'penghadap_legalisasis.kedudukan', 'penghadap_legalisasis.id_penghadap_legalisasi', 'mewakili.nama_client as nama_mewakili', 'mewakili.id_client as id_mewakili')
            ->where('penghadap_legalisasis.id_buku_legalisasi', $id_buku_legalisasi)
            ->get();

        $daftar = [];
        foreach ($daftarpenghadaplegaliasisi as $p) {
            $daftar[] = [
                'id_penghadap_legalisasi' => $p->id_penghadap_legalisasi,
                'nama_client' => $p->nama_client,
                'id_client' => $p->id_client,
                'mewakili' => $p->nama_mewakili,
                'id_mewakili' => $p->id_mewakili,
                'no_identitas' => $p->no_identitas,
                'jenis_client' => $p->jenis_client,
                'status_kedudukan' => $p->kedudukan,
            ];
        }

        return $daftar;
    }

    public function SimpanNomorWarmerking(Request $request)
    {
        $b = $request->input('data_buku');
        $request->validate([
            'data_buku.judul_surat' => ['required'],
        ]);

        if (!array_key_exists('id_buku_warmerking', $b)) {
            $buku = DB::table('buku_warmerkings')
                ->orderBy('id_buku_warmerking', 'desc')
                ->limit(1)
                ->first();

            if (isset($buku->id_buku_warmerking)) {
                $urutan = (int) substr($buku->id_buku_warmerking, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_buku_warmerking = 'BKW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            if (array_key_exists('keterangan_surat', $b)) {
                $keterangan = $b['keterangan_surat'];
            } else {
                $keterangan = null;
            }

            $data_buku = [
                'id_buku_warmerking' => $id_buku_warmerking,
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $keterangan,
                'id_user' => auth()->user()->id_user,
                'status_warmerking' => 'Proses',
                'tgl_didaftarkan' => date('Y-m-d'),
                'tgl_surat' => date('Y-m-d'),
                'no_warmerking' => $this->PembuatanNomorWarmeking(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuWarmerking::create($data_buku);

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_warmerkings')
                    ->orderBy('id_penghadap_warmerking', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_warmerking)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_warmerking, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                if (isset($a['id_mewakili'])) {
                    $id_mewakili = $a['id_mewakili'];
                } else {
                    $id_mewakili = null;
                }

                $penghadap = [
                    'id_penghadap_warmerking' => $id_penghadap,
                    'id_buku_warmerking' => $id_buku_warmerking,
                    'id_client' => $a['id_client'],
                    'id_mewakili' => $id_mewakili,
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                PenghadapWarmerking::create($penghadap);
            }

            $this->notifyNumberTaken(
                'Buku Waarmerking',
                (string) $data_buku['no_warmerking'],
                (string) $id_buku_warmerking,
                (string) $data_buku['tgl_didaftarkan'],
                (string) $data_buku['judul_surat'],
                (string) $data_buku['id_user'],
                (array) $request->input('penghadap', [])
            );

            return $this->successResponse(null, 'Berhasil membuat nomor akta');
        } else {
            $data_buku = [
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $b['keterangan_surat'],
                'id_user' => auth()->user()->id_user,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuWarmerking::where('id_buku_warmerking', $b['id_buku_warmerking'])->update($data_buku);
            PenghadapWarmerking::where('id_buku_warmerking', $b['id_buku_warmerking'])->delete();

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_warmerkings')
                    ->orderBy('id_penghadap_warmerking', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_warmerking)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_warmerking, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);
                if (isset($a['id_mewakili'])) {
                    $id_mewakili = $a['id_mewakili'];
                } else {
                    $id_mewakili = null;
                }

                $penghadap = [
                    'id_penghadap_warmerking' => $id_penghadap,
                    'id_buku_warmerking' => $b['id_buku_warmerking'],
                    'id_client' => $a['id_client'],
                    'id_mewakili' => $id_mewakili,
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapWarmerking::create($penghadap);
            }

            return $this->successResponse(null, 'Berhasil Mengupdate Data');
        }
    }

    public function PembuatanNomorWarmeking()
    {
        $tanggalterakhir = DB::table('buku_warmerkings')
            ->select('buku_warmerkings.tgl_didaftarkan', 'buku_warmerkings.no_warmerking')
            ->orderBy('buku_warmerkings.id_buku_warmerking', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_warmerking)) {
            return 1;
        } else {
            return $tanggalterakhir->no_warmerking + 1;
        }
    }

    public function getDataPenghadapWarmerking($id_buku_warmerking)
    {
        $daftarpenghadapwarmerking = DB::table('penghadap_warmerkings')
            ->join('data_clients', 'penghadap_warmerkings.id_client', '=', 'data_clients.id_client')
            ->leftjoin('data_clients as mewakili', 'penghadap_warmerkings.id_mewakili', '=', 'mewakili.id_client')
            ->select('data_clients.nama_client', 'penghadap_warmerkings.id_mewakili', 'mewakili.nama_client as nama_mewakili', 'data_clients.id_client', 'data_clients.no_identitas', 'data_clients.jenis_client', 'penghadap_warmerkings.kedudukan', 'penghadap_warmerkings.id_penghadap_warmerking')
            ->where('penghadap_warmerkings.id_buku_warmerking', $id_buku_warmerking)
            ->get();

        $daftar = [];
        foreach ($daftarpenghadapwarmerking as $p) {
            $daftar[] = [
                'id_penghadap_warmerking' => $p->id_penghadap_warmerking,
                'nama_client' => $p->nama_client,
                'id_client' => $p->id_client,
                'mewakili' => $p->nama_mewakili,
                'id_mewakili' => $p->id_mewakili,
                'no_identitas' => $p->no_identitas,
                'jenis_client' => $p->jenis_client,
                'status_kedudukan' => $p->kedudukan,
            ];
        }

        return $daftar;
    }

    public function SimpanNomorInvoiceTax(Request $request)
    {
        $b = $request->input('data_buku');
        $request->validate([
            'data_buku.judul_surat' => ['required'],
            'penghadap' => 'required',
            'penghadap.*.status_kedudukan' => 'required',
            'penghadap.*.id_client' => 'required',
        ]);

        if (!array_key_exists('id_buku_warmerking', $b)) {
            $buku = DB::table('buku_warmerkings')
                ->orderBy('id_buku_warmerking', 'desc')
                ->limit(1)
                ->first();

            if (isset($buku->id_buku_warmerking)) {
                $urutan = (int) substr($buku->id_buku_warmerking, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_buku_warmerking = 'BKW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            if (array_key_exists('keterangan_surat', $b)) {
                $keterangan = $b['keterangan_surat'];
            } else {
                $keterangan = null;
            }

            $data_buku = [
                'id_buku_warmerking' => $id_buku_warmerking,
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $keterangan,
                'id_user' => auth()->user()->id_user,
                'status_akta' => 'Proses',
                'tanggal_permintaan' => date('Y-m-d'),
                'no_akta' => $this->PembuatanNomorWarmeking(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuWarmerking::create($data_buku);

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_warmerkings')
                    ->orderBy('id_penghadap_warmerking', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_warmerking)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_warmerking, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $penghadap = [
                    'id_penghadap_warmerking' => $id_penghadap,
                    'id_buku_warmerking' => $id_buku_warmerking,
                    'id_client' => $a['id_client'],
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                PenghadapWarmerking::create($penghadap);
            }

            $this->notifyNumberTaken(
                'Buku Invoice Tax',
                (string) $data_buku['no_akta'],
                (string) $id_buku_warmerking,
                (string) $data_buku['tanggal_permintaan'],
                (string) $data_buku['judul_surat'],
                (string) $data_buku['id_user'],
                (array) $request->input('penghadap', [])
            );

            return $this->successResponse(null, 'Berhasil membuat nomor akta');
        } else {
            $data_buku = [
                'judul_surat' => $b['judul_surat'],
                'keterangan_surat' => $b['keterangan_surat'],
                'id_user' => auth()->user()->id_user,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            BukuWarmerking::where('id_buku_warmerking', $b['id_buku_warmerking'])->update($data_buku);
            PenghadapWarmerking::where('id_buku_warmerking', $b['id_buku_warmerking'])->delete();

            foreach ($request->input('penghadap') as $a) {
                $daftarpenghadap = DB::table('penghadap_warmerkings')
                    ->orderBy('id_penghadap_warmerking', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($daftarpenghadap->id_penghadap_warmerking)) {
                    $urutan = (int) substr($daftarpenghadap->id_penghadap_warmerking, 6) + 1;
                } else {
                    $urutan = 1;
                }
                $id_penghadap = 'PHW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $penghadap = [
                    'id_penghadap_warmerking' => $id_penghadap,
                    'id_buku_warmerking' => $b['id_buku_warmerking'],
                    'id_client' => $a['id_client'],
                    'kedudukan' => $a['status_kedudukan'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                PenghadapWarmerking::create($penghadap);
            }

            return $this->successResponse(null, 'Berhasil Mengupdate Data');
        }
    }

    public function PembuatanNomorInvoiceTax()
    {
        $tanggalterakhir = DB::table('buku_warmerkings')
            ->select('buku_warmerkings.tanggal_permintaan', 'buku_warmerkings.no_akta')
            ->whereYear('tanggal_permintaan', date('Y'))
            ->whereMonth('tanggal_permintaan', date('m'))
            ->orderBy('buku_warmerkings.id_buku_warmerking', 'DESC')
            ->limit(1)
            ->first();

        if (empty($tanggalterakhir->no_akta)) {
            return 1;
        } else {
            return $tanggalterakhir->no_akta + 1;
        }
    }

    public function getIsiInvoiceTax($id_buku_warmerking)
    {
        $daftarpenghadapwarmerking = DB::table('penghadap_warmerkings')
            ->join('data_clients', 'penghadap_warmerkings.id_client', '=', 'data_clients.id_client')
            ->select('data_clients.nama_client', 'data_clients.id_client', 'data_clients.no_identitas', 'data_clients.jenis_client', 'penghadap_warmerkings.kedudukan', 'penghadap_warmerkings.id_penghadap_warmerking')
            ->where('penghadap_warmerkings.id_buku_warmerking', $id_buku_warmerking)
            ->get();

        $daftar = [];
        foreach ($daftarpenghadapwarmerking as $p) {
            $daftar[] = [
                'id_penghadap_warmerking' => $p->id_penghadap_warmerking,
                'nama_client' => $p->nama_client,
                'id_client' => $p->id_client,
                'no_identitas' => $p->no_identitas,
                'jenis_client' => $p->jenis_client,
                'status_kedudukan' => $p->kedudukan,
            ];
        }

        return $daftar;
    }

    public function UploadExcelNotaris(Request $request)
    {
        $rows = Excel::toArray(new DashboardController(), $request->file('files'));
        $data = [];
        for ($i = 1; $i <= count($rows[0]); ++$i) {
            if (isset($rows[0][$i])) {
                $data = [
                    'id_sementara' => $rows[0][$i][0],
                    'no_akta' => $rows[0][$i][1],
                    'tgl_akta' => $rows[0][$i][2],
                    'judul_pekerjaan' => $rows[0][$i][3],
                    'nama_client' => $rows[0][$i][4],
                    'penghadap' => null,
                    'jenis_pekerjaan' => null,
                    'id_user' => null,
                    'nama_asisten' => null,
                ];

                $buku = DB::table('buku_notaris')
                    ->orderBy('id_buku_notaris', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($buku->id_buku_notaris)) {
                    $urutan = (int) substr($buku->id_buku_notaris, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_buku_notaris = 'BKN'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $data_buku = [
                    'id_buku_notaris' => $id_buku_notaris,
                    'id_akta' => null,
                    'judul_pekerjaan' => $rows[0][$i][3],
                    'id_user' => auth()->user()->id_user,
                    'nama_client' => $rows[0][$i][4],
                    'tgl_akta' => date('Y-m-d', strtotime($rows[0][$i][2])),
                    'no_akta' => $rows[0][$i][1],
                    'status_akta' => 'Lama',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                BukuNotaris::create($data_buku);
            }
        }

        return $this->successResponse($data, 'Berhasil Mengambil data excel');
    }

    public function UploadExcelWarmerking(Request $request)
    {
        $rows = Excel::toArray(new DashboardController(), $request->file('files'));
        $data = [];
        for ($i = 1; $i <= count($rows[0]); ++$i) {
            if (isset($rows[0][$i])) {
                $buku = DB::table('buku_warmerkings')
                    ->orderBy('id_buku_warmerking', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($buku->id_buku_warmerking)) {
                    $urutan = (int) substr($buku->id_buku_warmerking, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_buku_warmerking = 'BKW'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $tgl_surat = strtotime($rows[0][$i][1]);
                $tgl_didaftarkan = strtotime($rows[0][$i][2]);

                $data_buku = [
                    'id_buku_warmerking' => $id_buku_warmerking,
                    'keterangan_surat' => null,
                    'id_user' => auth()->user()->id_user,
                    'no_warmerking' => $rows[0][$i][0],
                    'tgl_surat' => date('Y-m-d', $tgl_surat),
                    'tgl_didaftarkan' => date('Y-m-d', $tgl_didaftarkan),
                    'judul_surat' => $rows[0][$i][3],
                    'nama_client' => $rows[0][$i][4],
                    'status_warmerking' => 'Selesai',
                ];

                BukuWarmerking::create($data_buku);
            }
        }
        $response = [
            'status' => true,
            'message' => 'Upload Pekerjaan Warmerking Berhasil',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadExcelLegalisasi(Request $request)
    {
        $rows = Excel::toArray(new DashboardController(), $request->file('files'));

        $data = [];
        for ($i = 1; $i <= count($rows[0]); ++$i) {
            if (isset($rows[0][$i])) {
                $buku = DB::table('buku_legalisasis')
                    ->orderBy('id_buku_legalisasi', 'desc')
                    ->limit(1)
                    ->first();

                if (isset($buku->id_buku_legalisasi)) {
                    $urutan = (int) substr($buku->id_buku_legalisasi, 6) + 1;
                } else {
                    $urutan = 1;
                }

                $id_buku_legalisasi = 'BKL'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

                $tgl_surat = strtotime($rows[0][$i][1]);

                $data_buku = [
                    'id_buku_legalisasi' => $id_buku_legalisasi,
                    'keterangan_surat' => null,
                    'id_user' => auth()->user()->id_user,
                    'no_legalisasi' => $rows[0][$i][0],
                    'tgl_surat' => date('Y-m-d', $tgl_surat),
                    'judul_surat' => $rows[0][$i][2],
                    'nama_client' => $rows[0][$i][3],
                    'status_warmerking' => 'Selesai',
                ];

                BukuLegalisasi::create($data_buku);
            }
        }

        return $this->successResponse($data, 'Berhasil Mengambil data excel');
    }

    public function UploadExcelPPAT(Request $request)
    {
        $this->validate($request, [
            'files' => 'required|mimes:csv,xls,xlsx',
        ]);

        // $rows = Excel::toArray(new PembuatanNomor, $request->file('files'));
        $array = (new ImportPPAT())->toArray($request->file('files'));

        echo print_r($array);

        $buku = DB::table('buku_ppats')
            ->orderBy('id_buku_ppat', 'desc')
            ->limit(1)
            ->first();

        if (isset($buku->id_buku_ppat)) {
            $urutan = (int) substr($buku->id_buku_ppat, 6) + 1;
        } else {
            $urutan = 1;
        }

        $id_buku_ppat = 'BKP'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

        /*      for($i=1; $i<=count($rows[0]); $i++){
        if(isset($rows[0][$i])){



         /*   $hasil = array(
            'id_buku_ppat'     =>$id_buku_ppat,
            'id_akta'          =>'J_0001',
            'id_user'          =>auth()->user()->id_user,
            'no_akta'          =>preg_replace('/\s+/','',$rows[0][$i][1]),
            'tanggal_akta'     =>$rows[0][$i][2],
            'bentuk_hukum'     =>$rows[0][$i][3],
            'pihak_mengalihkan'=>$rows[0][$i][4],
            'pihak_menerima'   =>$rows[0][$i][5],
            'no_hak_milik'     =>$rows[0][$i][6],
            'tanah_bangunan'   =>$rows[0][$i][7],
            'luas_tanah'       =>$rows[0][$i][8],
            'bangunan'         =>$rows[0][$i][9],
            'harga_transaksi'  =>str_replace('.','',str_replace('-',0,$rows[0][$i][10])),
            'nop'              =>$rows[0][$i][11],
            'total_njop'       =>str_replace('-',0,$rows[0][$i][12]),
            'tgl_bphtb'        =>$rows[0][$i][13],
            'harga_bphtb'      =>str_replace('-',0,str_replace('NIHIL',0,$rows[0][$i][14])),
            'tgl_pph'          =>$rows[0][$i][15],
            'harga_pph'        =>str_replace('-',0,str_replace('NIHIL',0,$rows[0][$i][16])),
            'keterangan'       =>$rows[0][$i][17],
        );


        }
      } */
        /*  $response = array(
        'status'  =>true,
        'message' =>"Berhasil Menambahkan data excel",
        'data'    =>$hasil,
    );

    return response($response,200);*/
    }

    public function SimpanJadwalNotaris(Request $request)
    {
        // BukuNotaris::where('id_buku_notaris', $request->post('id_buku_notaris'))->update(array('tgl_signing' => $request->post('tgl_signing')));

        $response = [
            'status' => false,
            'message' => 'Feature Jadwal Notaris Belum Tersedia',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function getBukuSuratNotaris(Request $request)
    {
        $tgl = explode('-', $request->post('date'));
        $query = DB::table('buku_surat_notaris')
            ->whereYear('buku_surat_notaris.created_at', $tgl[0])
            ->whereMonth('buku_surat_notaris.created_at', $tgl[1])
            ->Leftjoin('users', 'buku_surat_notaris.pengirim', '=', 'users.id_user')
            ->Leftjoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_notaris.id_client')
            ->select('buku_surat_notaris.id_surat_notaris',
                'data_clients.nama_client',
                'buku_surat_notaris.no_surat',
                'buku_surat_notaris.keterangan',
                'buku_surat_notaris.file',
                'buku_surat_notaris.created_at',
                'users.id_user',
                'users.nama_lengkap')
            ->orderBy('buku_surat_notaris.id_surat_notaris', 'DESC')
            ->get();

        $data = [];
        foreach ($query as $r) {
            $data[] = [
                'id_surat_notaris' => $r->id_surat_notaris,
                'nama_client' => $r->nama_client,
                'no_surat' => $this->ProsesNomorSuratNotaris($r->no_surat, $r->created_at, $r->keterangan),
                'keterangan' => $r->keterangan,
                'file' => $r->file,
                'created_at' => $r->created_at,
                'id_user' => $r->id_user,
                'pengirim' => $r->id_user,
                'nama_lengkap' => $r->nama_lengkap,
            ];
        }

        $response = [
            'status' => true,
            'message' => 'Berhasil menampilkan data',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function getBukuSuratPPAT(Request $request)
    {
        $tgl = explode('-', $request->post('date'));
        $query = DB::table('buku_surat_ppats')
            ->whereYear('buku_surat_ppats.created_at', $tgl[0])
            ->whereMonth('buku_surat_ppats.created_at', $tgl[1])
            ->Leftjoin('users', 'buku_surat_ppats.pengirim', '=', 'users.id_user')
            ->Leftjoin('data_clients', 'data_clients.id_client', '=', 'buku_surat_ppats.id_client')
            ->select('buku_surat_ppats.id_surat_ppat',
                'data_clients.nama_client',
                'buku_surat_ppats.no_surat',
                'buku_surat_ppats.keterangan',
                'buku_surat_ppats.file',
                'buku_surat_ppats.created_at',
                'users.id_user',
                'users.nama_lengkap')
            ->orderByDesc('buku_surat_ppats.id_surat_ppat')
            ->get();

        $data = [];
        foreach ($query as $r) {
            $data[] = [
                'id_surat_ppat' => $r->id_surat_ppat,
                'nama_client' => $r->nama_client,
                'no_surat' => $this->ProsesNomorSuratPPAT($r->no_surat, $r->created_at, $r->keterangan),
                'keterangan' => $r->keterangan,
                'file' => $r->file,
                'created_at' => $r->created_at,
                'id_user' => $r->id_user,
                'pengirim' => $r->id_user,
                'nama_lengkap' => $r->nama_lengkap,
            ];
        }

        $response = [
            'status' => true,
            'message' => 'Berhasil menampilkan data',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function SimpanNomorSuratNotaris(Request $request)
    {
        $request->validate([
            'id_client' => 'required',
            'pengirim' => 'required',
        ]);

        if (!$request->post('id_surat_notaris')) {
            $buku = DB::table('buku_surat_notaris')
            ->orderBy('id_surat_notaris', 'desc')
            ->limit(1)
            ->first();

            if (isset($buku->id_surat_notaris)) {
                $urutan = (int) substr($buku->id_surat_notaris, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_surat_notaris = 'BSN'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $data_buku = [
              'id_surat_notaris' => $id_surat_notaris,
              'id_client' => $request->post('id_client'),
              'keterangan' => $request->post('keterangan'),
              'pengirim' => $request->post('pengirim'),
              'no_surat' => $this->PembuatanNomorSuratNotaris(),
              'created_at' => now(),
              'updated_at' => now(),
            ];

            BukuSuratNotaris::create($data_buku);

            $namaClient = (string) (DB::table('data_clients')
                ->where('id_client', $request->post('id_client'))
                ->value('nama_client') ?? '');
            $judulSurat = trim($namaClient);
            if ($judulSurat === '') {
                $judulSurat = (string) ($request->post('keterangan') ?? '');
            }

            $this->notifyNumberTaken(
                'Buku Surat Notaris',
                (string) $data_buku['no_surat'],
                (string) $id_surat_notaris,
                now()->toDateString(),
                $judulSurat,
                (string) $data_buku['pengirim'],
                [
                    [
                        'id_client' => $request->post('id_client'),
                        'status_kedudukan' => 'Client',
                    ],
                ]
            );

            return $this->successResponse(null, 'Berhasil membuat nomor surat notaris');
        } else {
            $data_buku = [
      'id_client' => $request->post('id_client'),
      'keterangan' => $request->post('keterangan'),
      'pengirim' => $request->post('pengirim'),
      'updated_at' => now(),
      ];

            BukuSuratNotaris::where('id_surat_notaris', $request->post('id_surat_notaris'))->update($data_buku);

            return $this->successResponse(null, 'Berhasil update nomor surat notaris');
        }
    }

    public function SimpanNomorSuratPPAT(Request $request)
    {
        $request->validate([
            'id_client' => 'required',
            'pengirim' => 'required',
        ]);
        if (!$request->post('id_surat_ppat')) {
            $buku = DB::table('buku_surat_ppats')
            ->orderBy('id_surat_ppat', 'desc')
            ->limit(1)
            ->first();

            if (isset($buku->id_surat_ppat)) {
                $urutan = (int) substr($buku->id_surat_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_surat_ppat = 'BS'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $data_buku = [
              'id_surat_ppat' => $id_surat_ppat,
              'id_client' => $request->post('id_client'),
              'keterangan' => $request->post('keterangan'),
              'pengirim' => $request->post('pengirim'),
              'no_surat' => $this->PembuatanNomorSuratPPAT(),
              'created_at' => now(),
              'updated_at' => now(),
            ];

            BukuSuratPPAT::create($data_buku);

            $namaClient = (string) (DB::table('data_clients')
                ->where('id_client', $request->post('id_client'))
                ->value('nama_client') ?? '');
            $judulSurat = trim($namaClient);
            if ($judulSurat === '') {
                $judulSurat = (string) ($request->post('keterangan') ?? '');
            }

            $this->notifyNumberTaken(
                'Buku Surat PPAT',
                (string) $data_buku['no_surat'],
                (string) $id_surat_ppat,
                now()->toDateString(),
                $judulSurat,
                (string) $data_buku['pengirim'],
                [
                    [
                        'id_client' => $request->post('id_client'),
                        'status_kedudukan' => 'Client',
                    ],
                ]
            );

            return $this->successResponse(null, 'Berhasil membuat nomor surat ppat');
        } else {
            $data_buku = [
      'id_client' => $request->post('id_client'),
      'keterangan' => $request->post('keterangan'),
      'pengirim' => $request->post('pengirim'),
      'updated_at' => now(),
      ];

            BukuSuratPPAT::where('id_surat_ppat', $request->post('id_surat_ppat'))->update($data_buku);

            return $this->successResponse(null, 'Berhasil update nomor surat ppat');
        }
    }

    public function SimpanPesanan(Request $request)
    {
        $request->validate([
            'id_user' => ['required'],
            'nama_pesanan' => ['required'],
        ]);

        if (!$request->post('id_order')) {
            $buku = DB::table('orders')
                ->orderBy('id_order', 'desc')
                ->limit(1)
                ->first();

            if (isset($buku->id_order)) {
                $urutan = (int) substr($buku->id_order, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_order = 'ORD'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $data_buku = [
                'id_order' => $id_order,
                'nama_pesanan' => $request->post('nama_pesanan'),
                'id_user' => $request->post('id_user'),
                'keterangan_order' => $request->post('keterangan_order'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            orders::create($data_buku);

            return $this->successResponse(null, 'Berhasil membuat pesanan');
        } else {
            $data_buku = [
                'nama_pesanan' => $request->post('nama_pesanan'),
                'id_user' => $request->post('id_user'),
                'keterangan_order' => $request->post('keterangan_order'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            orders::where('id_order', $request->post('id_order'))->update($data_buku);

            return $this->successResponse(null, 'Berhasil Mengupdate Data Pesanan');
        }
    }

    public function getBukuPesanan(Request $request)
    {
        $tgl = explode('-', $request->post('date'));

        if ($request->post('jenis_invoice')) {
            $query = DB::table('orders')
                ->whereYear('orders.created_at', $tgl[0])
                ->whereMonth('orders.created_at', $tgl[1])
                ->where('status_order', $request->post('status_order'))
                ->where('jenis_invoice', $request->post('jenis_invoice'))
                ->join('users', 'orders.id_user', '=', 'users.id_user')
                ->orderByDesc('orders.id_order')
                ->select('id_order', 'ket_noinv', 'nama_pesanan', 'keterangan_order', 'orders.id_user', 'orders.no_inv', 'orders.jenis_invoice', 'status_order', 'users.nama_lengkap', 'orders.created_at', 'orders.updated_at')
                ->get();
        } else {
            $query = DB::table('orders')
            ->whereYear('orders.created_at', $tgl[0])
            ->whereMonth('orders.created_at', $tgl[1])
            ->where('status_order', $request->post('status_order'))
            ->join('users', 'orders.id_user', '=', 'users.id_user')
            ->orderByDesc('orders.id_order')
            ->select('id_order', 'ket_noinv', 'nama_pesanan', 'keterangan_order', 'orders.id_user', 'orders.no_inv', 'orders.jenis_invoice', 'status_order', 'users.nama_lengkap', 'orders.created_at', 'orders.updated_at')
            ->get();
        }

        $data = [];

        foreach ($query as $d) {
            if ($d->jenis_invoice == 'tax') {
                $total_invoice = invoice_taxs::where('id_order', $d->id_order)->get()->toArray();
            } else {
                $total_invoice = invoice_non_taxs::where('id_order', $d->id_order)->get()->toArray();
            }

            $data[] = [
             'created_at' => $d->created_at,
             'id_order' => $d->id_order,
             'id_user' => $d->id_user,
             'keterangan_order' => $d->keterangan_order,
             'nama_lengkap' => $d->nama_lengkap,
             'nama_pesanan' => $d->nama_pesanan,
             'status_order' => $d->status_order,
             'updated_at' => $d->updated_at,
             'detail_order' => detail_pesanan::where('id_order', $d->id_order)->get()->toArray(),
             'no_inv' => $this->ProsesNomorInvoice($d->no_inv, $d->created_at, $d->ket_noinv),
             'ket_noinv' => $d->ket_noinv,
             'jenis_invoice' => $d->jenis_invoice,
             'total_invoice' => $total_invoice,
            ];
        }

        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Pesanan ',
            'data' => $data,
        ];

        return response($response, 200);
    }

    private function isAdminLevel(?string $levelUser): bool
    {
        $level = strtoupper(trim((string) $levelUser));

        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function buildPenghadapSummary(array $penghadapRows): string
    {
        if (empty($penghadapRows)) {
            return '';
        }

        $clientIds = collect($penghadapRows)
            ->flatMap(function ($row) {
                if (!is_array($row)) {
                    return [];
                }

                return [
                    (string) ($row['id_client'] ?? ''),
                    (string) ($row['id_mewakili'] ?? ''),
                ];
            })
            ->filter(function ($id) {
                return trim((string) $id) !== '';
            })
            ->unique()
            ->values()
            ->all();

        $clientNameMap = [];
        if (!empty($clientIds)) {
            $clientNameMap = DB::table('data_clients')
                ->whereIn('id_client', $clientIds)
                ->pluck('nama_client', 'id_client')
                ->toArray();
        }

        $lines = [];
        $index = 1;

        foreach ($penghadapRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $idClient = trim((string) ($row['id_client'] ?? ''));
            $idMewakili = trim((string) ($row['id_mewakili'] ?? ''));
            $namaClient = trim((string) ($clientNameMap[$idClient] ?? ($row['nama_client'] ?? $idClient)));
            $namaMewakili = trim((string) ($clientNameMap[$idMewakili] ?? ($row['nama_mewakili'] ?? '')));
            $kedudukan = trim((string) ($row['status_kedudukan'] ?? ($row['kedudukan'] ?? '')));

            if ($namaClient === '') {
                continue;
            }

            $line = $index.'. '.$namaClient;

            if ($kedudukan !== '') {
                $line .= ' ('.$kedudukan.')';
            }

            if ($namaMewakili !== '') {
                $line .= ' mewakili '.$namaMewakili;
            }

            $lines[] = $line;
            $index++;
        }

        return implode("\n", $lines);
    }

    private function notifyNumberTaken(
        string $moduleLabel,
        string $nomor,
        string $recordId,
        ?string $documentDate,
        ?string $title,
        ?string $takenByIdUser,
        array $penghadapRows = []
    ): void {
        try {
            /** @var User|null $actor */
            $actor = auth()->user();
            $actorName = trim((string) optional($actor)->nama_lengkap);
            $actorIdUser = trim((string) optional($actor)->id_user);

            $takenBy = null;
            if (!empty($takenByIdUser)) {
                $takenBy = User::query()->where('id_user', (string) $takenByIdUser)->first();
            }
            if (!$takenBy && $actor instanceof User) {
                $takenBy = $actor;
            }

            $takenByName = trim((string) optional($takenBy)->nama_lengkap);
            $takenByUserId = trim((string) optional($takenBy)->id_user);
            $takenByPhone = trim((string) optional($takenBy)->phone);
            $waktu = now()->format('d-m-Y H:i');
            $safeTitle = trim((string) $title);
            $safeDate = trim((string) $documentDate);
            $penghadapSummary = $this->buildPenghadapSummary($penghadapRows);

            /** @var WahaClient $waha */
            $waha = app(WahaClient::class);

            if ($takenByPhone !== '') {
                $userMessage = "*PENGAMBILAN NOMOR BERHASIL*\n\n";
                $userMessage .= "*Modul:* {$moduleLabel}\n";
                $userMessage .= "*Nomor:* {$nomor}\n";
                $userMessage .= "*ID Data:* {$recordId}\n";
                if ($safeDate !== '') {
                    $userMessage .= "*Tanggal Dokumen:* {$safeDate}\n";
                }
                if ($safeTitle !== '') {
                    $userMessage .= "*Judul:* {$safeTitle}\n";
                }
                if ($penghadapSummary !== '') {
                    $userMessage .= "*Penghadap:*\n{$penghadapSummary}\n";
                }
                if ($actorName !== '' && $actorIdUser !== '' && $actorIdUser !== $takenByUserId) {
                    $userMessage .= "*Diinput Oleh:* {$actorName} ({$actorIdUser})\n";
                }
                $userMessage .= "*Waktu:* {$waktu}\n";
                $userMessage .= "\nData nomor berhasil tersimpan dengan status *Proses*.";

                $waha->sendMessage($takenByPhone, $userMessage, [
                    'source' => 'nomor.taken.user',
                    'recipient_user_id' => optional($takenBy)->id,
                    'module' => $moduleLabel,
                    'record_id' => $recordId,
                    'nomor' => $nomor,
                ]);
            }

            $admins = User::query()
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get()
                ->filter(function (User $user) {
                    return $this->isAdminLevel((string) $user->level_user);
                })
                ->values();

            foreach ($admins as $admin) {
                $adminMessage = "*NOTIF PENGAMBILAN NOMOR*\n\n";
                $adminMessage .= "*Modul:* {$moduleLabel}\n";
                $adminMessage .= "*Nomor:* {$nomor}\n";
                $adminMessage .= "*ID Data:* {$recordId}\n";
                if ($safeDate !== '') {
                    $adminMessage .= "*Tanggal Dokumen:* {$safeDate}\n";
                }
                if ($safeTitle !== '') {
                    $adminMessage .= "*Judul:* {$safeTitle}\n";
                }
                if ($penghadapSummary !== '') {
                    $adminMessage .= "*Penghadap:*\n{$penghadapSummary}\n";
                }
                if ($takenByName !== '' || $takenByUserId !== '') {
                    $adminMessage .= "*Pengambil:* {$takenByName} ({$takenByUserId})\n";
                }
                if ($actorName !== '' || $actorIdUser !== '') {
                    $adminMessage .= "*Diinput Oleh:* {$actorName} ({$actorIdUser})\n";
                }
                $adminMessage .= "*Waktu:* {$waktu}";

                $waha->sendMessage((string) $admin->phone, $adminMessage, [
                    'source' => 'nomor.taken.admin',
                    'recipient_user_id' => $admin->id,
                    'module' => $moduleLabel,
                    'record_id' => $recordId,
                    'nomor' => $nomor,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function ProsesNomorInvoice($no_inv, $tgl_inv, $keterangan_surat)
    {
        $dt = explode('-', $tgl_inv);
        $romawibulan = $this->getRomawi($dt[1]);

        $no = str_pad($no_inv, 3, '0', STR_PAD_LEFT);
        if ($keterangan_surat != null) {
            return $no.'/NOT/INV/'.$keterangan_surat.'/'.$romawibulan.'/'.$dt[0];
        } else {
            return $no.'/NOT/INV/'.$romawibulan.'/'.$dt[0];
        }
    }

}
