<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScanSession extends Model
{
    protected $fillable = [
        'token',
        'assistant_user_id',
        'status',
        'output_type',
        'note',
        'created_ip',
        'user_agent',
        'expires_at',
        'uploaded_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    public function document(): HasOne
    {
        return $this->hasOne(ScannedDocument::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ScannedDocument::class);
    }
}
