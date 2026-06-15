<?php

namespace App\Http\Controllers;

use App\Services\BantekService;
use Illuminate\Http\Request;

class BantekController extends ApiController
{
    private $bantekService;

    public function __construct(BantekService $bantekService)
    {
        $this->bantekService = $bantekService;
    }
    /**
     * GET /api/bantek
     * Mengambil daftar bantek beserta client di dalamnya
     */
    public function index()
    {
        $data = $this->bantekService->index();

        return response()->json([
            'status' => true,
            'message' => 'Berhasil memuat data bantek',
            'data' => $data
        ], 200);
    }

    /**
     * POST /api/bantek/create
     * Membuat Nomor Bantek Baru (Auto Generate)
     */
    public function store(Request $request)
    {
        $request->validate([
            'lokasi_bantek' => 'required|string',
        ]);

        $result = $this->bantekService->create(
            $request->lokasi_bantek,
            $request->has('id_client') && !empty($request->id_client) ? $request->id_client : null
        );

        return response()->json([
            'status' => true,
            'message' => $result['message'],
            'data' => $result['data']
        ], 200);
    }

    /**
     * POST /api/bantek/add-clients
     * Menambahkan Client ke Bantek yang sudah ada
     */
    public function addClients(Request $request)
    {
        $request->validate([
            'no_bantek' => 'required|exists:penyimpanan_bantek,no_bantek',
            'lokasi_bantek' => 'required',
            'id_clients' => 'required|array',
            'id_clients.*' => 'exists:data_clients,id_client'
        ]);

        try {
            $insertedCount = $this->bantekService->addClients(
                $request->no_bantek,
                $request->lokasi_bantek,
                $request->id_clients
            );

            return response()->json([
                'status' => true,
                'message' => 'Berhasil menambahkan ' . $insertedCount . ' client ke ' . $request->no_bantek,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/bantek/remove-client
     * (Opsional) Menghapus Client dari Bantek
     */
    public function removeClient(Request $request)
    {
        $request->validate([
            'no_bantek' => 'required|string',
            'id_client' => 'required|string'
        ]);

        $removed = $this->bantekService->removeClient($request->no_bantek, $request->id_client);

        if (!$removed) {
            return response()->json([
                'status' => false,
                'message' => 'Data tidak ditemukan'
            ], 404);
        }






        // Kalau lebih dari 1 → hapus row saja


        // Kalau tinggal 1 → kosongkan client saja

        return response()->json([
            'status' => true,
            'message' => 'Client berhasil dihapus dari bantek'
        ]);
    }


}
