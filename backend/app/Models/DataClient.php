<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataClient extends Model
{
    use HasFactory;

   /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'id_client',
        'nama_client',
        'no_identitas',
        'jenis_client',
        'alamat_client',
        'nama_folder',
        'pembuat_client',
        'email',
        'contact_number',
        'created_at',
        'updated_at',
    ];
}
