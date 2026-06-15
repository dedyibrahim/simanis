<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_dokumen_ppat extends Model
{
    use HasFactory;

    protected $table = 'tb_dokumen_ppat';
    protected $fillable = [
        'id_dokumen_ppat',
        'id_buku_ppat',
        'id_user',
        'nama_berkas',
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
