<?php

namespace App\Imports;

use App\Models\BukuNotaris;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToModel;
use Illuminate\Support\Facades\DB;
class PekerjaanNotaris implements ToModel
{
     public function model(array $row){

      return $row;
     /*   $buku = DB::table('buku_notaris')
        ->orderBy('id_buku_notaris', 'desc')
        ->limit(1)
        ->first();

        if(isset($buku->id_buku_notaris)){
        $urutan = (int) substr($buku->id_buku_notaris,6)+1;
        }else{
        $urutan =1;
        }

        $id_buku_notaris    =  "BKN".str_pad($urutan,7 ,"0",STR_PAD_LEFT);


        return new BukuNotaris([

            'id_buku_notaris'     =>$id_buku_notaris,
            'id_akta'             =>NULL,
            'judul_pekerjaan'     =>$row[3],
            'id_user'             =>auth()->user()->id_user,
            'nama_client'         =>$row[4],
            'tgl_akta'            =>date('Y-m-d',strtotime($row[2])),
            'no_akta'             =>$row[1],
            'status_akta'         =>'Lama',
            'created_at'          =>now(),
            'updated_at'          =>now()

            ]); */
        }
}
