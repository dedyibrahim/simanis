<?php

namespace App\Http\Controllers;

use App\Models\PeminjamanMinuta;
use App\Services\PeminjamanMinutaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PeminjamanMinutaController extends Controller
{
    protected $service;

    public function __construct(PeminjamanMinutaService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string'],
            'date' => ['nullable', 'regex:/^\d{4}\-\d{2}$/'],
            'status' => ['nullable', Rule::in(['Dipinjam', 'Terlambat', 'Dikembalikan'])],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat data peminjaman minuta.',
            'data' => $this->service->list(
                $validated['search'] ?? null,
                $validated['date'] ?? null,
                $validated['status'] ?? null,
            ),
        ]);
    }

    public function store(Request $request)
    {
        // ❌ nama_peminjam DIHAPUS
        $validated = $request->validate([
            'no_akta' => ['required', 'string'],
            'no_bundle' => ['nullable', 'string'],
            'keperluan' => ['nullable', 'string'],
            'tanggal_pinjam' => ['required', 'date'],
            'tanggal_kembali' => ['required', 'date', 'after_or_equal:tanggal_pinjam'],
            'keterangan' => ['nullable', 'string'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Peminjaman berhasil disimpan.',
            'data' => $this->service->store($validated)
        ]);
    }

    public function update(Request $request, $id)
    {
        $item = PeminjamanMinuta::findOrFail($id);
        $validated = $request->validate([
            'no_akta' => ['required', 'string'],
            'no_bundle' => ['nullable', 'string'],
            'keperluan' => ['nullable', 'string'],
            'tanggal_pinjam' => ['required', 'date'],
            'tanggal_kembali' => ['required', 'date', 'after_or_equal:tanggal_pinjam'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['Dipinjam', 'Terlambat', 'Dikembalikan'])],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Data diperbarui.',
            'data' => $this->service->update($item, $validated)
        ]);
    }

    public function kembalikan($id)
    {
        return response()->json([
            'status' => true,
            'message' => 'Status peminjaman diperbarui.',
            'data' => $this->service->kembalikan(
                PeminjamanMinuta::findOrFail($id)
            )
        ]);
    }

    public function perpanjang(Request $request, $id)
    {
        $validated = $request->validate([
            'tanggal_kembali' => ['required', 'date'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Tanggal pengembalian berhasil diperpanjang.',
            'data' => $this->service->perpanjang(
                PeminjamanMinuta::findOrFail($id),
                $validated['tanggal_kembali']
            )
        ]);
    }

    public function togglePengembalian($id)
    {
        return response()->json([
            'status' => true,
            'message' => 'Status peminjaman berhasil ditoggle.',
            'data' => $this->service->togglePengembalian(
                PeminjamanMinuta::findOrFail($id)
            )
        ]);
    }
}
