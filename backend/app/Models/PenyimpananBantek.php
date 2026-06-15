<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenyimpananBantek extends Model
{
    use HasFactory;
    protected $table = 'penyimpanan_bantek';
    protected $fillable = [
        'no_bantek',
        'id_client',
        'lokasi_bantek' ,
        'created_at',
        'updated_at'
        ];
}
