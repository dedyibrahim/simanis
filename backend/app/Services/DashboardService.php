<?php

namespace App\Services;

use App\Models\BukuLegalisasi;
use App\Models\BukuNotaris;
use App\Models\BukuPPATS;
use App\Models\BukuSuratNotaris;
use App\Models\BukuSuratPPAT;
use App\Models\BukuWarmerking;
use App\Models\DataClient;
use App\Models\tb_berkas;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getDaftarAsisten(): array
    {
        return User::query()->get()->toArray();
    }

    public function getDataDokumenClient(string $idClient): array
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

    public function getDashboardSummary(): array
    {
        return [
            'notaris' => number_format(BukuNotaris::query()->count()),
            'legalisasi' => number_format(BukuLegalisasi::query()->count()),
            'warmerking' => number_format(BukuWarmerking::query()->count()),
            'ppat' => number_format(BukuPPATS::query()->count()),
            'surat_notaris' => number_format(BukuSuratNotaris::query()->count()),
            'surat_ppat' => number_format(BukuSuratPPAT::query()->count()),
        ];
    }

    public function getPemenang(?string $start = null, ?string $end = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($start, $end);

        $notaris = $this->getTopPerformer('buku_notaris', 'tgl_akta', $startDate, $endDate);
        $ppat = $this->getTopPerformer('buku_ppats', 'tanggal_akta', $startDate, $endDate);
        $legalisasi = $this->getTopPerformer('buku_legalisasis', 'tgl_surat', $startDate, $endDate);
        $warmerking = $this->getTopPerformer('buku_warmerkings', 'tgl_surat', $startDate, $endDate);

        return [
            [
                'judul' => 'Pembuat Akta Notaris',
                'jumlah' => $notaris['total'],
                'nama_lengkap' => $notaris['nama_lengkap'],
                'nama_lengkap_list' => $notaris['nama_lengkap_list'],
            ],
            [
                'judul' => 'Pembuat Akta PPAT',
                'jumlah' => $ppat['total'],
                'nama_lengkap' => $ppat['nama_lengkap'],
                'nama_lengkap_list' => $ppat['nama_lengkap_list'],
            ],
            [
                'judul' => 'Pembuat Legalisasi',
                'jumlah' => $legalisasi['total'],
                'nama_lengkap' => $legalisasi['nama_lengkap'],
                'nama_lengkap_list' => $legalisasi['nama_lengkap_list'],
            ],
            [
                'judul' => 'Pembuat Waarmerking',
                'jumlah' => $warmerking['total'],
                'nama_lengkap' => $warmerking['nama_lengkap'],
                'nama_lengkap_list' => $warmerking['nama_lengkap_list'],
            ],
        ];
    }

    public function getTotalPekerjaan(?string $start = null, ?string $end = null): array
    {
        $notarisQuery = BukuNotaris::query();
        $legalisasiQuery = BukuLegalisasi::query();
        $waarmerkingQuery = BukuWarmerking::query();
        $ppatQuery = BukuPPATS::query();

        if ($start && $end) {
            $notarisQuery->whereBetween('tgl_akta', [$start, $end]);
            $legalisasiQuery->whereBetween('tgl_surat', [$start, $end]);
            $waarmerkingQuery->whereBetween('tgl_surat', [$start, $end]);
            $ppatQuery->whereBetween('tanggal_akta', [$start, $end]);
        }

        return [
            [
                'title' => 'Akta Notaris',
                'total' => $notarisQuery->count(),
            ],
            [
                'title' => 'Legalisasi',
                'total' => $legalisasiQuery->count(),
            ],
            [
                'title' => 'Waarmerking',
                'total' => $waarmerkingQuery->count(),
            ],
            [
                'title' => 'Akta PPAT',
                'total' => $ppatQuery->count(),
            ],
        ];
    }

    public function getGrafik(?string $start = null, ?string $end = null): array
    {
        $users = DB::table('users')
            ->select('nama_lengkap', 'id_user')
            ->whereNotIn('level_user', ['Petugas Luar', 'Arsip'])
            ->get();

       $categories = $users->map(function ($item) {
        $namaArray = explode(' ', $item->nama_lengkap);

        $namaDepan = $namaArray[0] ?? '';
        $namaKedua = $namaArray[1] ?? '';

        return trim($namaDepan . ' ' . $namaKedua);
    })->toArray();

        $bukuNotaris = [];
        $bukuLegalisasi = [];
        $bukuWaarmerking = [];
        $bukuPpat = [];

        foreach ($users as $user) {
            $notarisQuery = BukuNotaris::where('id_user', $user->id_user);
            if ($start && $end) {
                $notarisQuery->whereBetween('tgl_akta', [$start, $end]);
            }
            $bukuNotaris[] = $notarisQuery->count();

            $legalisasiQuery = BukuLegalisasi::where('id_user', $user->id_user);
            if ($start && $end) {
                $legalisasiQuery->whereBetween('tgl_surat', [$start, $end]);
            }
            $bukuLegalisasi[] = $legalisasiQuery->count();

            $waarmerkingQuery = BukuWarmerking::where('id_user', $user->id_user);
            if ($start && $end) {
                $waarmerkingQuery->whereBetween('tgl_surat', [$start, $end]);
            }
            $bukuWaarmerking[] = $waarmerkingQuery->count();

            $ppatQuery = BukuPPATS::where('id_user', $user->id_user);
            if ($start && $end) {
                $ppatQuery->whereBetween('tanggal_akta', [$start, $end]);
            }
            $bukuPpat[] = $ppatQuery->count();
        }

        return [
            'categories' => $categories,
            'notaris' => $bukuNotaris,
            'waarmerking' => $bukuWaarmerking,
            'legalisasi' => $bukuLegalisasi,
            'ppat' => $bukuPpat,
        ];
    }

    private function getTopPerformer(string $table, string $dateColumn, string $startDate, string $endDate): array
    {
        $rows = DB::table($table)
            ->join('users', $table . '.id_user', '=', 'users.id_user')
            ->select(DB::raw('count(' . $table . '.id_user) as total'), 'users.nama_lengkap')
            ->where('users.level_user', '!=', 'Admin')
            ->whereBetween($table . '.' . $dateColumn, [$startDate, $endDate])
            ->groupBy('users.id_user', 'users.nama_lengkap')
            ->orderBy('total', 'DESC')
            ->orderBy('users.nama_lengkap')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'total' => 0,
                'nama_lengkap' => '-',
                'nama_lengkap_list' => [],
            ];
        }

        $topTotal = (int) $rows->first()->total;
        $topNames = $rows
            ->filter(fn ($row) => (int) $row->total === $topTotal)
            ->pluck('nama_lengkap')
            ->values()
            ->all();

        return [
            'total' => $topTotal,
            'nama_lengkap' => implode(', ', $topNames),
            'nama_lengkap_list' => $topNames,
        ];
    }

    private function resolveDateRange(?string $start, ?string $end): array
    {
        if ($start && $end) {
            try {
                $startDate = Carbon::parse($start)->startOfDay();
                $endDate = Carbon::parse($end)->endOfDay();

                if ($endDate->lt($startDate)) {
                    $tmp = $startDate;
                    $startDate = $endDate->copy()->startOfDay();
                    $endDate = $tmp->copy()->endOfDay();
                }

                return [$startDate->toDateString(), $endDate->toDateString()];
            } catch (\Throwable $e) {
                // fallback ke bulan berjalan jika format tanggal tidak valid
            }
        }

        $now = now();
        return [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ];
    }
}
