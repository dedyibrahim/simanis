<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_dokumen_legalisasis extends Model
{
    use HasFactory;

    protected $table = 'tb_dokumen_legalisasis';
    protected $fillable = [
        'id_dokumen_legalisasi',
        'id_buku_legalisasi',
        'id_user',
        'nama_berkas',
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
