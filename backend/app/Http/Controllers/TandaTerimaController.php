<?php

namespace App\Http\Controllers;

use App\Models\IsiDiterima;
use App\Models\TandaTerima;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TandaTerimaController extends Controller
{
    public function index(Request $request)
    {
        $tgl = explode('-', $request->get('date'));

        $tandaTerimas = TandaTerima::with('pembuat', 'isiDiterimas')
        ->where('status', $request->get('status'))
        ->whereYear('created_at', $tgl[0])
        ->whereMonth('created_at', $tgl[1])->get();

        if ($tandaTerimas->isEmpty()) {
            return response()->json(['message' => 'Tanda Terima  Tidak Tersedia'], 404);
        }

        $response = [];

        foreach ($tandaTerimas as $tandaTerima) {
            $namaPembuat = $tandaTerima->nama_lengkap;

            // Menambahkan nama pembuat ke dalam respons
            $data = $tandaTerima->toArray();
            //     $data = $tandaTerima->isiDiterima;
            $response[] = $data;
        }

        return response()->json($response, 200);
    }

    public function show($id)
    {
        $tandaTerima = TandaTerima::with('pembuat', 'isiDiterimas')->findOrFail($id);

        return response()->json($tandaTerima);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_pengirim' => 'required',
            'nama_penerima' => 'required',
            'up_penerima' => 'required',
            'isi_diterimas' => 'required|array|min:1',
            'isi_diterimas.*.isi_diterima' => 'required|string',
            // tambahkan validasi untuk kolom lain
        ]);

        // Logika pembuatan nomor tanda terima, misalnya menggunakan timestamp
        $nomorTandaTerima = 'TN'.now()->timestamp;

        $tandaTerima = TandaTerima::create([
            'nomor_tanda_terima' => $nomorTandaTerima,
            'created_at' => Carbon::parse($request->input('tgl_terima'))->format('Y-m-d\TH:i:s.v\Z'),
            'nama_pengirim' => $request->input('nama_pengirim'),
            'nama_penerima' => $request->input('nama_penerima'),
            'status' => $request->input('status'),
            'lokasi' => $request->input('lokasi'),
            'up_penerima' => $request->input('up_penerima'),
            'keterangan_tanda_terima' => $request->input('keterangan_tanda_terima'),
            'pembuat' => auth()->user()->id_user,
            // tambahkan kolom lain sesuai kebutuhan
        ]);
        $isiDiterimaData = $request->input('isi_diterimas', []);

        foreach ($isiDiterimaData as $data) {
            IsiDiterima::create([
                'tanda_terima_id' => $tandaTerima->id,
                'isi_diterima' => $data['isi_diterima'],
            ]);
        }

        return response()->json($tandaTerima, 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nomor_tanda_terima' => 'nullable|string',
            'nama_pengirim' => 'required',
            'nama_penerima' => 'required',
            'isi_diterimas' => 'required|array|min:1',
            'isi_diterimas.*.isi_diterima' => 'required|string',
            // tambahkan validasi untuk kolom lain
        ]);

        $tandaTerima = TandaTerima::findOrFail($id);
        $nomorTandaTerima = $request->filled('nomor_tanda_terima')
            ? $request->input('nomor_tanda_terima')
            : $tandaTerima->nomor_tanda_terima;

        // Update TandaTerima record
        $tandaTerima->update([
            'created_at' => Carbon::parse($request->input('tgl_terima'))->format('Y-m-d\TH:i:s.v\Z'),
            'nomor_tanda_terima' => $nomorTandaTerima,
            'nama_pengirim' => $request->input('nama_pengirim'),
            'nama_penerima' => $request->input('nama_penerima'),
            'status' => $request->input('status'),
            'lokasi' => $request->input('lokasi'),
            'up_penerima' => $request->input('up_penerima'),
            'keterangan_tanda_terima' => $request->input('keterangan_tanda_terima'),
        ]);

        $isiDiterimaData = $request->input('isi_diterimas', []);
        $isiDiterimaIdsToKeep = collect($isiDiterimaData)
            ->pluck('id')
            ->filter(fn ($value) => !is_null($value) && $value !== '')
            ->values()
            ->all();

        $deleteQuery = IsiDiterima::where('tanda_terima_id', $tandaTerima->id);
        if (! empty($isiDiterimaIdsToKeep)) {
            $deleteQuery->whereNotIn('id', $isiDiterimaIdsToKeep);
        }
        $deleteQuery->delete();

        foreach ($isiDiterimaData as $data) {
            $isiDiterimaId = isset($data['id']) ? $data['id'] : null;
            if ($isiDiterimaId) {
                $existing = IsiDiterima::where('id', $isiDiterimaId)
                    ->where('tanda_terima_id', $tandaTerima->id)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'isi_diterima' => $data['isi_diterima'],
                    ]);

                    continue;
                }
            }

            IsiDiterima::create([
                'tanda_terima_id' => $tandaTerima->id,
                'isi_diterima' => $data['isi_diterima'],
            ]);
        }

        return response()->json($isiDiterimaData, 200);
    }

    public function destroy($id)
    {
        $tandaTerima = TandaTerima::findOrFail($id);
        $level = strtoupper(trim((string) optional(request()->user())->level_user));
        $isSuperAdmin = in_array($level, ['SUPER ADMIN', 'SUPERADMIN'], true);

        if (!$isSuperAdmin) {
            if ((string) $tandaTerima->pembuat !== (string) optional(request()->user())->id_user) {
                return response()->json(['message' => 'Anda hanya bisa menghapus tanda terima yang Anda buat sendiri.'], 403);
            }

            $latest = TandaTerima::where('status', $tandaTerima->status)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (!$latest || (int) $latest->id !== (int) $tandaTerima->id) {
                return response()->json(['message' => 'Hanya nomor tanda terima terakhir yang bisa dihapus.'], 403);
            }
        }

        $path = public_path('tandaterima/'.$tandaTerima->file);
        DB::transaction(function () use ($tandaTerima) {
            IsiDiterima::where('tanda_terima_id', $tandaTerima->id)->delete();
            $tandaTerima->delete();
        });

        if ($tandaTerima->file && \App\Services\DocumentStorage::exists($path)) {
            \App\Services\DocumentStorage::delete($path);
        }

        return response()->json(['message' => 'Tanda Terima berhasil dihapus'], 200);
    }
}
