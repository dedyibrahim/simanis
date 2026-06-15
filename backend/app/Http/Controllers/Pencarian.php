<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;

class Pencarian extends ApiController
{
    private $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function index(Request $request)
    {
        $result = $this->searchService->searchDataClient($request->post('query'));

        $data = [
            'data_client' => $result,
        ];

        $response = [
            'status' => true,
            'message' => 'Berhasil memuat data',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function getPencarianBukuNotaris(Request $request)
    {
        $data = $this->searchService->getPencarianBukuNotaris((string) $request->id_client);

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getPencarianBukuWarmerking(Request $request)
    {
        $data = $this->searchService->getPencarianBukuWarmerking((string) $request->id_client);

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getPencarianBukuLegalisasi(Request $request)
    {
        $data = $this->searchService->getPencarianBukuLegalisasi((string) $request->id_client);

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getPencarianBukuPPAT(Request $request)
    {
        $data = $this->searchService->getPencarianBukuPPAT((string) $request->id_client);

        return $this->successResponse($data, 'Berhasil menampilkan data permintaan');
    }

    public function getDokumenClient(Request $request)
    {
        $payload = $this->searchService->getDokumenClient((string) $request->post('id_client'));

        return response([
            'status' => true,
            'data_client' => $payload['data_client'],
            'message' => 'Berhasil Mengambil data client',
            'data' => $payload['data'],
        ], 200);
    }
}
