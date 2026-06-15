<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenghadapWarmerking extends Model
{
    use HasFactory;
    protected $fillable = [
        'id_penghadap_warmerking',
        'id_buku_warmerking',
        'id_client',
        'id_mewakili',
         'mewakili',
        'kedudukan',
        'created_at',
        'updated_at'
        ];
}
