<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class Dashboard extends Controller
{
    private $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function getPemenang(Request $request)
    {
        $data = $this->dashboardService->getPemenang($request->start_date, $request->end_date);

        $response = array(
            'status' => true,
            'message' => "Menampilkan data",
            'data'  => $data,
        );

        return response($response, 200);
    }

    public function getTotalPekerjaan(Request $request)
    {
        $data = $this->dashboardService->getTotalPekerjaan($request->start_date, $request->end_date);

        return response()->json([
            'status'  => true,
            'message' => "Menampilkan data",
            'data'    => $data,
        ], 200);
    }

   public function getGrafik(Request $request)
{
    $data = $this->dashboardService->getGrafik($request->start_date, $request->end_date);

    return response()->json([
        'status'  => true,
        'message' => "Menampilkan data",
        'data'    => $data,
    ], 200);
}

}
