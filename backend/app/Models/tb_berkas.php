<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_berkas extends Model
{
    use HasFactory;

    protected $table = 'tb_berkas';
    protected $fillable = [
        'id_berkas'  ,
        'id_client',
        'id_dokumen',
        'id_user',
        'nama_berkas',
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
