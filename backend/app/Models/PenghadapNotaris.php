<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenghadapNotaris extends Model
{
    use HasFactory;

    protected $fillable = [
    'id_penghadap_notaris',
    'id_buku_notaris',
    'id_client',
    'id_mewakili',
    'kedudukan',
    'created_at',
    'updated_at'
    ];
}
