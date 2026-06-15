<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class detail_pesanan extends Model
{
    use HasFactory;

    protected $fillable = [
    'id_detail_pesanan',
    'id_order',
    'id_order',
    'id_pekerjaan',
    'nama_pekerjaan',
    'jenis_pekerjaan',
    'no_pekerjaan',
    'pembuat',
    'tanggal_pekerjaan',
    'harga',
    'no_inv',
    'jenis_invoice',
    ];
}
