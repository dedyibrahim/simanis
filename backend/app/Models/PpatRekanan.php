<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PpatRekanan extends Model
{
    use HasFactory;

    protected $table = 'ppat_rekanans';

    protected $fillable = [
        'nama_ppat',
        'alamat',
        'no_hp',
        'aktif',
    ];
}
