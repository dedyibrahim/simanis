<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IsiDiterima extends Model
{
    use HasFactory;
    protected $table = 'isi_diterima';

    protected $fillable = [
        'tanda_terima_id',
        'isi_diterima',
    ];

    public function tandaTerima()
    {
        return $this->belongsTo(TandaTerima::class);
    }
}
