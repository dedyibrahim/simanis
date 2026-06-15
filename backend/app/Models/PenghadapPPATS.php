<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenghadapPPATS extends Model
{
    use HasFactory;
    protected $table = 'penghadap_ppats';

    protected $fillable = [
        'id_penghadap_ppat',
        'id_buku_ppat',
        'id_client',
        'status_kedudukan',
        'id_mewakili',
        'created_at',
        'updated_at'
        ];
}
