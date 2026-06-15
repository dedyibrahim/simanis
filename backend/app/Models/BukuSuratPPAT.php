<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BukuSuratPPAT extends Model
{
    use HasFactory;
    protected $table = 'buku_surat_ppats';
    protected $fillable = [
        'id_surat_ppat',
        'id_client'      ,
        'keterangan'     ,
        'file'           ,
        'pengirim'       ,
        'no_surat'       ,
        'created_at',
        'updated_at',
    ];
}
