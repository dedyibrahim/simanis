<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentDownloadRequest extends Model
{
    protected $fillable = [
        'module_path',
        'row_id',
        'file_name',
        'file_category',
        'requested_by_id_user',
        'requested_by_name',
        'status',
        'approved_by_id_user',
        'approved_by_name',
        'approved_at',
        'note',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];
}
