<?php

namespace App\Services;

use App\Models\PeminjamanMinuta;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class PeminjamanMinutaService
{
    private function refreshLateStatus(PeminjamanMinuta $item): void
    {
        $today = Carbon::today()->toDateString();
        $dueDate = $item->tanggal_kembali ? Carbon::parse($item->tanggal_kembali)->toDateString() : null;

        if ($item->status === 'Dikembalikan') {
            if ((bool) $item->is_terlambat) {
                $item->is_terlambat = false;
                $item->save();
            }
            return;
        }

        if ($dueDate && $dueDate < $today) {
            if ($item->status !== 'Terlambat' || !(bool) $item->is_terlambat) {
                $item->status = 'Terlambat';
                $item->is_terlambat = true;
                $item->save();
            }
            return;
        }

        if ($item->status !== 'Dipinjam' || (bool) $item->is_terlambat) {
            $item->status = 'Dipinjam';
            $item->is_terlambat = false;
            $item->save();
        }
    }

    public function list($search = null, $month = null)
    {
        $query = PeminjamanMinuta::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_akta', 'like', "%$search%")
                  ->orWhere('no_bundle', 'like', "%$search%")
                  ->orWhere('nama_peminjam', 'like', "%$search%")
                  ->orWhere('keperluan', 'like', "%$search%")
                  ->orWhere('keterangan', 'like', "%$search%");
            });
        }

        if ($month) {
            $parts = explode('-', (string) $month);
            if (count($parts) === 2) {
                $year = (int) $parts[0];
                $monthNumber = (int) $parts[1];
                if ($year > 0 && $monthNumber >= 1 && $monthNumber <= 12) {
                    $query->whereYear('tanggal_pinjam', $year)
                        ->whereMonth('tanggal_pinjam', $monthNumber);
                }
            }
        }

        $data = $query->orderBy('created_at', 'desc')->get();

        foreach ($data as $item) {
            $this->refreshLateStatus($item);
        }

        return $data->fresh();
    }

    public function store(array $payload)
    {
        $authUser = Auth::user();
        $dueDate = Carbon::parse($payload['tanggal_kembali'])->toDateString();
        $isLate = $dueDate < Carbon::today()->toDateString();

        return PeminjamanMinuta::create([
            'no_akta' => $payload['no_akta'],
            'no_bundle' => $payload['no_bundle'] ?? null,

            // 🔥 AMBIL DARI AUTH
            'nama_peminjam' => (string) optional($authUser)->nama_lengkap,

            'keperluan' => $payload['keperluan'] ?? null,
            'tanggal_pinjam' => $payload['tanggal_pinjam'],
            'tanggal_kembali' => $payload['tanggal_kembali'],
            'keterangan' => $payload['keterangan'] ?? null,
            'status' => $isLate ? 'Terlambat' : 'Dipinjam',
            'is_terlambat' => $isLate,
            'jumlah_perpanjangan' => 0,
            'created_by' => Auth::id(),
        ]);
    }

    public function update(PeminjamanMinuta $item, array $payload)
    {
        $item->update([
            'no_akta' => $payload['no_akta'],
            'no_bundle' => $payload['no_bundle'] ?? null,
            'keperluan' => $payload['keperluan'] ?? null,
            'tanggal_pinjam' => $payload['tanggal_pinjam'],
            'tanggal_kembali' => $payload['tanggal_kembali'],
            'keterangan' => $payload['keterangan'] ?? null,
            'status' => $payload['status'] ?? $item->status,
            'updated_by' => Auth::id(),
        ]);

        $this->refreshLateStatus($item);
        return $item;
    }

    public function kembalikan(PeminjamanMinuta $item)
    {
        $item->update([
            'status' => 'Dikembalikan',
            'is_terlambat' => false,
            'updated_by' => Auth::id(),
        ]);

        return $item;
    }

    public function perpanjang(PeminjamanMinuta $item, $tanggalBaru)
    {
        $newDueDate = Carbon::parse((string) $tanggalBaru)->toDateString();
        $isLate = $newDueDate < Carbon::today()->toDateString();

        $item->update([
            'tanggal_kembali' => $newDueDate,
            'status' => $isLate ? 'Terlambat' : 'Dipinjam',
            'is_terlambat' => $isLate,
            'jumlah_perpanjangan' => ((int) $item->jumlah_perpanjangan) + 1,
            'tanggal_perpanjangan_terakhir' => Carbon::today()->toDateString(),
            'updated_by' => Auth::id(),
        ]);

        return $item;
    }

    public function togglePengembalian(PeminjamanMinuta $item)
    {
        if ($item->status === 'Dikembalikan') {
            $dueDate = $item->tanggal_kembali ? Carbon::parse($item->tanggal_kembali)->toDateString() : null;
            $isLate = $dueDate ? $dueDate < Carbon::today()->toDateString() : false;

            $item->update([
                'status' => $isLate ? 'Terlambat' : 'Dipinjam',
                'is_terlambat' => $isLate,
                'updated_by' => Auth::id(),
            ]);

            return $item;
        }

        return $this->kembalikan($item);
    }
}
