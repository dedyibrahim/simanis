<?php

namespace App\Http\Controllers;

use App\Models\JadwalNotaris;
use Illuminate\Http\Request;

class JadwalNotarisController extends ApiController
{
    public function SimpanJadwalNotaris(Request $request)
    {
        $request->validate([
            'name' => ['required'],
            'jenis' => ['required'],
            'start' => ['required'],
            'end' => ['required'],
        ]);
        $data = [
            'name' => $request->input('name'),
            'jenis' => $request->input('jenis'),
            'start' => $request->input('start'),
            'end' => $request->input('end'),
            'created_by' => auth()->user()->id_user,
        ];

        JadwalNotaris::create($data);

        return $this->successResponse(null, 'Berhasil membuat jadwal');
    }

    public function getJadwalNotaris()
    {
        $jenis = 'Month';
        $query = JadwalNotaris::get();

        $data = [];
        foreach ($query as $r) {
            if ($r->jenis == 'Di Luar') {
                $color = 'primary';
            } else {
                $color = 'error';
            }

            $data[] = [
             'name' => $r->name,
             'start' => $r->start,
             'end' => $r->end,
             'color' => $color,
             'timed' => true,
            ];
        }

        $response = [
         'status' => true,
         'message' => 'Berhasil menampilkan Jadwal',
         'data' => $data,
    ];

        return response($response, 200);
    }
}
