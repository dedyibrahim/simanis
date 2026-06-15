<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use Illuminate\Http\Request;
class Order extends Controller
{
    private $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function SimpanDetailOrder(Request $request){
        $record = (array) $request->post('data_record', []);
        $dataOrder = (array) $request->post('data_order', []);
        $invoice = $request->post('invoice');
        $invoicePayload = is_array($invoice) ? $invoice : null;

        if ($invoicePayload && !$this->isInvoiceAdmin((string) optional($request->user())->level_user)) {
            return response([
                'status' => false,
                'message' => 'Akses ditolak. Pembuatan invoice hanya bisa dilakukan oleh level Admin.',
                'data' => [],
            ], 403);
        }

        $data = $this->orderService->simpanDetailOrder(
            $record,
            $dataOrder,
            $invoicePayload
        );

        $response = array(
            'status' =>true,
            'message' => $invoicePayload
                ? 'Data order dan invoice berhasil disimpan.'
                : 'Detail order berhasil disimpan.',
            'data'  =>$data,
        );
        return response($response,200);
    }

    public function UpdateStatusInvoice(Request $request){
     if (!$this->isInvoiceAdmin((string) optional($request->user())->level_user)) {
        return response([
            'status' => false,
            'message' => 'Akses ditolak. Perubahan status invoice hanya bisa dilakukan oleh level Admin.',
            'data' => [],
        ], 403);
     }

     $data = (array) $request->post();
     $this->orderService->updateStatusInvoice($data);

     $response = array(
        'status' =>true,
        'message' =>"Update Status Invoice Berhasil ",
        'data'  =>[],
    );
    return response($response,200);
    }

    private function isInvoiceAdmin(?string $levelUser): bool
    {
        return strtoupper(trim((string) $levelUser)) === 'ADMIN';
    }
}
