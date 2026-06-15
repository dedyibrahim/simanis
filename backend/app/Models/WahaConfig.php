<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WahaConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_url',
        'api_key',
        'send_message_endpoint',
        'check_number_endpoint',
        'status_endpoint',
        'timeout_seconds',
        'enabled',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'timeout_seconds' => 'integer',
        'metadata' => 'array',
    ];
}

