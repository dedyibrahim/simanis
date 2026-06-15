<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class buku_lama_notaris extends Model
{
    use HasFactory;

     protected $fillable= [
        'id_sementara',
        'no_akta',
        'tgl_akta',
        'judul_pekerjaan',
        'nama_client',
        'penghadap',
        'jenis_pekerjaan',
        'status_akta',
        'id_user',
        'nama_asisten',
     ];
}
