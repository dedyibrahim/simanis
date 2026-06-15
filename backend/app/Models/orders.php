<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class orders extends Model
{
    use HasFactory;
    protected $fillable = [
    'id_order',
    'nama_pesanan',
    'id_user' ,
    'keterangan_order',
    'created_at',
    'updated_at'
    ];
}
