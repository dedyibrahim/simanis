<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JadwalNotaris extends Model
{
    use HasFactory;
    protected $fillable = [
        'id',
        'name',
        'start',
        'end',
        'jenis',
        'created_by',
        ];
}
