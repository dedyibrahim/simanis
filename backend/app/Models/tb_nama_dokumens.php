<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class tb_nama_dokumens extends Model
{
    use HasFactory;

    protected $table = 'tb_nama_dokumens';
    protected $fillable = [
        'id_dokumen'  ,
        'nama_dokumen',
        'created_at',
        'updated_at',
    ];
}
