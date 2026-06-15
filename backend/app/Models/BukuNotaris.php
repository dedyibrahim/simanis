<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BukuNotaris extends Model
{
    use HasFactory;

    protected $fillable = [
'id_buku_notaris',
'id_akta',
'id_user',
'tgl_akta',
'judul_pekerjaan',
'status_akta',
'nama_client',
'no_akta',
'tgl_signing',
'created_at',
'created_by',
'updated_at',
    ];
}
