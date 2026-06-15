<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BukuPPATS extends Model
{
    use HasFactory;
    protected $table = 'buku_ppats';
    protected $fillable = [
        'id_buku_ppat'  ,
        'id_akta',
        'id_user',
        'no_akta',
        'tanggal_akta',
        'bentuk_hukum',
        'pihak_mengalihkan',
        'pihak_menerima',
        'no_hak_milik',
        'luas_tanah_bangunan',
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
        'created_at',
        'updated_at',
    ];
}
