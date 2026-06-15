<?php

namespace App\Http\Controllers;

use App\Models\DaftarAktas;
use App\Models\tb_nama_dokumens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LayananController extends ApiController
{
    public function getDataLayanan()
    {
        $data = DaftarAktas::orderBy('id_akta', 'Desc')
         ->get()
        ->toArray();
        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen ',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function getDataDokumens()
    {
        $data = tb_nama_dokumens::orderBy('id_dokumen', 'desc')
        ->get()
        ->toArray();
        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen ',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function getStandarDokumen(Request $request)
    {
        $data = tb_nama_dokumens::where('nama_dokumen', 'LIKE', '%'.$request->post('judul_dokumen').'%')->get()->toArray();
        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen ',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function SimpanLayanan(Request $request)
    {
        if ($request->post('id_akta')) {
            $data = [
                'nama_akta' => $request->post('nama_akta'),
                'pekerjaan_milik' => $request->post('pekerjaan_milik'),
                'apht' => $request->post('apht'),
              ];

            DaftarAktas::where('id_akta', $request->post('id_akta'))->update($data);

            $response = [
                'status' => true,
                'message' => 'Berhasil Memperbaharui layanan ',
                'data' => [],
            ];
        } else {
            $layanan = DB::table('daftar_aktas')
            ->orderBy('id_akta', 'desc')
            ->limit(1)
            ->first();

            if (isset($layanan->id_akta)) {
                $urutan = (int) substr($layanan->id_akta, 3) + 1;
            } else {
                $urutan = 1;
            }
            $id_akta = 'J_'.str_pad($urutan, 4, '0', STR_PAD_LEFT);

            $data = [
              'id_akta' => $id_akta,
              'nama_akta' => $request->post('nama_akta'),
              'pekerjaan_milik' => $request->post('pekerjaan_milik'),
              'apht' => $request->post('apht'),
            ];

            DaftarAktas::insert($data);

            $response = [
                'status' => true,
                'message' => 'Berhasil Menambahkan layanan ',
                'data' => [],
            ];
        }

        return response($response, 200);
    }

    public function DeleteLayanan(Request $request)
    {
        DaftarAktas::where('id_akta', $request->post('id_akta'))->delete();

        $response = [
            'status' => true,
            'message' => 'Berhasil Mendelete layanan ',
            'data' => [],
        ];

        return response($response, 200);
    }
}
