<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataClient extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_client';
    public $incrementing = false;
    protected $keyType = 'string';

   /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'id_client',
        'nama_client',
        'no_identitas',
        'has_npwp',
        'jenis_client',
        'alamat_client',
        'nama_folder',
        'pembuat_client',
        'email',
        'contact_number',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'has_npwp' => 'boolean',
    ];
}
