<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends ApiController{
    private $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getDaftarAsisten(){
        $data = $this->dashboardService->getDaftarAsisten();
        return $this->successResponse($data, "Berhasil Mengambil data asisten");

    }

    public function getDataDokumenClient(Request $request){
        $payload = $this->dashboardService->getDataDokumenClient((string) $request->post('id_client'));

             $response = array(
                'status' =>true,
                'data_client' => $payload['data_client'],
                'message' =>"Berhasil Mengambil data client",
                'data'  =>$payload['data'],
            );
            return response($response,200);

    }

    public function getDashboard(){
        $data = $this->dashboardService->getDashboardSummary();

        $response = array(
            'status' =>true,
            'message' =>"Mengambil data informasi",
            'data'  =>$data,
        );
        return response($response,200);
    }
}
