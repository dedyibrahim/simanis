<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TandaTerima extends Model
{
    use HasFactory;
    protected $table = 'tanda_terima';

    protected $fillable = [
        'nomor_tanda_terima',
        'nama_pengirim',
        'nama_penerima',
        'up_penerima',
        'created_at',
        'pembuat',
        'keterangan_tanda_terima',
        'file',
        'status',
        'lokasi',
    ];

    public function isiDiterimas()
    {
        return $this->hasMany(IsiDiterima::class);
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'pembuat', 'id_user', 'nama_lengkap');
    }
}
