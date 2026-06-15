<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_dokumen_warmerkings extends Model
{
    use HasFactory;
    protected $fillable = [
        'id_dokumen_warmerking',
        'id_buku_warmerking',
        'id_user',
        'nama_berkas',
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
