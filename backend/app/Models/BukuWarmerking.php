<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BukuWarmerking extends Model
{
    use HasFactory;

    protected $table = 'buku_warmerkings';
    protected $fillable = [
        'id_buku_warmerking'  ,
        'judul_surat',
        'keterangan_surat',
        'id_user',
        'tgl_surat',
        'tgl_didaftarkan',
        'status_warmerking',
        'nama_client',
        'no_warmerking',
        'created_at',
        'updated_at',
    ];

}
