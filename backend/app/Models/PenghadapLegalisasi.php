<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenghadapLegalisasi extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_penghadap_legalisasi',
        'id_buku_legalisasi',
        'id_client',
        'kedudukan',
        'id_mewakili',
        'mewakili',
        'created_at',
        'updated_at'
        ];
}
