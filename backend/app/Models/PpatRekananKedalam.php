<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PpatRekananKedalam extends Model
{
    use HasFactory;

    protected $table = 'ppat_rekanan_kedalams';

    protected $fillable = [
        'ppat_rekanan_id',
        'no_akta',
        'tanggal_akta',
        'id_akta',
        'nama_akta_manual',
        'pihak_mengalihkan',
        'pihak_menerima',
        'no_hak_milik',
        'luas_tanah',
        'luas_bangunan',
        'harga_transaksi',
        'nop',
        'harga_njop',
        'tgl_bphtb',
        'harga_bphtb',
        'tgl_pph',
        'harga_pph',
        'keterangan',
        'created_by',
    ];
}
