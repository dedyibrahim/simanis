<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BukuLegalisasi extends Model
{
    use HasFactory;

    protected $table = 'buku_legalisasis';
    protected $fillable = [
        'id_buku_legalisasi'  ,
        'judul_surat',
        'keterangan_surat',
        'id_user',
        'tgl_surat',
        'no_legalisasi',
        'nama_client',
        'judul_surat',
        'status_legalisasi',
        'created_at',
        'updated_at',
    ];
}
