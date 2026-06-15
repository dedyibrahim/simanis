<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PeminjamanMinuta extends Model
{
    protected $fillable = [
        'no_akta',
        'no_bundle',
        'nama_peminjam',
        'keperluan',
        'tanggal_pinjam',
        'tanggal_kembali',
        'status',
        'keterangan',
        'is_terlambat',
        'jumlah_perpanjangan',
        'tanggal_perpanjangan_terakhir',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_pinjam' => 'date',
        'tanggal_kembali' => 'date',
        'tanggal_perpanjangan_terakhir' => 'date',
        'is_terlambat' => 'boolean',
    ];

    /**
     * Update status terlambat otomatis
     */
    public function refreshStatus()
    {
        if (
            $this->status === 'Dipinjam' &&
            $this->tanggal_kembali < Carbon::today()
        ) {
            $this->status = 'Terlambat';
            $this->is_terlambat = true;
            $this->save();
        }
    }
}
