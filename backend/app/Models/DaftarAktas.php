<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DaftarAktas extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_akta',
        'id_akta'
    ];
}
