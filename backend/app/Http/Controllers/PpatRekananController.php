<?php

namespace App\Http\Controllers;

use App\Models\PpatRekanan;
use App\Models\PpatRekananKedalam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PpatRekananController extends Controller
{
    private function success($data = null, string $message = 'Berhasil memuat data.')
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function periodParts(Request $request): array
    {
        $date = (string) $request->input('date', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
            $date = date('Y-m');
        }
        [$year, $month] = explode('-', $date);
        return [(int) $year, (int) $month];
    }

    public function master(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = PpatRekanan::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('nama_ppat', 'like', $like)
                        ->orWhere('alamat', 'like', $like)
                        ->orWhere('no_hp', 'like', $like);
                });
            })
            ->orderByDesc('aktif')
            ->orderBy('nama_ppat');

        return $this->success($query->get(), 'Master PPAT rekanan berhasil dimuat.');
    }

    public function storeMaster(Request $request)
    {
        $validated = $request->validate([
            'nama_ppat' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'no_hp' => ['nullable', 'string', 'max:40'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        $row = PpatRekanan::create([
            'nama_ppat' => $validated['nama_ppat'],
            'alamat' => $validated['alamat'] ?? null,
            'no_hp' => $validated['no_hp'] ?? null,
            'aktif' => $request->boolean('aktif', true),
        ]);

        return $this->success($row, 'PPAT rekanan berhasil ditambahkan.');
    }

    public function updateMaster(Request $request, int $id)
    {
        $row = PpatRekanan::findOrFail($id);
        $validated = $request->validate([
            'nama_ppat' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'no_hp' => ['nullable', 'string', 'max:40'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        $row->update([
            'nama_ppat' => $validated['nama_ppat'],
            'alamat' => $validated['alamat'] ?? null,
            'no_hp' => $validated['no_hp'] ?? null,
            'aktif' => $request->boolean('aktif', true),
        ]);

        return $this->success($row, 'PPAT rekanan berhasil diperbarui.');
    }

    public function destroyMaster(int $id)
    {
        $usedByKeluar = DB::table('buku_ppats')->where('ppat_rekanan_keluar_id', $id)->exists();
        $usedByKedalam = DB::table('ppat_rekanan_kedalams')->where('ppat_rekanan_id', $id)->exists();

        if ($usedByKeluar || $usedByKedalam) {
            PpatRekanan::where('id', $id)->update(['aktif' => false]);
            return $this->success(null, 'PPAT rekanan sudah dipakai, jadi dinonaktifkan.');
        }

        PpatRekanan::where('id', $id)->delete();
        return $this->success(null, 'PPAT rekanan berhasil dihapus.');
    }

    public function keluar(Request $request)
    {
        [$year, $month] = $this->periodParts($request);
        $search = trim((string) $request->input('search', ''));

        $query = DB::table('buku_ppats')
            ->leftJoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftJoin('users', 'buku_ppats.id_user', '=', 'users.id_user')
            ->leftJoin('ppat_rekanans', 'buku_ppats.ppat_rekanan_keluar_id', '=', 'ppat_rekanans.id')
            ->whereYear('buku_ppats.tanggal_akta', $year)
            ->whereMonth('buku_ppats.tanggal_akta', $month)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('buku_ppats.no_akta', 'like', $like)
                        ->orWhere('buku_ppats.id_buku_ppat', 'like', $like)
                        ->orWhere('daftar_aktas.nama_akta', 'like', $like)
                        ->orWhere('users.nama_lengkap', 'like', $like)
                        ->orWhere('ppat_rekanans.nama_ppat', 'like', $like)
                        ->orWhere('buku_ppats.keterangan', 'like', $like);
                });
            })
            ->select(
                'buku_ppats.id_buku_ppat',
                'buku_ppats.no_akta',
                'buku_ppats.tanggal_akta',
                'buku_ppats.keterangan',
                'buku_ppats.ppat_rekanan_keluar_id',
                'buku_ppats.rekanan_keluar_catatan',
                'buku_ppats.rekanan_keluar_at',
                'daftar_aktas.nama_akta',
                'users.nama_lengkap as pengambil',
                'ppat_rekanans.nama_ppat as nama_ppat_rekanan'
            )
            ->orderByDesc('buku_ppats.id_buku_ppat')
            ->get();

        return $this->success($query, 'Data rekanan keluar berhasil dimuat.');
    }

    public function markKeluar(Request $request)
    {
        $validated = $request->validate([
            'id_buku_ppat' => ['required', 'exists:buku_ppats,id_buku_ppat'],
            'ppat_rekanan_id' => ['required', 'exists:ppat_rekanans,id'],
            'catatan' => ['nullable', 'string'],
        ]);

        DB::table('buku_ppats')
            ->where('id_buku_ppat', $validated['id_buku_ppat'])
            ->update([
                'ppat_rekanan_keluar_id' => $validated['ppat_rekanan_id'],
                'rekanan_keluar_catatan' => $validated['catatan'] ?? null,
                'rekanan_keluar_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->success(null, 'Nomor PPAT berhasil ditandai dipakai PPAT rekanan.');
    }

    public function unmarkKeluar(Request $request)
    {
        $validated = $request->validate([
            'id_buku_ppat' => ['required', 'exists:buku_ppats,id_buku_ppat'],
        ]);

        DB::table('buku_ppats')
            ->where('id_buku_ppat', $validated['id_buku_ppat'])
            ->update([
                'ppat_rekanan_keluar_id' => null,
                'rekanan_keluar_catatan' => null,
                'rekanan_keluar_at' => null,
                'updated_at' => now(),
            ]);

        return $this->success(null, 'Tanda rekanan keluar berhasil dilepas.');
    }

    public function kedalam(Request $request)
    {
        [$year, $month] = $this->periodParts($request);
        $search = trim((string) $request->input('search', ''));

        $query = DB::table('ppat_rekanan_kedalams')
            ->join('ppat_rekanans', 'ppat_rekanan_kedalams.ppat_rekanan_id', '=', 'ppat_rekanans.id')
            ->leftJoin('daftar_aktas', 'ppat_rekanan_kedalams.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftJoin('users', 'ppat_rekanan_kedalams.created_by', '=', 'users.id_user')
            ->whereYear('ppat_rekanan_kedalams.tanggal_akta', $year)
            ->whereMonth('ppat_rekanan_kedalams.tanggal_akta', $month)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('ppat_rekanan_kedalams.no_akta', 'like', $like)
                        ->orWhere('ppat_rekanan_kedalams.nama_akta_manual', 'like', $like)
                        ->orWhere('daftar_aktas.nama_akta', 'like', $like)
                        ->orWhere('ppat_rekanans.nama_ppat', 'like', $like)
                        ->orWhere('ppat_rekanan_kedalams.pihak_mengalihkan', 'like', $like)
                        ->orWhere('ppat_rekanan_kedalams.pihak_menerima', 'like', $like)
                        ->orWhere('ppat_rekanan_kedalams.keterangan', 'like', $like);
                });
            })
            ->select(
                'ppat_rekanan_kedalams.*',
                'ppat_rekanans.nama_ppat',
                'daftar_aktas.nama_akta',
                'users.nama_lengkap as pembuat'
            )
            ->orderByDesc('ppat_rekanan_kedalams.tanggal_akta')
            ->orderByDesc('ppat_rekanan_kedalams.id')
            ->get();

        return $this->success($query, 'Data rekanan kedalam berhasil dimuat.');
    }

    public function storeKedalam(Request $request)
    {
        $validated = $this->validateKedalam($request);
        $validated['created_by'] = optional(auth()->user())->id_user;

        $row = PpatRekananKedalam::create($validated);
        return $this->success($row, 'Akta PPAT rekanan kedalam berhasil disimpan.');
    }

    public function updateKedalam(Request $request, int $id)
    {
        $row = PpatRekananKedalam::findOrFail($id);
        $row->update($this->validateKedalam($request));

        return $this->success($row, 'Akta PPAT rekanan kedalam berhasil diperbarui.');
    }

    public function destroyKedalam(int $id)
    {
        PpatRekananKedalam::where('id', $id)->delete();
        return $this->success(null, 'Akta PPAT rekanan kedalam berhasil dihapus.');
    }

    private function validateKedalam(Request $request): array
    {
        return $request->validate([
            'ppat_rekanan_id' => ['required', 'exists:ppat_rekanans,id'],
            'no_akta' => ['required', 'string', 'max:80'],
            'tanggal_akta' => ['required', 'date'],
            'id_akta' => ['nullable', Rule::exists('daftar_aktas', 'id_akta')],
            'nama_akta_manual' => ['nullable', 'string', 'max:255'],
            'pihak_mengalihkan' => ['nullable', 'string'],
            'pihak_menerima' => ['nullable', 'string'],
            'no_hak_milik' => ['nullable', 'string', 'max:255'],
            'luas_tanah' => ['nullable', 'numeric'],
            'luas_bangunan' => ['nullable', 'numeric'],
            'harga_transaksi' => ['nullable', 'string'],
            'nop' => ['nullable', 'string', 'max:255'],
            'harga_njop' => ['nullable', 'string'],
            'tgl_bphtb' => ['nullable', 'date'],
            'harga_bphtb' => ['nullable', 'string'],
            'tgl_pph' => ['nullable', 'date'],
            'harga_pph' => ['nullable', 'string'],
            'keterangan' => ['nullable', 'string'],
        ]);
    }
}
