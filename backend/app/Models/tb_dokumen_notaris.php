<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_dokumen_notaris extends Model
{
    use HasFactory;

    protected $table = 'tb_dokumen_notaris';
    protected $fillable = [
        'id_dokumen_notaris',
        'id_buku_notaris',
        'id_user',
        'nama_berkas',
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
