<?php
namespace App\Imports;

use App\Models\BukuPPATS;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Facades\DB;
class ImportPPAT implements ToModel ,WithStartRow
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function startRow(): int
    {
        return 2;
    }

    public function transformDate($value, $format = 'Y-m-d')
{
    try {
        return \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
    } catch (\ErrorException $e) {
        return \Carbon\Carbon::createFromFormat($format, $value);
    }
}


     public function toArray(array $row){

        return $row;
     }
    public function model(array $row)
    {


        if($row[1]){

            $buku = DB::table('buku_ppats')
            ->orderBy('id_buku_ppat', 'desc')
            ->limit(1)
            ->first();

            if(isset($buku->id_buku_ppat)){
            $urutan = (int) substr($buku->id_buku_ppat,6)+1;
            }else{
            $urutan =1;
            }

            $id_buku_ppat   =  "BKP".str_pad($urutan,7 ,"0",STR_PAD_LEFT);

        if($row[13] == '-' || $row[13] == 'NIHIL' ){
            $tanggal_bphtb = NULL;
        }else{
            $tanggal_bphtb = $this->transformDate($row[13]);
        }

        if($row[15] == '-'  ||  $row[15] == 'NIHIL'){
            $tanggal_pph = NULL;
        }else{
            $tanggal_pph = $this->transformDate($row[15]);
        }

        if($row[2] == '-'  ||  $row[2] == 'NIHIL'){
            $tanggal_akta = NULL;
        }else{
            $tanggal_akta = $this->transformDate($row[2]);
        }

        return new  BukuPPATS([
        'id_buku_ppat'     =>$id_buku_ppat,
        'id_akta'          =>'J_0001',
        'id_user'          =>auth()->user()->id_user,
        'no_akta'          =>preg_replace('/\s+/','',$row[1]),
        'tanggal_akta'     =>$tanggal_akta,
        'bentuk_hukum'     =>$row[3],
        'pihak_mengalihkan'=>$row[4],
        'pihak_menerima'   =>$row[5],
        'no_hak_milik'     =>$row[6],
        'tanah_bangunan'   =>$row[7],
        'luas_tanah'       =>$row[8],
        'bangunan'         =>$row[9],
        'harga_transaksi'  =>str_replace('.','',str_replace('-',0,$row[10])),
        'nop'              =>$row[11],
        'total_njop'       =>str_replace('-',0,$row[12]),
        'tgl_bphtb'        =>$tanggal_bphtb,
        'harga_bphtb'      =>str_replace('-',0,str_replace('NIHIL',0,$row[14])),
        'tgl_pph'          =>$tanggal_pph,
        'harga_pph'        =>str_replace('-',0,str_replace('NIHIL',0,$row[16])),
        'keterangan'       =>$row[17],
    ]);
  }
 }
}
